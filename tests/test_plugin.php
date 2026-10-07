<?php
// Banc de test CLI : vérifie le rendu et les caches avec un adaptateur WordPress.
// Une installation réelle WordPress reste nécessaire pour le test d'intégration.
define('ABSPATH', __DIR__);
define('MINUTE_IN_SECONDS', 60);
$options = array('rpb_index_url' => 'https://example.org/index.json');
$fail_remote = false;
$public = dirname(__DIR__) . '/public/';
function add_action(...$args) {}
function add_shortcode(...$args) {}
function wp_register_style(...$args) {}
function wp_register_script(...$args) {}
function wp_enqueue_style(...$args) {}
function wp_enqueue_script(...$args) {}
function plugins_url($path, $file) { return '../wordpress/revue-presse-bassin/' . $path; }
function get_option($key, $default = false) { global $options; return $options[$key] ?? $default; }
function update_option($key, $value, $autoload = false) { global $options; $options[$key] = $value; }
function wp_safe_remote_get($url, $args = array()) {
    global $public, $fail_remote;
    if ($fail_remote) { return array('code' => 503, 'body' => ''); }
    $path = parse_url($url, PHP_URL_PATH);
    return array('code' => 200, 'body' => file_get_contents($public . ltrim($path, '/')));
}
function is_wp_error($value) { return false; }
function wp_remote_retrieve_response_code($value) { return $value['code']; }
function wp_remote_retrieve_body($value) { return $value['body']; }
function shortcode_atts($defaults, $attributes, $tag) { return array_merge($defaults, $attributes); }
function current_user_can($cap) { return true; }
function sanitize_text_field($text) { return strip_tags($text); }
function wp_unslash($text) { return $text; }
function esc_html($text) { return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text) { return esc_html($text); }
function esc_url($url) { return preg_match('~^https?://|^\.\./~', $url) ? esc_attr($url) : ''; }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function get_permalink() { return 'https://example.org/revue-de-presse/'; }
function get_queried_object_id() { return 1; }
function wp_unique_id($prefix) { static $n = 0; return $prefix . ++$n; }
function selected($a, $b) { if ($a === $b) { echo 'selected="selected"'; } }
function wp_date($format, $stamp, $zone) {
    $date = (new DateTimeImmutable('@' . $stamp))->setTimezone($zone);
    $result = $date->format($format);
    $en = array('January','February','March','April','May','June','July','August','September','October','November','December');
    $fr = array('janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre');
    return str_replace($en, $fr, $result);
}
require dirname(__DIR__) . '/wordpress/revue-presse-bassin/revue-presse-bassin.php';
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
$html = RPB_Revue_Presse::shortcode(array('periode' => '2026-09'));
$month = json_decode(file_get_contents($public . 'mois/2026-09.json'), true);
$expected = count($month['articles']);
check($expected >= 31, 'La sélection initiale est conservée');
check(substr_count($html, 'class="rpb-card"') === $expected, 'Toutes les références sont rendues');
check(strpos($html, 'data-view="date"') !== false, 'La vue chronologique est disponible');
check(strpos($html, 'name="rp_period"') !== false, 'Les archives sont sélectionnables');

$valid = new ReflectionMethod('RPB_Revue_Presse', 'valid');
check($valid->invoke(null, $month, 'period'), 'Le JSON généré est accepté');
$bad = $month; $bad['articles'][0]['places'] = 'incorrect';
check(!$valid->invoke(null, $bad, 'period'), 'Les structures invalides sont rejetées');
$bad = $month; $bad['articles'][0]['theme'] = 'unknown';
check(!$valid->invoke(null, $bad, 'period'), 'Les thèmes inconnus sont rejetés');

$key = 'rpb_cache_' . md5($options['rpb_index_url']);
$options[$key]['2026-09']['data']['articles'][0]['title'] = '<script>alert(1)</script>';
$escaped = RPB_Revue_Presse::shortcode(array('periode' => '2026-09'));
check(strpos($escaped, '<script>alert(1)</script>') === false, 'Les titres sont échappés');
$options[$key]['2026-09']['data'] = $month;
foreach ($options[$key] as &$entry) { $entry['checked_at'] = 0; } unset($entry);
$fail_remote = true;
$fallback = RPB_Revue_Presse::shortcode(array('periode' => '2026-09'));
check(substr_count($fallback, 'class="rpb-card"') === $expected, 'Le dernier résultat valide est conservé');
check(strpos($fallback, 'momentanément indisponible') !== false, 'La panne est signalée');
$fail_remote = false;
if (isset($argv[1])) {
    file_put_contents($argv[1], '<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Test Revue de presse</title><link rel="stylesheet" href="../wordpress/revue-presse-bassin/assets/revue.css"><body style="margin:0;padding:20px;background:#fff">' . $html . '<script src="../wordpress/revue-presse-bassin/assets/revue.js"></script></body></html>');
}
echo "Plugin : rendu, validation JSON, échappement et copie de secours vérifiés.\n";
