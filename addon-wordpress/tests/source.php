<?php
/**
 * Test de la lecture des dates des événements (includes/class-source.php), avec WordPress simulé :
 * appel interne de l'export calendrier, puis adresse publique, puis adresse locale.
 *
 * Usage : php tests/source.php
 */
define('ABSPATH', '/');
define('LABFAB_NL_DIR', dirname(__DIR__) . '/newsletter-labfab');

$ICS = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nDTSTART;TZID=Europe/Paris:20261209T100000\r\n"
    . "DTEND;TZID=Europe/Paris:20261209T123000\r\nSUMMARY:Atelier Presse DIY\r\nLOCATION:Edulab Rennes 2\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

// ------------------------------------------------ WordPress simulé
$GLOBALS['options'] = [];
$GLOBALS['http'] = [];          // requêtes réseau faites
$GLOBALS['reseau'] = [];        // réponse simulée par adresse : code HTTP
$GLOBALS['route'] = true;       // l'export calendrier existe-t-il ?
$GLOBALS['export'] = 'retour';  // comportement de l'export : retour | ecrit | exit
function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['options'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['options'][$k]); return true; }
function apply_filters($h, $v) { return $v; }
function rest_url($p = '') { return 'https://labfab.fr/wp-json/' . $p; }
function home_url() { return 'https://labfab.fr'; }
function wp_parse_url($u) { return parse_url($u); }
function add_query_arg($k, $v, $u) { return $u . (str_contains($u, '?') ? '&' : '?') . $k . '=' . $v; }
function is_wp_error($x) { return $x instanceof WP_Error; }
class WP_Error { function __construct(public $c = '', public $m = '') {} function get_error_message() { return $this->m; } }
function wp_remote_get($url, $args) {
    $GLOBALS['http'][] = ['url' => $url, 'host' => $args['headers']['Host'] ?? null];
    $type = str_contains($url, '127.0.0.1') ? 'locale' : 'publique';
    $code = $GLOBALS['reseau'][$type] ?? 503;
    if ($code === 0) return new WP_Error('http_request_failed', 'cURL error 28: Operation timed out');
    $corps = $code === 200 ? $GLOBALS['ICS'] : ($code === 404 ? '{"code":"no_date"}' : 'Service Unavailable');
    return ['code' => $code, 'body' => $corps];
}
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
class WP_REST_Request { var $params = []; function __construct(public $m, public $route) {} function set_query_params($p) { $this->params = $p; } }
class WP_REST_Response {
    function __construct(public $data, public $status = 200) {}
    function get_status() { return $this->status; } function get_data() { return $this->data; }
    function is_error() { return $this->status >= 400; }
}
class Serveur { function get_routes() { return $GLOBALS['route'] ? ['/labfab/v1/ics' => [[]]] : []; } }
function rest_get_server() { return new Serveur(); }
function rest_do_request($req) {
    $GLOBALS['appels_internes'] = ($GLOBALS['appels_internes'] ?? 0) + 1;
    $id = $req->params['event_id'];
    if ($id === 2004) return new WP_REST_Response(['code' => 'no_date', 'message' => 'No date found', 'data' => ['status' => 404]], 404);
    if ($GLOBALS['export'] === 'ecrit') { echo $GLOBALS['ICS']; return new WP_REST_Response(null, 200); }
    if ($GLOBALS['export'] === 'exit') { echo $GLOBALS['ICS']; exit; }
    return new WP_REST_Response($GLOBALS['ICS'], 200);
}

require LABFAB_NL_DIR . '/includes/class-contenu.php';
require LABFAB_NL_DIR . '/includes/class-source.php';

// Sous-processus : l'export calendrier arrête PHP (exit)
if (($argv[1] ?? '') === '--exit') {
    $GLOBALS['export'] = 'exit';
    $f = $argv[2];
    // Enregistré en dernier, pour passer après la vérification de l'addon
    register_shutdown_function(function () use ($f) {
        register_shutdown_function(function () use ($f) { file_put_contents($f, json_encode($GLOBALS['options'])); });
    });
    LabFab_NL_Source::calendrier(1993);
    exit;
}

$ok = 0; $ko = 0;
function verifier($nom, $c, $detail = '') {
    global $ok, $ko;
    if ($c) { $ok++; echo "  ✓ $nom\n"; } else { $ko++; echo "  ✗ $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
function remise_a_zero() {
    $GLOBALS['http'] = []; $GLOBALS['appels_internes'] = 0; $GLOBALS['options'] = [];
    $GLOBALS['route'] = true; $GLOBALS['export'] = 'retour'; $GLOBALS['reseau'] = [];
    LabFab_NL_Source::reinitialiser();
}
$tz = new DateTimeZone('Europe/Paris');

echo "Bouclage bloqué par l'hébergeur (503), lecture interne disponible — la situation de labfab.fr\n";
remise_a_zero();
$ics = LabFab_NL_Source::calendrier(1993);
$lu = LabFab_NL_Contenu::lire_ics($ics, $tz);
verifier('date lue', $lu && LabFab_NL_Contenu::affichage_dates($lu) === ['mer. 9 déc.', '10h – 12h30']);
verifier('par la méthode interne', LabFab_NL_Source::$methode === 'interne');
verifier('aucune requête réseau', $GLOBALS['http'] === []);
verifier('événement n°2004 : « sans date », sans erreur', LabFab_NL_Source::calendrier(2004) === null);

echo "\nExport calendrier qui écrit le fichier au lieu de le renvoyer\n";
remise_a_zero();
$GLOBALS['export'] = 'ecrit';
ob_start(); $ics = LabFab_NL_Source::calendrier(1993); $fuite = ob_get_clean();
verifier('fichier récupéré', is_string($ics) && str_contains($ics, 'Atelier Presse DIY'));
verifier('rien ne s\'affiche dans la page', $fuite === '');

echo "\nExport calendrier introuvable : repli sur le réseau\n";
remise_a_zero();
$GLOBALS['route'] = false;
$GLOBALS['reseau'] = ['publique' => 503, 'locale' => 200];
$ics = LabFab_NL_Source::calendrier(1993);
verifier('date lue par l\'adresse locale', $ics !== null && LabFab_NL_Source::$methode === 'locale');
verifier('adresse locale avec le nom du site', ($GLOBALS['http'][1]['url'] ?? '') === 'http://127.0.0.1/wp-json/labfab/v1/ics?event_id=1993'
    && ($GLOBALS['http'][1]['host'] ?? '') === 'labfab.fr', json_encode($GLOBALS['http']));
$GLOBALS['http'] = [];
LabFab_NL_Source::calendrier(1990);
verifier('l\'adresse publique en échec n\'est pas réessayée pour l\'événement suivant', count($GLOBALS['http']) === 1, json_encode($GLOBALS['http']));

echo "\nTout est bloqué\n";
remise_a_zero();
$GLOBALS['route'] = false;
$GLOBALS['reseau'] = ['publique' => 503, 'locale' => 0];
try { LabFab_NL_Source::calendrier(1993); $msg = ''; } catch (RuntimeException $e) { $msg = $e->getMessage(); }
verifier('erreur claire, avec les trois méthodes', str_contains($msg, 'interne : export calendrier introuvable')
    && str_contains($msg, 'publique : réponse HTTP 503') && str_contains($msg, 'locale : cURL error 28'), $msg);
echo "    → $msg\n";
$avant = count($GLOBALS['http']);
try { LabFab_NL_Source::calendrier(1990); } catch (RuntimeException $e) {}
verifier('pas de nouvelle attente pour l\'événement suivant', count($GLOBALS['http']) === $avant);

echo "\nExport calendrier qui arrête PHP (exit)\n";
$fichier = tempnam(sys_get_temp_dir(), 'labfab');
$sortie = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --exit ' . escapeshellarg($fichier));
$options = json_decode(file_get_contents($fichier), true);
verifier('l\'arrêt est détecté et noté', !empty($options['labfab_newsletter_appel_interne_interdit']), file_get_contents($fichier));
remise_a_zero();
update_option('labfab_newsletter_appel_interne_interdit', 1);
$GLOBALS['reseau'] = ['publique' => 200];
$ics = LabFab_NL_Source::calendrier(1993);
verifier('la fois suivante, la méthode interne est sautée', $GLOBALS['appels_internes'] === 0 && LabFab_NL_Source::$methode === 'publique');
LabFab_NL_Source::reinitialiser();
verifier('« Réessayer la lecture interne » la réautorise', get_option('labfab_newsletter_appel_interne_interdit') === false);

echo "\n$ok réussis, $ko échoués\n";
exit($ko ? 1 : 0);
