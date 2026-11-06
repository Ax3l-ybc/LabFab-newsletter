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

    /**
     * Dates de l'événement, lues dans l'export calendrier du thème du site
     * (/wp-json/labfab/v1/ics?event_id=…) : c'est la seule source fiable de la date réelle.
     *
     * @return string|null Contenu du fichier .ics, ou null si l'événement n'a pas de date.
     * @throws RuntimeException
     */
    static function calendrier($evenement_id) {
        $url = add_query_arg('event_id', (int) $evenement_id, rest_url('labfab/v1/ics'));
        $url = apply_filters('labfab_newsletter_url_ics', $url, $evenement_id);
        $erreur = '';
        // Le site refuse parfois une requête quand on en enchaîne plusieurs : on réessaie.
        for ($essai = 1; $essai <= 3; $essai++) {
            $reponse = wp_remote_get($url, [
                'timeout' => 20,
                'sslverify' => apply_filters('https_local_ssl_verify', false),
            ]);
            if (is_wp_error($reponse)) {
                $erreur = $reponse->get_error_message();
            } else {
                $code = (int) wp_remote_retrieve_response_code($reponse);
                if ($code === 200) {
                    return wp_remote_retrieve_body($reponse);
                }
                if ($code === 404) {
                    return null;
                }
                $erreur = 'réponse HTTP ' . $code;
            }
            if ($essai < 3) {
                sleep(2 * $essai);
            }
        }
        throw new RuntimeException(sprintf(
            "Impossible de lire les dates de l'événement n°%d (%s). Le site doit pouvoir s'appeler lui-même ; "
            . 'voir Outils → Santé du site.', $evenement_id, $erreur));
    }
}
