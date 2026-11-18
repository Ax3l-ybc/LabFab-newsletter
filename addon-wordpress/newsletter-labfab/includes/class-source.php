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
        self::$base_trouvees = 0;
        $base_utilisee = false;
        foreach ($posts as $post) {
            $ics = self::calendrier($post->ID);
            $base_utilisee = $base_utilisee || self::$methode === 'base';
            $evenements[] = [
                'titre' => LabFab_NL_Contenu::texte_brut(get_the_title($post)),
                'extrait' => LabFab_NL_Contenu::resumer(LabFab_NL_Contenu::texte_brut(get_the_excerpt($post))),
                'url' => get_permalink($post),
                'image_url' => get_the_post_thumbnail_url($post, 'large') ?: null,
                'publie' => get_the_date('Y-m-d', $post),
                'ics' => $ics,
            ];
        }
        self::verifier_base($base_utilisee, count($posts));
        return $evenements;
    }

    const ROUTE_ICS = '/labfab/v1/ics';
    const OPTION_INTERNE_INTERDIT = 'labfab_newsletter_appel_interne_interdit';

    /** Dernière méthode qui a fonctionné, pour le diagnostic. */
    static $methode = '';

    /** Méthodes déjà en échec pendant cette page : on ne les réessaie pas pour chaque événement. */
    private static $en_echec = [];
    private static $surveillance = false;

    /** Lecture en base : nombre d'événements datés trouvés, et champs utilisés (pour le diagnostic). */
    static $base_trouvees = 0;
    static $base_champs = [];

    const OPTION_ARRET = 'labfab_newsletter_arret_details';

    /**
     * Dates de l'événement, lues dans l'export calendrier du thème du site
     * (/wp-json/labfab/v1/ics?event_id=…) : c'est la seule source fiable de la date réelle.
     *
     * Trois façons d'y accéder, dans l'ordre :
     *  1. de l'intérieur de WordPress, sans passer par le réseau (le plus sûr : certains
     *     hébergeurs bloquent les requêtes d'un site vers lui-même, avec une erreur 503) ;
     *  2. par l'adresse publique du site ;
     *  3. par l'adresse locale du serveur (127.0.0.1) ;
     *  4. en dernier recours, en cherchant les dates directement dans les champs de l'événement
     *     enregistrés dans la base de données (voir calendrier_base()).
     *
     * @return string|null Contenu du fichier .ics, ou null si l'événement n'a pas de date.
     * @throws RuntimeException
     */
    static function calendrier($evenement_id) {
        $erreurs = [];
        foreach (['interne', 'publique', 'locale', 'base'] as $methode) {
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
        self::$base_trouvees = 0;
        self::$base_champs = [];
        delete_option(self::OPTION_INTERNE_INTERDIT);
    }

    static function verifier_arret() {
        if (empty($GLOBALS['labfab_nl_appel_en_cours'])) {
            return;
        }
        update_option(self::OPTION_INTERNE_INTERDIT, 1, false);
        $erreur = error_get_last();
        $sortie = ob_get_level() ? (string) ob_get_contents() : '';
        update_option(self::OPTION_ARRET, [
            'date' => current_time('mysql'),
            'erreur' => $erreur ? $erreur['message'] . ' (' . $erreur['file'] . ':' . $erreur['line'] . ')' : '',
            'sortie' => mb_substr($sortie, 0, 300),
            'entetes' => headers_list(),
        ], false);
        // On remplace ce que l'export a écrit (le fichier calendrier) par un message lisible.
        while (ob_get_level()) {
            ob_end_clean();
        }
        if (!headers_sent() && !(defined('DOING_CRON') && DOING_CRON)) {
            header_remove('Content-Disposition');
            header('Content-Type: text/html; charset=utf-8');
            echo '<p style="font:16px sans-serif">L\'export calendrier du thème a arrêté la page. L\'addon ne l\'utilisera plus et '
                . 'lira les dates autrement. <a href="' . esc_url(admin_url('admin.php?page=newsletter_labfab_index')) . '">Revenir à la page de l\'addon</a></p>';
        }
    }

    /**
     * 4. Dates cherchées directement dans les champs de l'événement (base de données).
     *
     * Le thème range les dates dans des champs personnalisés (ACF) dont le nom n'est pas connu :
     * on repère les valeurs qui ressemblent à des dates et à des heures, et on distingue début et
     * fin d'après le nom des champs (« debut », « start », « fin », « end »…). Le résultat est
     * remis sous forme de fichier calendrier, comme celui de l'export du thème.
     */
    static function calendrier_base($evenement_id) {
        $lu = self::dates_en_base($evenement_id);
        if (!$lu) {
            return ['ok' => true, 'ics' => null];
        }
        self::$base_trouvees++;
        self::$base_champs = array_unique(array_merge(self::$base_champs, $lu['champs']));
        return ['ok' => true, 'ics' => self::ics($lu)];
    }

    /** Si la lecture en base n'a trouvé aucune date, c'est qu'elle ne sait pas lire ce site : erreur. */
    static function verifier_base($base_utilisee, $nb_evenements) {
        if ($base_utilisee && $nb_evenements && !self::$base_trouvees) {
            throw new RuntimeException("Impossible de lire les dates des événements : l'export calendrier n'est pas "
                . "accessible et aucune date n'a été reconnue dans les champs des événements. Utilisez « Tester la "
                . 'lecture des dates » sur la page Newsletter → LabFab automatique pour le diagnostic.');
        }
    }

    /** Le nom du champ désigne-t-il une fin ? On regarde ses mots : date_fin, dateFin, end_date, heure-de-fin… */
    static function est_fin($cle) {
        $mots = preg_split('/[_\-\s]+|(?<=[a-z])(?=[A-Z])/', $cle);
        return (bool) array_intersect(array_map('strtolower', $mots), ['fin', 'end', 'au', 'to', 'until', 'jusqu', 'jusquau']);
    }

    /** @return array{debut:string, fin:string, journee:bool, lieu:?string, champs:string[]}|null */
    static function dates_en_base($evenement_id) {
        $dates = [];   // champ => ['d' => AAAA-MM-JJ, 'h' => HH:MM|null]
        $heures = [];  // champ => HH:MM
        $lieux = [];   // champ => valeur brute
        foreach ((array) get_post_meta($evenement_id) as $cle => $valeurs) {
            if ($cle === '' || $cle[0] === '_') {
                continue;
            }
            $valeur = maybe_unserialize($valeurs[0] ?? '');
            if (preg_match('/(lieu|location|adresse|antenne|place)/i', $cle)) {
                $lieux[$cle] = $valeur;
                continue;
            }
            if (!is_scalar($valeur)) {
                continue;
            }
            $v = trim((string) $valeur);
            if ($v === '') {
                continue;
            }
            if ($d = self::reconnaitre_date($v)) {
                $dates[$cle] = $d;
            } elseif (preg_match('/^(\d{1,2})[:h](\d{2})(?::\d{2})?$/i', $v, $m) && (int) $m[1] < 24) {
                $heures[$cle] = sprintf('%02d:%s', $m[1], $m[2]);
            }
        }
        if (!$dates) {
            return null;
        }
        $choisir = function (array $champs, $fin) {
            foreach (array_keys($champs) as $cle) {
                if (self::est_fin($cle) === $fin) {
                    return $cle;
                }
            }
            return null;
        };
        $cle_debut = $choisir($dates, false) ?? array_key_first($dates);
        $cle_fin = $choisir($dates, true) ?? $cle_debut;
        $h_debut = $dates[$cle_debut]['h'] ?? null;
        $cle_h_debut = $choisir($heures, false);
        if ($h_debut === null && $cle_h_debut) {
            $h_debut = $heures[$cle_h_debut];
        }
        $h_fin = $dates[$cle_fin]['h'] ?? null;
        $cle_h_fin = $choisir($heures, true);
        if ($h_fin === null && $cle_h_fin) {
            $h_fin = $heures[$cle_h_fin];
        }
        if ($dates[$cle_fin]['d'] < $dates[$cle_debut]['d']) {
            $cle_fin = $cle_debut;
        }
        $champs = array_values(array_unique(array_filter([$cle_debut, $cle_fin,
            $h_debut !== null ? $cle_h_debut : null, $h_fin !== null ? $cle_h_fin : null])));

        $lieu = null;
        foreach ($lieux as $cle => $valeur) {
            $lieu = self::lieu_lisible($valeur);
            if ($lieu) {
                $champs[] = $cle;
                break;
            }
        }
        return [
            'debut' => $dates[$cle_debut]['d'] . ($h_debut ? ' ' . $h_debut : ''),
            'fin' => $dates[$cle_fin]['d'] . ($h_debut ? ' ' . ($h_fin ?? $h_debut) : ''),
            'journee' => $h_debut === null,
            'lieu' => $lieu,
            'champs' => $champs,
        ];
    }

    /** Reconnaît AAAAMMJJ (ACF), AAAA-MM-JJ[ HH:MM[:SS]], JJ/MM/AAAA[ HH:MM] ou un horodatage Unix. */
    static function reconnaitre_date($v) {
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return ['d' => "$m[1]-$m[2]-$m[3]", 'h' => null];
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::\d{2})?)?$/', $v, $m)
                && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return ['d' => "$m[1]-$m[2]-$m[3]", 'h' => isset($m[4]) && "$m[4]:$m[5]" !== '00:00' ? "$m[4]:$m[5]" : null];
        }
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})(?:\s+(\d{1,2})[:h](\d{2}))?$#i', $v, $m)
                && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return ['d' => "$m[3]-$m[2]-$m[1]", 'h' => isset($m[4]) ? sprintf('%02d:%s', $m[4], $m[5]) : null];
        }
        if (preg_match('/^\d{10}$/', $v) && (int) $v > 1420070400 && (int) $v < 2208988800) { // 2015 à 2040
            $d = (new DateTimeImmutable('@' . $v))->setTimezone(wp_timezone());
            return ['d' => $d->format('Y-m-d'), 'h' => $d->format('H:i') !== '00:00' ? $d->format('H:i') : null];
        }
        return null;
    }

    /** Lieu : texte, ou identifiant(s) de page(s) liée(s) (ex. les antennes) dont on prend le titre. */
    private static function lieu_lisible($valeur) {
        $ids = is_array($valeur) ? $valeur : (is_numeric($valeur) ? [$valeur] : null);
        if ($ids === null) {
            $texte = is_scalar($valeur) ? trim((string) $valeur) : '';
            return $texte !== '' ? $texte : null;
        }
        $titres = [];
        foreach ($ids as $id) {
            if (is_numeric($id) && ($titre = get_the_title((int) $id)) !== '') {
                $titres[] = LabFab_NL_Contenu::texte_brut($titre);
            }
        }
        return $titres ? implode(', ', $titres) : null;
    }

    /** Remet les dates trouvées sous forme de fichier calendrier (.ics). */
    private static function ics(array $lu) {
        $echapper = function ($t) {
            return str_replace(['\\', ',', ';', "\n"], ['\\\\', '\\,', '\;', '\\n'], $t);
        };
        if ($lu['journee']) {
            $debut = 'DTSTART;VALUE=DATE:' . str_replace('-', '', $lu['debut']);
            $lendemain = (new DateTimeImmutable($lu['fin']))->modify('+1 day')->format('Ymd');
            $fin = 'DTEND;VALUE=DATE:' . $lendemain;
        } else {
            $format = function ($d) {
                return (new DateTimeImmutable($d))->format('Ymd\THis');
            };
            $fuseau = wp_timezone()->getName();
            $debut = 'DTSTART;TZID=' . $fuseau . ':' . $format($lu['debut']);
            $fin = 'DTEND;TZID=' . $fuseau . ':' . $format($lu['fin']);
        }
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\n$debut\r\n$fin\r\n"
            . ($lu['lieu'] ? 'LOCATION:' . $echapper($lu['lieu']) . "\r\n" : '')
            . "END:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    /**
     * Pour le diagnostic : emplacement du code de l'export calendrier du thème, et champs enregistrés
     * pour un événement (sans les champs techniques commençant par « _ »).
     */
    static function details($evenement_id) {
        $export = 'introuvable';
        $routes = rest_get_server()->get_routes();
        $rappel = $routes[self::ROUTE_ICS][0]['callback'] ?? null;
        try {
            if ($rappel instanceof Closure || (is_string($rappel) && function_exists($rappel))) {
                $r = new ReflectionFunction($rappel);
            } elseif (is_array($rappel)) {
                $r = new ReflectionMethod($rappel[0], $rappel[1]);
            } elseif (is_string($rappel) && strpos($rappel, '::') !== false) {
                $r = new ReflectionMethod($rappel);
            }
            if (isset($r) && $r->getFileName()) {
                $export = str_replace(ABSPATH, '', $r->getFileName()) . ', lignes ' . $r->getStartLine() . ' à ' . $r->getEndLine();
            }
        } catch (ReflectionException $e) {
            $export = 'emplacement inconnu';
        }
        $champs = [];
        foreach ((array) get_post_meta($evenement_id) as $cle => $valeurs) {
            if ($cle !== '' && $cle[0] !== '_') {
                $v = maybe_unserialize($valeurs[0] ?? '');
                $champs[$cle] = mb_substr(is_scalar($v) ? (string) $v : wp_json_encode($v), 0, 120);
            }
        }
        return ['export' => $export, 'champs' => $champs, 'arret' => get_option(self::OPTION_ARRET, [])];
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
