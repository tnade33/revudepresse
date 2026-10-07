<?php
/**
 * Plugin Name: Revue de presse du Bassin
 * Description: Affiche les JSON de la revue de presse avec filtres et archives.
 * Version: 1.0.0
 * Author: TVCapFerret
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 */
if (!defined('ABSPATH')) { exit; }

final class RPB_Revue_Presse {
    const VERSION = '1.0.0';
    const SETTING = 'rpb_index_url';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_init', array(__CLASS__, 'settings'));
        add_shortcode('revue_presse_bassin', array(__CLASS__, 'shortcode'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'register_assets'));
    }

    public static function register_assets() {
        wp_register_style('rpb-style', plugins_url('assets/revue.css', __FILE__), array(), self::VERSION);
        wp_register_script('rpb-script', plugins_url('assets/revue.js', __FILE__), array(), self::VERSION, true);
    }

    public static function menu() {
        add_options_page('Revue de presse du Bassin', 'Revue de presse', 'manage_options', 'rpb', array(__CLASS__, 'admin'));
    }

    public static function settings() {
        register_setting('rpb', self::SETTING, array('type' => 'string', 'sanitize_callback' => array(__CLASS__, 'sanitize_url')));
    }

    public static function sanitize_url($value) {
        $url = esc_url_raw(trim($value), array('https'));
        if ($value !== '' && (!$url || !preg_match('~/index\.json$~', $url) || !wp_http_validate_url($url))) {
            add_settings_error(self::SETTING, 'url', 'Indiquez une URL HTTPS publique terminant par /index.json.');
            return get_option(self::SETTING, '');
        }
        return $url;
    }

    public static function admin() {
        if (!current_user_can('manage_options')) { return; }
        if (isset($_POST['rpb_refresh'])) {
            check_admin_referer('rpb_refresh');
            $url = get_option(self::SETTING, '');
            $key = self::cache_key($url);
            $saved = get_option($key, array());
            foreach ($saved as $id => &$item) { $item['checked_at'] = 0; }
            unset($item);
            update_option($key, $saved, false);
            echo '<div class="notice notice-success"><p>Les données seront revérifiées au prochain affichage de la page.</p></div>';
        }
        ?>
        <div class="wrap"><h1>Revue de presse du Bassin</h1>
        <p>Renseignez l’adresse de l’index JSON publié sur GitHub Pages.</p>
        <form method="post" action="options.php">
            <?php settings_fields('rpb'); ?>
            <label for="rpb-url"><strong>Adresse de l’index JSON</strong></label><br>
            <input id="rpb-url" class="large-text" type="url" name="<?php echo esc_attr(self::SETTING); ?>"
                value="<?php echo esc_attr(get_option(self::SETTING, '')); ?>" placeholder="https://votre-compte.github.io/revue-presse-bassin/index.json">
            <?php submit_button('Enregistrer'); ?>
        </form>
        <p>Insérez <code>[revue_presse_bassin]</code> dans une page. Pour ouvrir septembre :
           <code>[revue_presse_bassin periode="2026-09"]</code>.</p>
        <p>Le cache est revérifié au maximum toutes les 30 minutes lors des visites. La dernière copie valide est conservée si le serveur distant ne répond pas.</p>
        <form method="post"><?php wp_nonce_field('rpb_refresh'); ?>
            <button class="button" name="rpb_refresh" value="1">Revérifier les données</button>
        </form></div>
        <?php
    }

    private static function cache_key($url) { return 'rpb_cache_' . md5($url); }

    private static function valid($data, $type) {
        if (!is_array($data) || ($data['schema_version'] ?? null) !== 1 || empty($data['generated_at'])
            || !is_string($data['generated_at']) || !strtotime($data['generated_at'])) { return false; }
        if ($type === 'index') {
            if (!isset($data['periods']) || !is_array($data['periods'])) { return false; }
            foreach ($data['periods'] as $period) {
                if (!is_array($period) || empty($period['id']) || empty($period['url'])
                    || !is_string($period['id']) || !is_string($period['url'])
                    || !is_string($period['start'] ?? null) || !is_string($period['end'] ?? null)
                    || !is_string($period['label'] ?? null)
                    || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $period['start'])
                    || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $period['end'])
                    || !preg_match('~^(derniere-semaine\.json|semaine-courante\.json|mois/\d{4}-\d{2}\.json)$~', $period['url'])) { return false; }
            }
            return true;
        }
        if (!isset($data['period']['id'], $data['articles']) || !is_array($data['articles'])
            || count($data['articles']) > 10000) { return false; }
        if (!is_array($data['themes'] ?? null) || !is_array($data['topics'] ?? null)) { return false; }
        $themes = array();
        foreach (array_merge($data['themes'], $data['topics']) as $entry) {
            if (!is_array($entry) || !is_string($entry['id'] ?? null) || !is_string($entry['label'] ?? null)) { return false; }
        }
        foreach ($data['themes'] as $theme) { $themes[] = $theme['id']; }
        foreach ($data['articles'] as $a) {
            if (!is_array($a)) { return false; }
            foreach (array('id', 'title', 'url', 'source_id', 'source_name', 'publication_date', 'published_at', 'theme') as $field) {
                if (!isset($a[$field]) || !is_string($a[$field])) { return false; }
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $a['publication_date']) || !strtotime($a['published_at'])
                || !preg_match('~^https?://~i', $a['url']) || !is_array($a['places'] ?? null)
                || !is_string($a['description'] ?? '') || !in_array($a['theme'], $themes, true)
                || (isset($a['topic_id']) && !is_string($a['topic_id']))) { return false; }
            foreach ($a['places'] as $place) { if (!is_string($place)) { return false; } }
        }
        return true;
    }

    private static function load($url, $key, $id, $type) {
        $cache = get_option($key, array());
        $saved = $cache[$id] ?? null;
        if ($saved && time() - $saved['checked_at'] < 30 * MINUTE_IN_SECONDS) {
            return array($saved['data'], !empty($saved['failed']));
        }
        $response = wp_safe_remote_get($url, array('timeout' => 8, 'redirection' => 3,
            'limit_response_size' => 8 * 1024 * 1024, 'headers' => array('Accept' => 'application/json')));
        $data = null;
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $candidate = json_decode(wp_remote_retrieve_body($response), true);
            if (self::valid($candidate, $type) && ($type === 'index' || $candidate['period']['id'] === $id)) { $data = $candidate; }
        }
        if ($data !== null) {
            $cache[$id] = array('data' => $data, 'checked_at' => time(), 'failed' => false);
            update_option($key, $cache, false);
            return array($data, false);
        }
        if ($saved) {
            $cache[$id]['checked_at'] = time(); $cache[$id]['failed'] = true;
            update_option($key, $cache, false);
            return array($saved['data'], true);
        }
        return array(null, true);
    }

    public static function shortcode($attributes) {
        $attributes = shortcode_atts(array('periode' => ''), $attributes, 'revue_presse_bassin');
        $url = get_option(self::SETTING, '');
        if (!$url) {
            return current_user_can('manage_options') ? '<p>Configurez l’adresse JSON dans Réglages → Revue de presse.</p>' : '<p>La revue de presse sera disponible prochainement.</p>';
        }
        $key = self::cache_key($url);
        list($index, $stale_index) = self::load($url, $key, 'index', 'index');
        if (!$index) { return '<p>La revue de presse est temporairement indisponible.</p>'; }
        $requested = isset($_GET['rp_period']) ? sanitize_text_field(wp_unslash($_GET['rp_period'])) : $attributes['periode'];
        $requested = $requested ?: ($index['default_period'] ?? 'latest-complete-week');
        $selected = null;
        foreach ($index['periods'] as $p) { if ($p['id'] === $requested) { $selected = $p; break; } }
        if (!$selected && !empty($index['periods'])) { $selected = $index['periods'][0]; }
        if (!$selected) { return '<p>Aucune période disponible.</p>'; }
        $base = substr($url, 0, -strlen('index.json'));
        list($data, $stale_period) = self::load($base . $selected['url'], $key, $selected['id'], 'period');
        if (!$data || $data['period']['id'] !== $selected['id']) { return '<p>Cette période est temporairement indisponible.</p>'; }
        self::register_assets(); wp_enqueue_style('rpb-style'); wp_enqueue_script('rpb-script');
        $uid = wp_unique_id('rpb-');
        $articles = $data['articles'];
        usort($articles, function ($a, $b) { return strcmp($b['published_at'], $a['published_at']); });
        $sources = array(); $places = array(); $themes = array(); $topics = array();
        foreach (($data['themes'] ?? array()) as $t) { if (isset($t['id'], $t['label'])) { $themes[$t['id']] = $t['label']; } }
        foreach (($data['topics'] ?? array()) as $t) { if (isset($t['id'], $t['label'])) { $topics[$t['id']] = $t['label']; } }
        foreach ($articles as $a) {
            $sources[$a['source_id']] = $a['source_name'];
            foreach ($a['places'] as $place) { $places[$place] = $place; }
        }
        asort($sources); asort($places);
        $failed_sources = array(); $unchecked = false;
        foreach (($index['sources'] ?? array()) as $s) {
            if (($s['status'] ?? '') === 'error') { $failed_sources[$s['source_name']] = true; }
            if (($s['status'] ?? '') === 'not_checked') { $unchecked = true; }
        }
        ob_start(); ?>
        <div class="rpb" id="<?php echo esc_attr($uid); ?>">
            <div class="rpb-intro"><p class="rpb-kicker">LA PRESSE EN REVUE</p>
                <h2>Revue de presse du Bassin d’Arcachon</h2>
                <p>Une sélection d’articles de la presse locale et nationale. Retrouvez chaque publication sur le site de son média.</p>
                <p class="rpb-meta">Du <?php echo esc_html(self::date_label($selected['start'])); ?> au <?php echo esc_html(self::date_label($selected['end'])); ?>
                · Données actualisées le <?php echo esc_html(wp_date('d/m/Y à H:i', strtotime($data['generated_at']), new DateTimeZone('Europe/Paris'))); ?></p>
            </div>
            <?php if ($stale_index || $stale_period): ?><p class="rpb-notice" role="status">La dernière version disponible est affichée ; l’actualisation est momentanément indisponible.</p><?php endif; ?>
            <form method="get" action="<?php echo esc_url(get_permalink()); ?>" class="rpb-period">
                <?php if (!get_option('permalink_structure')): ?><input type="hidden" name="page_id" value="<?php echo esc_attr(get_queried_object_id()); ?>"><?php endif; ?>
                <label for="<?php echo esc_attr($uid); ?>period">Période</label>
                <select id="<?php echo esc_attr($uid); ?>period" name="rp_period">
                    <?php foreach ($index['periods'] as $p): ?>
                    <option value="<?php echo esc_attr($p['id']); ?>" <?php selected($p['id'], $selected['id']); ?>><?php echo esc_html(self::period_label($p)); ?></option>
                    <?php endforeach; ?>
                </select><button type="submit">Afficher</button>
            </form>
            <div class="rpb-filters">
                <?php self::select_filter($uid, 'theme', 'Thème', $themes, 'Tous les thèmes'); ?>
                <?php self::select_filter($uid, 'source', 'Média', $sources, 'Tous les médias'); ?>
                <?php self::select_filter($uid, 'place', 'Commune ou lieu', $places, 'Tous les lieux'); ?>
                <div><label for="<?php echo esc_attr($uid); ?>search">Rechercher</label><input id="<?php echo esc_attr($uid); ?>search" type="search" data-filter="search" placeholder="Un sujet, un mot…"></div>
            </div>
            <div class="rpb-tools"><div class="rpb-views" role="group" aria-label="Ordre de lecture">
                <button type="button" data-view="theme" aria-pressed="true">Par thème</button>
                <button type="button" data-view="date" aria-pressed="false">Par date</button>
            </div><label class="rpb-group-toggle"><input type="checkbox" data-group checked> Regrouper les sujets communs</label></div>
            <p class="rpb-count" aria-live="polite"><?php echo esc_html(count($articles)); ?> article(s)</p>
            <div class="rpb-results">
            <?php foreach ($themes as $theme_id => $label):
                $items = array_filter($articles, function ($a) use ($theme_id) { return $a['theme'] === $theme_id; });
                if (!$items) { continue; } ?>
                <section class="rpb-section"><h3><?php echo esc_html($label); ?></h3><div class="rpb-cards">
                    <?php foreach ($items as $a) { self::card($a, $themes, $topics); } ?>
                </div></section>
            <?php endforeach; ?>
            </div>
            <p class="rpb-empty" hidden>Aucun article ne correspond à ces filtres.</p>
            <details class="rpb-coverage"><summary>À propos de cette sélection</summary>
                <p>Cette revue est une sélection, et ne garantit pas l’exhaustivité des publications. Les notices reprennent les informations disponibles dans les flux ou les références vérifiées par la rédaction.</p>
                <?php if ($selected['id'] === '2026-09'): ?><p>Septembre 2026 : sélection initiale partielle de 31 références. Les titres ont été abrégés ; aucun résumé n’a été ajouté aux articles dont seul le référencement a été vérifié.</p><?php endif; ?>
                <?php if ($failed_sources): ?><p>Certains flux n’ont pas pu être actualisés : <?php echo esc_html(implode(', ', array_keys($failed_sources))); ?>.</p><?php endif; ?>
                <?php if ($unchecked): ?><p>Cette version a été générée à partir des références conservées, sans collecte réseau.</p><?php endif; ?>
            </details>
            <script type="application/json" class="rpb-config"><?php echo wp_json_encode(array('themes' => $themes, 'topics' => $topics), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
            <noscript><p>Les articles et le choix de période restent disponibles. Activez JavaScript pour utiliser les filtres et la vue chronologique.</p></noscript>
        </div>
        <?php return ob_get_clean();
    }

    private static function date_label($date) { return wp_date('j F Y', strtotime($date . 'T12:00:00+02:00'), new DateTimeZone('Europe/Paris')); }

    private static function period_label($p) {
        if (preg_match('/^\d{4}-\d{2}$/', $p['id'])) { return wp_date('F Y', strtotime($p['start'] . 'T12:00:00+02:00'), new DateTimeZone('Europe/Paris')); }
        return $p['label'] . ' · ' . self::date_label($p['start']) . ' – ' . self::date_label($p['end']);
    }

    private static function select_filter($uid, $type, $label, $options, $all) { ?>
        <div><label for="<?php echo esc_attr($uid . $type); ?>"><?php echo esc_html($label); ?></label>
        <select id="<?php echo esc_attr($uid . $type); ?>" data-filter="<?php echo esc_attr($type); ?>"><option value=""><?php echo esc_html($all); ?></option>
            <?php foreach ($options as $value => $text): ?><option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($text); ?></option><?php endforeach; ?>
        </select></div>
    <?php }

    private static function card($a, $themes, $topics) { ?>
        <article class="rpb-card" data-theme="<?php echo esc_attr($a['theme']); ?>" data-source="<?php echo esc_attr($a['source_id']); ?>"
            data-places="<?php echo esc_attr(wp_json_encode($a['places'])); ?>" data-day="<?php echo esc_attr($a['publication_date']); ?>"
            data-published="<?php echo esc_attr($a['published_at']); ?>" data-topic="<?php echo esc_attr($a['topic_id'] ?? ''); ?>">
            <p class="rpb-card-meta"><time datetime="<?php echo esc_attr($a['publication_date']); ?>"><?php echo esc_html(self::date_label($a['publication_date'])); ?></time> · <strong><?php echo esc_html($a['source_name']); ?></strong></p>
            <h4><a href="<?php echo esc_url($a['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($a['title']); ?></a></h4>
            <?php if (!empty($a['description'])): ?><p class="rpb-description"><?php echo esc_html($a['description']); ?></p><?php endif; ?>
            <div class="rpb-tags"><span><?php echo esc_html($themes[$a['theme']] ?? 'Vie locale'); ?></span><?php foreach ($a['places'] as $place): ?><span><?php echo esc_html($place); ?></span><?php endforeach; ?></div>
            <a class="rpb-source-link" href="<?php echo esc_url($a['url']); ?>" target="_blank" rel="noopener noreferrer">Lire sur <?php echo esc_html($a['source_name']); ?> <span aria-hidden="true">↗</span><span class="screen-reader-text"> (nouvel onglet)</span></a>
        </article>
    <?php }
}
RPB_Revue_Presse::init();
