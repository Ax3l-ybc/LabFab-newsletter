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
function current_time($t) { return '2026-11-18 15:00:00'; }
function esc_url($u) { return $u; }
function admin_url($p) { return 'https://labfab.fr/wp-admin/' . $p; }
function rest_url($p = '') { return 'https://labfab.fr/wp-json/' . $p; }
function home_url() { return 'https://labfab.fr'; }
function wp_parse_url($u) { return parse_url($u); }
function add_query_arg($k, $v, $u) { return $u . (str_contains($u, '?') ? '&' : '?') . $k . '=' . $v; }
function is_wp_error($x) { return $x instanceof WP_Error; }
$GLOBALS['meta'] = [];          // champs des événements : id => [clé => valeur]
function get_post_meta($id) { return array_map(fn($v) => [is_array($v) ? serialize($v) : $v], $GLOBALS['meta'][$id] ?? []); }
function maybe_unserialize($v) { return is_string($v) && preg_match('/^a:\d+:/', $v) ? unserialize($v) : $v; }
function get_the_title($p) { $id = is_object($p) ? $p->ID : $p; return [12 => 'Edulab Rennes 2', 13 => 'IMT Atlantique'][$id] ?? ''; }
function wp_timezone() { return new DateTimeZone('Europe/Paris'); }
function get_posts($a) { return array_map(fn($i) => (object) ['ID' => $i], array_keys($GLOBALS['meta'])); }
function get_permalink($p) { return 'https://labfab.fr/?p=' . $p->ID; }
function get_the_excerpt($p) { return ''; }
function get_the_post_thumbnail_url($p, $t) { return false; }
function get_the_date($f, $p) { return '2026-10-01'; }
$GLOBALS['contenu'] = [];       // contenu brut des événements (blocs)
function get_post_field($champ, $id) { return $GLOBALS['contenu'][$id] ?? ''; }
function parse_blocks($c) {     // version simplifiée de l'analyseur de blocs de WordPress
    preg_match_all('/<!-- wp:([a-z0-9\-\/]+)\s+(\{.*?\})\s+\/?-->/s', $c, $m, PREG_SET_ORDER);
    return array_map(fn($x) => ['blockName' => str_contains($x[1], '/') ? $x[1] : 'core/' . $x[1],
        'attrs' => json_decode($x[2], true), 'innerBlocks' => []], $m);
}
function wp_json_encode($v) { return json_encode($v, JSON_UNESCAPED_UNICODE); }
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

echo "\nTout est bloqué, et l'événement n'a aucun champ de date\n";
remise_a_zero();
$GLOBALS['route'] = false;
$GLOBALS['reseau'] = ['publique' => 503, 'locale' => 0];
verifier('dernier recours : lecture dans les champs, « sans date »', LabFab_NL_Source::calendrier(1993) === null && LabFab_NL_Source::$methode === 'base');
$avant = count($GLOBALS['http']);
LabFab_NL_Source::calendrier(1990);
verifier('pas de nouvelle attente réseau pour l\'événement suivant', count($GLOBALS['http']) === $avant);

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

echo "\nRéseau bloqué et export calendrier qui arrête la page : dates lues dans les champs — la situation de labfab.fr\n";
remise_a_zero();
update_option('labfab_newsletter_appel_interne_interdit', 1);
$GLOBALS['reseau'] = ['publique' => 503, 'locale' => 503];
$cas = [
    'ACF : date + heures séparées, lieu lié' => [['date_debut' => '20261209', 'heure_debut' => '10:00:00', 'date_fin' => '20261209',
        'heure_fin' => '12:30:00', 'lieux' => ['12'], 'nombre_places' => '12'], 'mer. 9 déc., 10h – 12h30 — Edulab Rennes 2'],
    'date et heure ensemble (anglais)' => [['start_date' => '2026-10-19 10:00:00', 'end_date' => '2026-10-19 18:00:00', 'location' => 'IMT Atlantique'],
        'lun. 19 oct., 10h – 18h — IMT Atlantique'],
    'format JJ/MM/AAAA, nom en camelCase, sur deux jours' => [['dateDebut' => '19/10/2026', 'dateFin' => '20/10/2026'], 'lun. 19 oct. → mar. 20 oct.'],
    '« calendar_date » n\'est pas pris pour une fin' => [['calendar_date' => '20261014', 'heure' => '14h00'], 'mer. 14 oct., 14h'],
    'plusieurs lieux liés' => [['date' => '20260926', 'heure_debut' => '13:00', 'heure_fin' => '19:00', 'antennes' => ['12', '13']],
        'sam. 26 sept., 13h – 19h — Edulab Rennes 2, IMT Atlantique'],
    'fin avant le début : ignorée' => [['date_debut' => '20261209', 'date_fin' => '20261201'], 'mer. 9 déc.'],
];
$i = 100;
foreach ($cas as $nom => list($champs, $attendu)) {
    $GLOBALS['meta'] = [$i => $champs];
    $ics = LabFab_NL_Source::calendrier($i);
    $lu = $ics ? LabFab_NL_Contenu::lire_ics($ics, $tz) : null;
    $obtenu = $lu ? implode(', ', array_filter(LabFab_NL_Contenu::affichage_dates($lu))) . ($lu['lieu'] ? ' — ' . $lu['lieu'] : '') : 'rien';
    verifier($nom, $obtenu === $attendu && LabFab_NL_Source::$methode === 'base', $obtenu);
    $i++;
}
verifier('le réseau n\'est essayé qu\'une fois en tout', count($GLOBALS['http']) === 2, count($GLOBALS['http']) . ' requêtes');

echo "\nDates rangées ailleurs que dans des champs simples\n";
$cas = [
    'bloc Lazy Blocks dans le contenu' => [[], '<!-- wp:paragraph {"align":"left"} /--><!-- wp:lazyblock/evenement {"date-debut":"2026-12-09T10:00:00","date-fin":"2026-12-09T12:30:00","lieu":"Edulab Rennes 2"} /-->',
        'mer. 9 déc., 10h – 12h30 — Edulab Rennes 2'],
    'répéteur (liste de séances)' => [['seances' => [['jour' => '20261019', 'horaire_debut' => '10h', 'horaire_fin' => '18h']]], '', 'lun. 19 oct., 10h – 18h'],
    'format ISO avec fuseau' => [['event_start' => '2026-10-14T10:00:00+02:00', 'event_end' => '2026-10-14T12:00:00+02:00'], '', 'mer. 14 oct., 10h – 12h'],
    'date en toutes lettres' => [['quand' => 'mercredi 9 décembre 2026 à 10h'], '', 'mer. 9 déc., 10h'],
    'horodatage en millisecondes' => [['debut_ms' => (string) ((new DateTimeImmutable('2026-12-09 10:00', $tz))->getTimestamp() * 1000)], '', 'mer. 9 déc., 10h'],
    'une durée « 2h » n\'est pas une heure de début' => [['date' => '20261209', 'duree' => '2h'], '', 'mer. 9 déc.'],
    'date de mise à jour ignorée' => [['date_maj' => '2026-09-30', 'date_evenement' => '2026-12-09'], '', 'mer. 9 déc.'],
];
foreach ($cas as $nom => list($champs, $contenu, $attendu)) {
    remise_a_zero();
    update_option('labfab_newsletter_appel_interne_interdit', 1);
    $GLOBALS['reseau'] = ['publique' => 503, 'locale' => 503];
    $GLOBALS['meta'] = [$i => $champs];
    $GLOBALS['contenu'] = [$i => $contenu];
    $ics = LabFab_NL_Source::calendrier($i);
    $lu = $ics ? LabFab_NL_Contenu::lire_ics($ics, $tz) : null;
    $obtenu = $lu ? implode(', ', array_filter(LabFab_NL_Contenu::affichage_dates($lu))) . ($lu['lieu'] ? ' — ' . $lu['lieu'] : '') : 'rien';
    verifier($nom, $obtenu === $attendu, $obtenu);
    $i++;
}
$GLOBALS['contenu'] = [];

echo "\nChamps « cachés » de l'extension agenda de labfab.fr (_evenement_…), formats possibles\n";
$techniques = ['_edit_lock' => '1763470000:1', '_edit_last' => '1', '_thumbnail_id' => '2001',
    '_yoast_wpseo_estimated-reading-time-minutes' => '2', 'cmplz_hide_cookiebanner' => ''];
$cas = [
    'liste de dates + horaires début/fin' => [['_evenement_date_type' => 'unique', '_evenement_dates' => ['2026-12-09'],
        '_evenement_horaire_type' => 'plage', '_evenement_horaires' => ['debut' => '10:00', 'fin' => '12:30'], '_evenement_lieu' => 'Edulab Rennes 2'],
        'mer. 9 déc., 10h – 12h30 — Edulab Rennes 2'],
    'date JJ/MM/AAAA + horaires en texte' => [['_evenement_dates' => '09/12/2026', '_evenement_horaires' => '10h - 12h30'], 'mer. 9 déc., 10h – 12h30'],
    'plage de dates en texte' => [['_evenement_date_type' => 'plage', '_evenement_dates' => '19/10/2026 - 20/10/2026'], 'lun. 19 oct. → mar. 20 oct.'],
    'plage « du … au … » et horaires « de … à … »' => [['_evenement_dates' => 'du 2026-10-19 au 2026-10-20', '_evenement_horaires' => 'de 10:00 à 18:00'],
        'lun. 19 oct. → mar. 20 oct.'],
    'dates ACF en liste de séances' => [['_evenement_dates' => [['date' => '20261014', 'heure_debut' => '10:00:00', 'heure_fin' => '12:00:00']]],
        'mer. 14 oct., 10h – 12h'],
    'antennes liées, sans lieu' => [['_evenement_dates' => ['2026-09-26'], '_evenement_horaires' => ['13:00', '19:00'],
        '_evenement_antennes' => ['12', '13'], '_evenement_lieu' => ''], 'sam. 26 sept., 13h – 19h — Edulab Rennes 2, IMT Atlantique'],
    'le lieu passe avant les antennes' => [['_evenement_antennes' => ['13'], '_evenement_lieu' => 'Edulab Rennes 2', '_evenement_dates' => ['2026-12-09']],
        'mer. 9 déc. — Edulab Rennes 2'],
    'champs techniques seuls : pas de date' => [[], 'rien'],
];
foreach ($cas as $nom => list($champs, $attendu)) {
    remise_a_zero();
    update_option('labfab_newsletter_appel_interne_interdit', 1);
    $GLOBALS['reseau'] = ['publique' => 503, 'locale' => 503];
    $GLOBALS['meta'] = [$i => $techniques + $champs];
    $ics = LabFab_NL_Source::calendrier($i);
    $lu = $ics ? LabFab_NL_Contenu::lire_ics($ics, $tz) : null;
    $obtenu = $lu ? implode(', ', array_filter(LabFab_NL_Contenu::affichage_dates($lu))) . ($lu['lieu'] ? ' — ' . $lu['lieu'] : '') : 'rien';
    verifier($nom, $obtenu === $attendu, $obtenu);
    $i++;
}


remise_a_zero();
update_option('labfab_newsletter_appel_interne_interdit', 1);
$GLOBALS['meta'] = [2004 => ['sous_titre' => 'Ateliers'], 1993 => ['date_debut' => '20261209', 'heure_debut' => '10:00']];
$evts = LabFab_NL_Source::evenements();
verifier('événement sans champ de date : « sans date »', $evts[0]['ics'] === null && $evts[1]['ics'] !== null);
verifier('champs utilisés notés pour le diagnostic', LabFab_NL_Source::$base_champs === ['date_debut', 'heure_debut'], json_encode(LabFab_NL_Source::$base_champs));

remise_a_zero();
update_option('labfab_newsletter_appel_interne_interdit', 1);
$GLOBALS['meta'] = [2004 => ['sous_titre' => 'Ateliers'], 1993 => ['texte' => 'bonjour']];
try { LabFab_NL_Source::evenements(); $msg = ''; } catch (RuntimeException $e) { $msg = $e->getMessage(); }
verifier('aucune date reconnue nulle part : erreur, pas une lettre sans dates', str_contains($msg, 'aucune date n\'a été reconnue'), $msg);

echo "\n$ok réussis, $ko échoués\n";
exit($ko ? 1 : 0);
