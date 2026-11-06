<?php
// Compare la logique et le rendu de l'addon PHP avec le programme Python, sur les vraies données.
define('ABSPATH', '/');
define('LABFAB_NL_DIR', dirname(__DIR__) . '/newsletter-labfab');
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_url($u) { return htmlspecialchars((string) $u, ENT_QUOTES, 'UTF-8'); }
require LABFAB_NL_DIR . '/includes/class-contenu.php';

$d = json_decode(file_get_contents(__DIR__ . '/donnees-labfab.json'), true);
$tz = new DateTimeZone('Europe/Paris');
$echecs = 0;
foreach ($d['cas'] as $cas) {
    $c = LabFab_NL_Contenu::construire($cas['articles_entree'], $d['evenements'], $cas['mois'], $cas['aujourdhui'], $tz);
    $attendu = $cas['attendu'];
    $diff = [];
    foreach ($attendu as $cle => $valeur) {
        if (json_encode($c[$cle], JSON_UNESCAPED_UNICODE) !== json_encode($valeur, JSON_UNESCAPED_UNICODE)) {
            $diff[] = $cle . "\n    PHP   : " . json_encode($c[$cle], JSON_UNESCAPED_UNICODE) . "\n    Python: " . json_encode($valeur, JSON_UNESCAPED_UNICODE);
        }
    }
    // Rendu : même lettre, à l'échappement HTML près
    $c['logo_url'] = 'https://exemple.org/logo.png';
    $c['edito'] = '';
    ob_start();
    include LABFAB_NL_DIR . '/templates/lettre.php';
    $html = ob_get_clean();
    $norm = function ($h) {
        $h = html_entity_decode($h, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $h = preg_replace('/\s*(<|>)\s*/u', '$1', $h);
        return trim(preg_replace('/\s+/u', ' ', $h));
    };
    $memes = $norm($html) === $norm($cas['html']);
    if (!$memes) {
        file_put_contents(sys_get_temp_dir() . "/php-{$cas['mois']}.html", $html);
        $diff[] = 'rendu HTML différent (voir ' . sys_get_temp_dir() . '/php-' . $cas['mois'] . '.html)';
    }
    $ok = !$diff;
    $echecs += $ok ? 0 : 1;
    printf("%s (généré le %s) : %s — %d article(s) [%s], %d événement(s), lettre %s\n", $cas['mois'], $cas['aujourdhui'],
        $ok ? 'IDENTIQUE' : 'DIFFÉRENT', count($c['articles']), $c['source_passe'], count($c['evenements']),
        $c['a_envoyer'] ? 'oui' : 'non');
    foreach ($diff as $l) echo "  ✗ $l\n";
}
exit($echecs ? 1 : 0);
