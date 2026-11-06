<?php
/**
 * Addon « LabFab automatique » pour The Newsletter Plugin.
 *
 * Chaque jour, une tâche programmée vérifie si c'est le moment de préparer la lettre du mois
 * suivant. Si oui, l'addon choisit le contenu, remplit le modèle, crée la lettre dans
 * The Newsletter Plugin et la programme : c'est le moteur du plugin qui l'envoie ensuite
 * aux abonné·es confirmé·es, avec ses liens de désinscription et ses statistiques.
 */

defined('ABSPATH') || exit;

class LabFab_Newsletter_Addon extends NewsletterAddon {

    const TACHE = 'labfab_newsletter_quotidien';
    const OPTION_REGLAGES = 'labfab_newsletter_reglages';
    const OPTION_HISTORIQUE = 'labfab_newsletter_historique';
    const PAGE = 'newsletter_labfab_index';

    /** @var LabFab_Newsletter_Addon */
    static $instance;

    // Pas d'appel hebdomadaire aux serveurs de l'éditeur : cet addon n'est pas un addon officiel.
    var $weekly_check = false;

    function __construct($dir) {
        self::$instance = $this;
        parent::__construct('labfab', LABFAB_NL_VERSION, $dir);
        add_action(self::TACHE, [$this, 'tache_quotidienne']);
    }

    function init() {
        parent::init();
        if (!wp_next_scheduled(self::TACHE)) {
            // Tous les jours vers 6 h (heure du site).
            $demain_6h = current_datetime()->setTime(6, 0)->modify('+1 day');
            wp_schedule_event($demain_6h->getTimestamp(), 'daily', self::TACHE);
        }
    }

    function deactivate() {
        parent::deactivate();
        wp_clear_scheduled_hook(self::TACHE);
    }

    // ------------------------------------------------------------------ Réglages

    static function reglages_par_defaut() {
        return [
            'actif' => false,
            'jour_preparation' => 25,
            'jour_envoi' => 1,
            'heure_envoi' => 9,
            'email_test' => get_option('admin_email'),
            'sujet' => 'La lettre du LabFab – {mois} {annee}',
            'logo_url' => 'https://raw.githubusercontent.com/Ax3l-ybc/LabFab-newsletter/main/assets/logo-labfab.png',
            'edito' => '',
        ];
    }

    function reglages() {
        return array_merge(self::reglages_par_defaut(), (array) get_option(self::OPTION_REGLAGES, []));
    }

    /** Toutes les actions de l'addon, de la plus récente à la plus ancienne. */
    function historique() {
        return array_values((array) get_option(self::OPTION_HISTORIQUE, []));
    }

    private function noter($mois, array $entree) {
        $entree = ['mois' => $mois] + $entree + ['date' => current_datetime()->format('Y-m-d H:i')];
        $historique = $this->historique();
        array_unshift($historique, $entree);
        update_option(self::OPTION_HISTORIQUE, array_slice($historique, 0, 40), false);
        return $entree;
    }

    /**
     * La lettre de ce mois a-t-elle déjà été traitée ? Oui si elle a été programmée (même à la main,
     * même si elle a été annulée depuis), ou si la préparation automatique a déjà eu lieu.
     * Une erreur, elle, est réessayée le lendemain ; un brouillon fait à la main ne compte pas.
     */
    function deja_traitee($mois) {
        foreach ($this->historique() as $h) {
            if ($h['mois'] === $mois && ($h['resultat'] === 'programmee'
                    || (($h['declencheur'] ?? '') === 'automatique' && $h['resultat'] !== 'erreur'))) {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------------ Contenu

    /** Contenu complet de la lettre du mois $mois (AAAA-MM). */
    function contenu($mois) {
        $r = $this->reglages();
        $p = LabFab_NL_Contenu::periode($mois);
        $c = LabFab_NL_Contenu::construire(
            LabFab_NL_Source::articles($p['debut_passe'], $p['fin_passe']),
            LabFab_NL_Source::evenements(),
            $mois,
            current_datetime()->format('Y-m-d'),
            wp_timezone()
        );
        $c['logo_url'] = $r['logo_url'];
        $c['edito'] = trim($r['edito']) !== '' ? LabFab_NL_Contenu::edito_en_html($r['edito']) : '';
        $c['sujet'] = strtr($r['sujet'], ['{mois}' => $c['mois'], '{annee}' => $c['annee']]);
        return $c;
    }

    function rendre(array $c) {
        ob_start();
        include LABFAB_NL_DIR . '/templates/lettre.php';
        return ob_get_clean();
    }

    /** Date d'envoi prévue pour la lettre du mois $mois. */
    function date_envoi($mois) {
        $r = $this->reglages();
        return new DateTimeImmutable(
            sprintf('%s-%02d %02d:00:00', $mois, (int) $r['jour_envoi'], (int) $r['heure_envoi']), wp_timezone());
    }

    /** Ex. « 1er décembre à 9h », ou « mardi 1er décembre 2026 à 9h » avec $complete. */
    static function date_lisible($horodatage, $complete = false) {
        $jour = (int) wp_date('j', $horodatage);
        $texte = ($jour === 1 ? '1er' : $jour) . ' ' . wp_date('F', $horodatage);
        if ($complete) {
            $texte = wp_date('l', $horodatage) . ' ' . $texte . ' ' . wp_date('Y', $horodatage);
        }
        return $texte . ' à ' . wp_date('G', $horodatage) . 'h';
    }

    /** Résumé lisible du contenu, pour l'historique et les mails de notification. */
    static function resume(array $c) {
        $lignes = [];
        $lignes[] = ($c['source_passe'] === 'articles' ? 'Articles publiés' : 'Aucun article : événements passés')
            . ' en ' . $c['mois_precedent'] . ' : ' . count($c['articles']);
        foreach ($c['articles'] as $a) {
            $lignes[] = '  - ' . $a['titre'];
        }
        $lignes[] = 'Événements en ' . $c['mois_suivant'] . ' : ' . count($c['evenements']);
        foreach ($c['evenements'] as $e) {
            $lignes[] = '  - ' . $e['date_affichee'] . ' : ' . $e['titre'];
        }
        return implode("\n", $lignes);
    }

    // ------------------------------------------------------------------ Préparation

    /** Tâche quotidienne : prépare la lettre du mois prochain le jour prévu (ou dès que possible après). */
    function tache_quotidienne() {
        $r = $this->reglages();
        if (!$r['actif']) {
            return;
        }
        $maintenant = current_datetime();
        if ((int) $maintenant->format('j') < (int) $r['jour_preparation']) {
            return;
        }
        $mois = $maintenant->modify('first day of next month')->format('Y-m');
        if ($this->deja_traitee($mois)) {
            return;
        }
        $this->preparer($mois, 'programmer', 'automatique');
    }

    /**
     * Lettre de ce mois en attente d'envoi (ou en cours d'envoi) dans The Newsletter Plugin.
     * On interroge le plugin lui-même : c'est la seule garantie contre un double envoi.
     */
    function lettre_programmee($mois) {
        global $wpdb;
        $lignes = $wpdb->get_results($wpdb->prepare(
            'select id, options from ' . NEWSLETTER_EMAILS_TABLE . ' where status=%s', TNP_Email::STATUS_SENDING));
        foreach ((array) $lignes as $ligne) {
            $options = maybe_unserialize($ligne->options);
            if (is_array($options) && ($options['labfab_mois'] ?? '') === $mois) {
                return (int) $ligne->id;
            }
        }
        return null;
    }

    /**
     * Prépare la lettre du mois $mois.
     *
     * @param string $mode        'programmer' (envoi automatique à la date prévue) ou 'brouillon'
     * @param string $declencheur 'automatique' ou 'manuel'
     * @return array L'entrée ajoutée à l'historique.
     */
    function preparer($mois, $mode, $declencheur) {
        $r = $this->reglages();

        if ($mode === 'programmer' && $this->lettre_programmee($mois)) {
            return ['resultat' => 'erreur', 'message' => 'Une lettre est déjà programmée pour ce mois. '
                . 'Mettez-la en pause ou supprimez-la dans Newsletter → Newsletters avant d\'en programmer une autre.'];
        }

        try {
            $c = $this->contenu($mois);
        } catch (Exception $e) {
            $entree = $this->noter($mois, ['resultat' => 'erreur', 'declencheur' => $declencheur,
                'message' => $e->getMessage()]);
            $this->notifier("Erreur : la newsletter de $mois n'a pas pu être préparée",
                $e->getMessage() . "\n\nElle sera retentée demain. Vous pouvez aussi la préparer à la main : "
                . admin_url('admin.php?page=' . self::PAGE));
            return $entree;
        }

        if (!$c['a_envoyer']) {
            $entree = $this->noter($mois, ['resultat' => 'rien', 'declencheur' => $declencheur,
                'message' => 'Rien ne s\'est passé en ' . $c['mois_precedent'] . ' et aucun événement n\'est prévu en '
                    . $c['mois'] . ' : pas de lettre.', 'resume' => self::resume($c)]);
            $this->notifier('Pas de newsletter pour ' . $c['mois'] . ' ' . $c['annee'], $entree['message']
                . "\n\nSi des événements sont ajoutés sur le site, vous pouvez la préparer à la main : "
                . admin_url('admin.php?page=' . self::PAGE));
            return $entree;
        }

        $envoi = $this->date_envoi($mois);
        $note = '';
        if ($mode === 'programmer' && $envoi->getTimestamp() <= time()) {
            $mode = 'brouillon';
            $note = ' La date d\'envoi prévue étant dépassée, la lettre a été créée en brouillon.';
        }

        $newsletter = Newsletter::instance();
        $requete = "select * from " . NEWSLETTER_USERS_TABLE . " where status='C'";
        global $wpdb;
        $email = $newsletter->save_email([
            'subject' => $c['sujet'],
            'message' => $this->rendre($c),
            'message_text' => NewsletterModuleBase::get_email_default_text_part(),
            'type' => 'message',
            'editor' => NewsletterEmails::EDITOR_HTML,
            'status' => $mode === 'programmer' ? TNP_Email::STATUS_SENDING : TNP_Email::STATUS_DRAFT,
            'send_on' => $mode === 'programmer' ? $envoi->getTimestamp() : time(),
            'track' => $newsletter->get_option('track'),
            'token' => NewsletterModuleBase::get_token(),
            'query' => $requete,
            'total' => (int) $wpdb->get_var(str_replace('*', 'count(*)', $requete)),
            'options' => ['labfab_mois' => $mois],
        ]);

        if (!$email || is_wp_error($email)) {
            $message = is_wp_error($email) ? $email->get_error_message() : 'enregistrement refusé par la base de données';
            $entree = $this->noter($mois, ['resultat' => 'erreur', 'declencheur' => $declencheur,
                'message' => 'The Newsletter Plugin n\'a pas pu enregistrer la lettre : ' . $message]);
            $this->notifier("Erreur : la newsletter de $mois n'a pas pu être créée", $entree['message']);
            return $entree;
        }

        $lien = admin_url('admin.php?page=newsletter_emails_edit&id=' . (int) $email->id);
        if ($mode === 'programmer') {
            $quand = self::date_lisible($envoi->getTimestamp(), true);
            $message = 'Lettre programmée : elle partira le ' . $quand . ', pour ' . (int) $email->total . ' abonné·es.';
            if (trim($r['edito']) !== '') {
                $this->enregistrer_reglages(['edito' => ''] + $r); // l'édito ne sert qu'une fois
            }
        } else {
            $message = 'Lettre créée en brouillon : à relire et envoyer depuis Newsletter → Newsletters.' . $note;
        }

        $entree = $this->noter($mois, ['resultat' => $mode === 'programmer' ? 'programmee' : 'brouillon',
            'declencheur' => $declencheur, 'email_id' => (int) $email->id, 'message' => $message,
            'resume' => self::resume($c)]);

        $test = $this->envoyer_test($email, $mode === 'programmer' ? $envoi : null);
        $this->notifier($message, $message . "\n\n" . self::resume($c) . "\n\n"
            . ($test === true ? 'Un exemplaire de test vient de vous être envoyé.' : 'Le test n\'a pas pu être envoyé : ' . $test)
            . "\n\nRelire, modifier ou annuler la lettre : " . $lien
            . ($mode === 'programmer' ? "\nPour l'annuler : ouvrez-la puis cliquez sur « Pause », ou supprimez-la." : ''));
        return $entree;
    }

    /** Envoie un exemplaire de la lettre à l'adresse de test, via The Newsletter Plugin. */
    function envoyer_test($email, $envoi = null) {
        $adresse = $this->reglages()['email_test'];
        if (!is_email($adresse)) {
            return 'aucune adresse de test valide dans les réglages';
        }
        $newsletter = Newsletter::instance();
        $destinataire = $newsletter->get_user($adresse);
        if (!$destinataire) {
            $destinataire = $newsletter->get_dummy_user();
            $destinataire->email = $adresse;
        }
        $copie = clone $email;
        $copie->subject = ($envoi ? '[Test – envoi le ' . self::date_lisible($envoi->getTimestamp()) . '] ' : '[Test] ')
            . $email->subject;
        $resultat = $newsletter->send($copie, [$destinataire], true);
        return is_wp_error($resultat) ? $resultat->get_error_message() : true;
    }

    private function notifier($sujet, $texte) {
        $adresse = $this->reglages()['email_test'];
        if (is_email($adresse)) {
            wp_mail($adresse, '[Newsletter LabFab] ' . $sujet, $texte);
        }
    }

    function enregistrer_reglages(array $valeurs) {
        $defaut = self::reglages_par_defaut();
        $propre = [
            'actif' => !empty($valeurs['actif']),
            'jour_preparation' => min(28, max(1, (int) ($valeurs['jour_preparation'] ?? $defaut['jour_preparation']))),
            'jour_envoi' => min(28, max(1, (int) ($valeurs['jour_envoi'] ?? $defaut['jour_envoi']))),
            'heure_envoi' => min(23, max(0, (int) ($valeurs['heure_envoi'] ?? $defaut['heure_envoi']))),
            'email_test' => sanitize_email($valeurs['email_test'] ?? ''),
            'sujet' => sanitize_text_field($valeurs['sujet'] ?? '') ?: $defaut['sujet'],
            'logo_url' => esc_url_raw($valeurs['logo_url'] ?? '') ?: $defaut['logo_url'],
            // Texte brut gardé tel quel : il est échappé au moment de construire la lettre.
            'edito' => trim(str_replace("\r\n", "\n", wp_check_invalid_utf8((string) ($valeurs['edito'] ?? '')))),
        ];
        update_option(self::OPTION_REGLAGES, $propre, false);
        return $propre;
    }

    // ------------------------------------------------------------------ Administration

    function admin_menu() {
        add_submenu_page('newsletter_main_index', 'LabFab automatique', 'LabFab automatique',
            'manage_options', self::PAGE, [$this, 'page']);
    }

    function newsletter_menu() {
        $this->add_newsletters_menu_page('LabFab automatique', '?page=' . self::PAGE);
    }

    function page() {
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé.');
        }
        $avis = [];
        $apercu = null;
        $action = isset($_POST['labfab_action']) ? sanitize_key($_POST['labfab_action']) : '';
        $mois = isset($_POST['mois']) && preg_match('/^\d{4}-\d{2}$/', $_POST['mois'])
            ? $_POST['mois'] : current_datetime()->modify('first day of next month')->format('Y-m');

        if ($action) {
            check_admin_referer('labfab_newsletter');
            if ($action === 'enregistrer') {
                $this->enregistrer_reglages(wp_unslash($_POST['reglages'] ?? []));
                $avis[] = ['success', 'Réglages enregistrés.'];
            } elseif ($action === 'apercu') {
                try {
                    $apercu = $this->contenu($mois);
                } catch (Exception $e) {
                    $avis[] = ['error', $e->getMessage()];
                }
            } elseif ($action === 'brouillon' || $action === 'programmer') {
                $entree = $this->preparer($mois, $action, 'manuel');
                $avis[] = [$entree['resultat'] === 'erreur' ? 'error' : ($entree['resultat'] === 'rien' ? 'warning' : 'success'),
                    $entree['message']];
            }
        }

        include LABFAB_NL_DIR . '/admin/page.php';
    }
}
