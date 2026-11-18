<?php
/**
 * Lecture des articles et des événements directement dans WordPress.
 */

defined('ABSPATH') || exit;

class LabFab_NL_Source {

    /** Type de contenu des événements sur labfab.fr. */
    const TYPE_EVENEMENT = 'evenement';

    /**
     * Articles publiés entre $debut et $fin inclus (AAAA-MM-JJ), du plus ancien au plus récent.
     */
    static function articles($debut, $fin) {
        $posts = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'ASC',
            'date_query' => [[
                'after' => $debut . ' 00:00:00',
                'before' => $fin . ' 23:59:59',
                'inclusive' => true,
            ]],
        ]);
        $articles = [];
        foreach ($posts as $post) {
            $articles[] = [
                'titre' => LabFab_NL_Contenu::texte_brut(get_the_title($post)),
                'extrait' => LabFab_NL_Contenu::resumer(LabFab_NL_Contenu::texte_brut(get_the_excerpt($post))),
                'url' => get_permalink($post),
                'image_url' => get_the_post_thumbnail_url($post, 'large') ?: null,
                'categorie' => self::categorie_principale($post),
            ];
        }
        return $articles;
    }

    /** Première catégorie de l'article, sauf la catégorie par défaut (« Non classé »). */
    static function categorie_principale($post) {
        $par_defaut = (int) get_option('default_category');
        foreach (get_the_category($post->ID) as $categorie) {
            if ((int) $categorie->term_id !== $par_defaut && $categorie->name !== 'Non classé') {
                return html_entity_decode($categorie->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        return null;
    }

    /**
     * Tous les événements publiés, avec leur fichier calendrier (.ics) ou null s'ils n'ont pas de date.
     *
     * @throws RuntimeException si le site ne parvient pas à lire ses propres dates d'événements.
     */
    static function evenements() {
        $posts = get_posts([
            'post_type' => self::TYPE_EVENEMENT,
            'post_status' => 'publish',
            'posts_per_page' => -1,
        ]);
        $evenements = [];
        foreach ($posts as $post) {
            $evenements[] = [
                'titre' => LabFab_NL_Contenu::texte_brut(get_the_title($post)),
                'extrait' => LabFab_NL_Contenu::resumer(LabFab_NL_Contenu::texte_brut(get_the_excerpt($post))),
                'url' => get_permalink($post),
                'image_url' => get_the_post_thumbnail_url($post, 'large') ?: null,
                'publie' => get_the_date('Y-m-d', $post),
                'ics' => self::calendrier($post->ID),
            ];
        }
        return $evenements;
    }

    const ROUTE_ICS = '/labfab/v1/ics';
    const OPTION_INTERNE_INTERDIT = 'labfab_newsletter_appel_interne_interdit';

    /** Dernière méthode qui a fonctionné, pour le diagnostic. */
    static $methode = '';

    /** Méthodes déjà en échec pendant cette page : on ne les réessaie pas pour chaque événement. */
    private static $en_echec = [];
    private static $surveillance = false;

    /**
     * Dates de l'événement, lues dans l'export calendrier du thème du site
     * (/wp-json/labfab/v1/ics?event_id=…) : c'est la seule source fiable de la date réelle.
     *
     * Trois façons d'y accéder, dans l'ordre :
     *  1. de l'intérieur de WordPress, sans passer par le réseau (le plus sûr : certains
     *     hébergeurs bloquent les requêtes d'un site vers lui-même, avec une erreur 503) ;
     *  2. par l'adresse publique du site ;
     *  3. par l'adresse locale du serveur (127.0.0.1).
     *
     * @return string|null Contenu du fichier .ics, ou null si l'événement n'a pas de date.
     * @throws RuntimeException
     */
    static function calendrier($evenement_id) {
        $erreurs = [];
        foreach (['interne', 'publique', 'locale'] as $methode) {
            if (isset(self::$en_echec[$methode])) {
                $erreurs[] = $methode . ' : ' . self::$en_echec[$methode];
                continue;
            }
            $resultat = call_user_func([__CLASS__, 'calendrier_' . $methode], (int) $evenement_id);
            if ($resultat['ok']) {
                self::$methode = $methode;
                return $resultat['ics'];
            }
            self::$en_echec[$methode] = $resultat['erreur'];
            $erreurs[] = $methode . ' : ' . $resultat['erreur'];
        }
        throw new RuntimeException(sprintf(
            "Impossible de lire les dates de l'événement n°%d (%s). Utilisez « Tester la lecture des dates » "
            . 'sur la page Newsletter → LabFab automatique pour le diagnostic.', $evenement_id, implode(' ; ', $erreurs)));
    }

    /** 1. Appel direct de l'export calendrier, à l'intérieur de WordPress. */
    static function calendrier_interne($evenement_id) {
        if (get_option(self::OPTION_INTERNE_INTERDIT)) {
            return ['ok' => false, 'erreur' => 'désactivé (lors d\'un essai précédent, l\'export calendrier a arrêté la page)'];
        }
        $routes = rest_get_server()->get_routes();
        if (empty($routes[self::ROUTE_ICS])) {
            return ['ok' => false, 'erreur' => 'export calendrier introuvable (le thème du site est-il actif ?)'];
        }
        $requete = new WP_REST_Request('GET', self::ROUTE_ICS);
        $requete->set_query_params(['event_id' => $evenement_id]);

        // Si l'export calendrier arrête PHP après avoir écrit le fichier (exit), la page s'arrêterait
        // aussi : on le détecte pour ne plus utiliser cette méthode ensuite.
        $GLOBALS['labfab_nl_appel_en_cours'] = true;
        if (!self::$surveillance) {
            register_shutdown_function([__CLASS__, 'verifier_arret']);
            self::$surveillance = true;
        }
        ob_start();
        $reponse = rest_do_request($requete);
        $sortie = ob_get_clean();
        $GLOBALS['labfab_nl_appel_en_cours'] = false;

        $statut = $reponse->get_status();
        if ($statut === 404) {
            return ['ok' => true, 'ics' => null];
        }
        $donnees = $reponse->get_data();
        foreach ([$donnees, $sortie] as $candidat) {
            if (is_string($candidat) && strpos($candidat, 'BEGIN:VCALENDAR') !== false) {
                return ['ok' => true, 'ics' => $candidat];
            }
        }
        if ($reponse->is_error() && is_array($donnees) && ($donnees['code'] ?? '') === 'no_date') {
            return ['ok' => true, 'ics' => null];
        }
        return ['ok' => false, 'erreur' => 'réponse inattendue (statut ' . $statut . ')'];
    }

    /** Oublie les échecs de cette page et réautorise la méthode interne (bouton « Réessayer »). */
    static function reinitialiser() {
        self::$en_echec = [];
        delete_option(self::OPTION_INTERNE_INTERDIT);
    }

    static function verifier_arret() {
        if (!empty($GLOBALS['labfab_nl_appel_en_cours'])) {
            update_option(self::OPTION_INTERNE_INTERDIT, 1, false);
        }
    }

    /** 2. Adresse publique du site. */
    static function calendrier_publique($evenement_id) {
        $url = add_query_arg('event_id', $evenement_id, rest_url(ltrim(self::ROUTE_ICS, '/')));
        return self::telecharger(apply_filters('labfab_newsletter_url_ics', $url, $evenement_id), []);
    }

    /** 3. Adresse locale du serveur, en indiquant le nom du site. */
    static function calendrier_locale($evenement_id) {
        $site = wp_parse_url(home_url());
        $chemin = wp_parse_url(rest_url(ltrim(self::ROUTE_ICS, '/')));
        $url = 'http://127.0.0.1' . ($chemin['path'] ?? '/wp-json' . self::ROUTE_ICS) . '?event_id=' . $evenement_id;
        return self::telecharger($url, ['Host' => $site['host'] ?? '']);
    }

    private static function telecharger($url, array $entetes) {
        $reponse = wp_remote_get($url, [
            'timeout' => 8,
            'redirection' => 0,
            'headers' => $entetes,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        ]);
        if (is_wp_error($reponse)) {
            return ['ok' => false, 'erreur' => $reponse->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($reponse);
        $corps = wp_remote_retrieve_body($reponse);
        if ($code === 200 && strpos($corps, 'BEGIN:VCALENDAR') !== false) {
            return ['ok' => true, 'ics' => $corps];
        }
        if ($code === 404 && strpos($corps, 'no_date') !== false) {
            return ['ok' => true, 'ics' => null];
        }
        return ['ok' => false, 'erreur' => 'réponse HTTP ' . $code];
    }
}
