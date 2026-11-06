<?php
/**
 * Simulation de WordPress et de The Newsletter Plugin pour tester le fonctionnement de l'addon :
 * tâche quotidienne, préparation, programmation, brouillon, test, notifications, doublons, page d'admin.
 * Les fonctions simulées reprennent le comportement de WordPress 6 et de The Newsletter Plugin 9.5.4.
 */
define('ABSPATH', '/');
define('LABFAB_NL_VERSION', '1.0.0');
define('LABFAB_NL_DIR', dirname(__DIR__) . '/newsletter-labfab');
define('NEWSLETTER_USERS_TABLE', 'wp_newsletter');
define('NEWSLETTER_EMAILS_TABLE', 'wp_newsletter_emails');

// ---------------------------------------------------------------- WordPress simulé
$GLOBALS['options'] = ['admin_email' => 'admin@labfab.fr', 'default_category' => 1];
$GLOBALS['maintenant'] = '2026-11-25 06:05';
$GLOBALS['mails'] = [];
$GLOBALS['actions'] = [];
$GLOBALS['cron'] = [];
function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['options'][$k] = $v; return true; }
function wp_timezone() { return new DateTimeZone('Europe/Paris'); }
function current_datetime() { return new DateTimeImmutable($GLOBALS['maintenant'], wp_timezone()); }
function wp_date($f, $ts) {
    $d = (new DateTimeImmutable('@' . $ts))->setTimezone(wp_timezone());
    $mois = ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
    $jours = ['lundi','mardi','mercredi','jeudi','vendredi','samedi','dimanche'];
    $f = str_replace(['F', 'l'], ['"\M"', '"\J"'], $f);
    $s = $d->format($f);
    return str_replace(['"M"', '"J"'], [$mois[$d->format('n') - 1], $jours[$d->format('N') - 1]], $s);
}
function add_action($h, $cb, $p = 10) { $GLOBALS['actions'][$h][] = $cb; }
function add_filter($h, $cb, $p = 10) {}
function do_action_simule($h, ...$args) { foreach ($GLOBALS['actions'][$h] ?? [] as $cb) $cb(...$args); }
function is_admin() { return true; }
function wp_next_scheduled($h) { return $GLOBALS['cron'][$h] ?? false; }
function wp_schedule_event($ts, $r, $h) { $GLOBALS['cron'][$h] = $ts; }
function wp_clear_scheduled_hook($h) { unset($GLOBALS['cron'][$h]); }
function wp_unschedule_hook($h) { unset($GLOBALS['cron'][$h]); }
function register_deactivation_hook($f, $cb) {}
function load_plugin_textdomain(...$a) {}
function admin_url($p) { return 'https://labfab.fr/wp-admin/' . $p; }
function is_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL); }
function wp_mail($to, $s, $m) { $GLOBALS['mails'][] = ['a' => $to, 'objet' => $s, 'texte' => $m]; return true; }
function is_wp_error($x) { return $x instanceof WP_Error; }
class WP_Error { function __construct(public $c = '', public $m = '') {} function get_error_message() { return $this->m; } }
function sanitize_email($e) { return filter_var(trim($e), FILTER_VALIDATE_EMAIL) ?: ''; }
function sanitize_text_field($t) { return trim(strip_tags((string) $t)); }
function wp_check_invalid_utf8($t) { return $t; }
function esc_url_raw($u) { return filter_var($u, FILTER_VALIDATE_URL) ?: ''; }
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_url($u) { return htmlspecialchars((string) $u, ENT_QUOTES, 'UTF-8'); }
function esc_textarea($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function checked($v) { if ($v) echo 'checked="checked"'; }
function wp_nonce_field($a) { echo '<input type="hidden" name="_wpnonce" value="x">'; }
function check_admin_referer($a) { if (($_POST['_wpnonce'] ?? '') !== 'x') throw new Exception('nonce'); }
function current_user_can($c) { return true; }
function wp_die($m) { throw new Exception($m); }
function sanitize_key($k) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $k)); }
function wp_unslash($v) { return $v; }

// ---------------------------------------------------------------- The Newsletter Plugin simulé
class TNP_Email { const STATUS_DRAFT = 'new'; const STATUS_SENT = 'sent'; const STATUS_SENDING = 'sending'; }
class TNP_User { const STATUS_CONFIRMED = 'C'; }
class NewsletterEmails { const EDITOR_HTML = 1; }
class NewsletterModuleBase {
    static function get_token($l = 16) { return substr(md5(uniqid()), 0, $l); }
    static function get_email_default_text_part() { return 'Version texte'; }
}
class NewsletterAddon {
    var $weekly_check = true;
    function __construct($name, $version = '0.0.0', $dir = '') {
        $this->name = $name;
        add_action('newsletter_init', [$this, 'init']);
        if ($this->weekly_check) $GLOBALS['cron']['newsletter_addon_' . $name] = time();
    }
    function init() { add_action('admin_menu', [$this, 'admin_menu']); }
    function deactivate() {}
    function add_newsletters_menu_page($t, $u) {}
}
class Newsletter {
    static $i; var $emails = []; var $envois = [];
    static function instance() { return self::$i ??= new Newsletter(); }
    function get_option($k) { return $k === 'track' ? 1 : null; }
    function save_email($e) {
        $e = (array) $e;
        foreach (['subject', 'message', 'type', 'editor', 'status', 'send_on', 'token', 'query', 'track'] as $champ) {
            if (!array_key_exists($champ, $e)) throw new Exception("champ manquant : $champ");
        }
        $e['id'] = count($this->emails) + 1;
        $e['options'] = serialize($e['options']); // comme le plugin
        $this->emails[$e['id']] = $e;
        return $this->get_email($e['id']);
    }
    function get_email($id) {
        if (!isset($this->emails[$id])) return null;
        $o = (object) $this->emails[$id];
        $o->options = unserialize($o->options);
        return $o;
    }
    function get_user($email) { return null; }
    function get_dummy_user() { $u = new stdClass(); $u->id = 0; $u->email = 'john.doe@example.org'; return $u; }
    function send($email, $users = null, $test = false) {
        $this->envois[] = ['objet' => $email->subject, 'a' => array_map(fn($u) => $u->email, $users), 'test' => $test];
        return true;
    }
}
class wpdb_simule {
    function get_var($q) { return str_contains($q, "status='C'") && str_contains($q, 'count(*)') ? 213 : null; }
    function prepare($q, ...$a) { return str_replace('%s', "'" . $a[0] . "'", $q); }
    function get_results($q) {
        preg_match("/status='([a-z]+)'/", $q, $m);
        return array_values(array_map(fn($e) => (object) ['id' => $e['id'], 'options' => $e['options']],
            array_filter(Newsletter::instance()->emails, fn($e) => $e['status'] === $m[1])));
    }
}
function maybe_unserialize($v) { return is_string($v) ? unserialize($v) : $v; }
$GLOBALS['wpdb'] = new wpdb_simule();

// ---------------------------------------------------------------- Source simulée (vraies données du site)
$donnees = json_decode(file_get_contents(__DIR__ . '/donnees-labfab.json'), true);
class LabFab_NL_Source {
    static $donnees; static $panne = false;
    static function articles($debut, $fin) {
        foreach (self::$donnees['cas'] as $c) {
            if (LabFab_NL_Contenu::periode($c['mois'])['debut_passe'] === $debut) return $c['articles_entree'];
        }
        return [];
    }
    static function evenements() {
        if (self::$panne) throw new RuntimeException("Impossible de lire les dates de l'événement n°1999 (réponse HTTP 503).");
        return self::$donnees['evenements'];
    }
}
LabFab_NL_Source::$donnees = $donnees;

require LABFAB_NL_DIR . '/includes/class-contenu.php';
require LABFAB_NL_DIR . '/includes/class-addon.php';

// ---------------------------------------------------------------- Scénario
$ok = 0; $ko = 0;
function verifier($nom, $condition, $detail = '') {
    global $ok, $ko;
    if ($condition) { $ok++; echo "  ✓ $nom\n"; } else { $ko++; echo "  ✗ $nom" . ($detail ? " — $detail" : '') . "\n"; }
}
function quand($date) { $GLOBALS['maintenant'] = $date; }
function dernier($addon, $mois) { foreach ($addon->historique() as $h) if ($h['mois'] === $mois) return $h['resultat']; return ''; }

$addon = new LabFab_Newsletter_Addon(LABFAB_NL_DIR);
do_action_simule('newsletter_init');
$tnp = Newsletter::instance();

echo "Installation\n";
verifier('pas d\'appel hebdomadaire aux serveurs de l\'éditeur', !isset($GLOBALS['cron']['newsletter_addon_labfab']));
verifier('tâche quotidienne programmée', (bool) wp_next_scheduled('labfab_newsletter_quotidien'));
verifier('désactivé par défaut', $addon->reglages()['actif'] === false);
verifier('adresse de test = adresse de l\'admin', $addon->reglages()['email_test'] === 'admin@labfab.fr');

echo "\nAutomatisation désactivée, le 25 novembre\n";
quand('2026-11-25 06:05');
do_action_simule('labfab_newsletter_quotidien');
verifier('rien n\'est créé', !$tnp->emails && !$GLOBALS['mails']);

echo "\nActivation, avec un édito\n";
$addon->enregistrer_reglages(['actif' => '1', 'jour_preparation' => '25', 'jour_envoi' => '1', 'heure_envoi' => '9',
    'email_test' => 'axelle@exemple.fr', 'sujet' => 'La lettre du LabFab – {mois} {annee}',
    'logo_url' => 'https://raw.githubusercontent.com/Ax3l-ybc/LabFab-newsletter/main/assets/logo-labfab.png',
    'edito' => "Bonjour à toutes et tous !\n\nBonne lecture <3"]);
verifier('réglages enregistrés', $addon->reglages()['actif'] === true && $addon->reglages()['email_test'] === 'axelle@exemple.fr');

echo "\nLe 24 novembre : trop tôt\n";
quand('2026-11-24 06:05');
do_action_simule('labfab_newsletter_quotidien');
verifier('rien n\'est créé', !$tnp->emails);

echo "\nLe 25 novembre : préparation de la lettre de décembre\n";
quand('2026-11-25 06:05');
do_action_simule('labfab_newsletter_quotidien');
$e = $tnp->get_email(1);
verifier('une lettre créée dans The Newsletter Plugin', $e !== null);
verifier('statut « sending » (programmée)', $e && $e->status === 'sending', $e->status ?? '');
$envoi = new DateTimeImmutable('2026-12-01 09:00', wp_timezone());
verifier('envoi le 1er décembre à 9 h', $e && (int) $e->send_on === $envoi->getTimestamp(), date('c', (int) ($e->send_on ?? 0)));
verifier('objet « La lettre du LabFab – décembre 2026 »', $e && $e->subject === 'La lettre du LabFab – décembre 2026', $e->subject ?? '');
verifier('éditeur HTML, type message', $e && $e->editor === 1 && $e->type === 'message');
verifier('destinataires : abonné·es confirmé·es', $e && $e->query === "select * from wp_newsletter where status='C'" && $e->total === 213);
verifier('contient l\'Atelier Presse DIY', $e && str_contains($e->message, 'Atelier Presse DIY'));
verifier('contient l\'édito (échappé)', $e && str_contains($e->message, 'Bonjour à toutes et tous !<br><br>Bonne lecture &lt;3'));
verifier('balises de désinscription conservées', $e && str_contains($e->message, '{unsubscription_url}') && str_contains($e->message, '{email_url}'));
verifier('aucune balise PHP restée dans la lettre', $e && !str_contains($e->message, '<?php'));
verifier('mois noté dans les options', $e && $e->options['labfab_mois'] === '2026-12');
$test = $tnp->envois[0] ?? null;
verifier('test envoyé par le plugin à l\'adresse de test', $test && $test['test'] && $test['a'] === ['axelle@exemple.fr']);
verifier('objet du test « [Test – envoi le 1er décembre à 9h] … »', $test && $test['objet'] === '[Test – envoi le 1er décembre à 9h] La lettre du LabFab – décembre 2026', $test['objet'] ?? '');
verifier('mail récapitulatif envoyé', count($GLOBALS['mails']) === 1 && str_contains($GLOBALS['mails'][0]['texte'], 'newsletter_emails_edit&id=1'));
echo "    → « " . $GLOBALS['mails'][0]['objet'] . " »\n";
verifier('édito vidé après programmation', $addon->reglages()['edito'] === '');
verifier('historique : programmée', dernier($addon, '2026-12') === 'programmee');

echo "\nLes jours suivants : pas de doublon\n";
foreach (['2026-11-26 06:05', '2026-11-30 06:05'] as $d) { quand($d); do_action_simule('labfab_newsletter_quotidien'); }
verifier('toujours une seule lettre', count($tnp->emails) === 1);
quand('2026-11-27 10:00');
$r = $addon->preparer('2026-12', 'programmer', 'manuel');
verifier('programmation manuelle en double refusée', $r['resultat'] === 'erreur' && count($tnp->emails) === 1, $r['message']);
$r = $addon->preparer('2026-12', 'brouillon', 'manuel');
verifier('un brouillon reste possible', $r['resultat'] === 'brouillon' && $tnp->get_email(2)->status === 'new');
$r = $addon->preparer('2026-12', 'programmer', 'manuel');
verifier('après le brouillon, programmer en double reste refusé (bug corrigé)', $r['resultat'] === 'erreur' && count($tnp->emails) === 2, $r['message']);
quand('2026-11-28 06:05'); do_action_simule('labfab_newsletter_quotidien');
verifier('la tâche quotidienne ne recrée rien après le brouillon', count($tnp->emails) === 2);
$tnp->emails[1]['status'] = 'paused'; // la lettre est mise en pause dans le plugin
quand('2026-11-29 06:05'); do_action_simule('labfab_newsletter_quotidien');
verifier('une lettre annulée n\'est pas recréée automatiquement', count($tnp->emails) === 2);
$r = $addon->preparer('2026-12', 'programmer', 'manuel');
verifier('après annulation, on peut reprogrammer à la main', $r['resultat'] === 'programmee' && $tnp->get_email(3)->status === 'sending', $r['message']);

echo "\nLe 25 décembre : panne du site pendant la lecture des dates\n";
LabFab_NL_Source::$panne = true;
$GLOBALS['mails'] = [];
quand('2026-12-25 06:05');
do_action_simule('labfab_newsletter_quotidien');
verifier('aucune lettre créée', count($tnp->emails) === 3);
verifier('historique : erreur', dernier($addon, '2027-01') === 'erreur');
verifier('mail d\'erreur envoyé', str_starts_with($GLOBALS['mails'][0]['objet'] ?? '', '[Newsletter LabFab] Erreur'));
echo "\nLe 26 décembre : le site répond de nouveau, nouvel essai\n";
LabFab_NL_Source::$panne = false;
quand('2026-12-26 06:05');
do_action_simule('labfab_newsletter_quotidien');
$e = $tnp->get_email(4);
verifier('lettre de janvier programmée', $e && $e->status === 'sending' && $e->subject === 'La lettre du LabFab – janvier 2027');
verifier('elle parle de l\'Atelier Presse DIY passé', $e && str_contains($e->message, 'Événement · mer. 9 déc.'));

echo "\nLe 25 janvier : rien à raconter\n";
$GLOBALS['mails'] = [];
quand('2027-01-25 06:05');
do_action_simule('labfab_newsletter_quotidien');
verifier('aucune lettre créée', count($tnp->emails) === 4);
verifier('historique : pas de lettre', dernier($addon, '2027-02') === 'rien');
verifier('mail « Pas de newsletter pour février 2027 »', ($GLOBALS['mails'][0]['objet'] ?? '') === '[Newsletter LabFab] Pas de newsletter pour février 2027', $GLOBALS['mails'][0]['objet'] ?? '');
quand('2027-01-26 06:05');
do_action_simule('labfab_newsletter_quotidien');
verifier('pas de nouvel essai le lendemain', count($GLOBALS['mails']) === 1);

echo "\nPréparation tardive (date d'envoi dépassée)\n";
quand('2026-12-02 10:00');
$r = $addon->preparer('2026-12', 'programmer', 'manuel');
// La lettre de décembre programmée existe toujours → refus attendu ; on teste sur un autre mois
$r = $addon->preparer('2026-10', 'programmer', 'manuel');
verifier('créée en brouillon, pas envoyée', $r['resultat'] === 'brouillon' && str_contains($r['message'], 'dépassée'), $r['message']);

echo "\nPage d'administration\n";
quand('2026-11-10 14:00');
$_POST = ['_wpnonce' => 'x', 'labfab_action' => 'apercu', 'mois' => '2026-12'];
ob_start(); $addon->page(); $page = ob_get_clean();
verifier('la page s\'affiche avec l\'aperçu', str_contains($page, 'Aperçu de la lettre') && str_contains($page, 'srcdoc='));
verifier('résumé du contenu affiché', str_contains($page, 'mer. 9 déc. : Atelier Presse DIY'));
verifier('historique affiché', str_contains($page, 'Programmée') && str_contains($page, 'Ouvrir la lettre'));
file_put_contents(sys_get_temp_dir() . '/page-admin.html', '<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="https://labfab.fr/wp-admin/css/common.min.css">' . $page);
$_POST = ['_wpnonce' => 'mauvais', 'labfab_action' => 'enregistrer'];
try { ob_start(); $addon->page(); ob_end_clean(); $refus = false; } catch (Exception $x) { ob_end_clean(); $refus = true; }
verifier('formulaire refusé sans jeton de sécurité', $refus);

echo "\n$ok réussis, $ko échoués\n";
exit($ko ? 1 : 0);
