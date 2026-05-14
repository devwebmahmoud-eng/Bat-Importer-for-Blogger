<?php
if (!defined('ABSPATH')) {
    exit;
}

final class MHBI_Utils {

    public static function sanitize_checkbox($value) {
        return empty($value) ? 0 : 1;
    }

    public static function sanitize_settings($settings) {
        $settings = (array) $settings;

        return array(
            'blog_id'           => isset($settings['blog_id']) ? preg_replace('/\D+/', '', (string) $settings['blog_id']) : '',
            'download_images'   => self::sanitize_checkbox(isset($settings['download_images']) ? $settings['download_images'] : 0),
            'import_pages'      => self::sanitize_checkbox(isset($settings['import_pages']) ? $settings['import_pages'] : 0),
            'enable_redirects'  => self::sanitize_checkbox(isset($settings['enable_redirects']) ? $settings['enable_redirects'] : 0),
            'redirect_404_home' => self::sanitize_checkbox(isset($settings['redirect_404_home']) ? $settings['redirect_404_home'] : 0),
            'batch_size'        => isset($settings['batch_size']) ? max(1, min(50, absint($settings['batch_size']))) : 10,
            'last_blog_title'   => isset($settings['last_blog_title']) ? sanitize_text_field((string) $settings['last_blog_title']) : '',
            'last_blog_url'     => isset($settings['last_blog_url']) ? esc_url_raw((string) $settings['last_blog_url']) : '',
        );
    }

    public static function get_default_settings() {
        return array(
            'blog_id'           => '',
            'download_images'   => 1,
            'import_pages'      => 1,
            'enable_redirects'  => 1,
            'redirect_404_home' => 0,
            'batch_size'        => 10,
            'last_blog_title'   => '',
            'last_blog_url'     => '',
        );
    }

    public static function get_settings() {
        $settings = get_option('mhbi_settings', array());
        $settings = wp_parse_args((array) $settings, self::get_default_settings());

        return self::sanitize_settings($settings);
    }

    public static function update_settings($new_settings) {
        $settings = wp_parse_args((array) $new_settings, self::get_settings());
        $settings = self::sanitize_settings($settings);
        update_option('mhbi_settings', $settings, false);
        

        return $settings;
    }

    public static function normalize_old_path($path) {
        $path = (string) $path;
        if ($path === '') {
            return '/';
        }

        $parsed = wp_parse_url($path);
        if (is_array($parsed) && isset($parsed['path'])) {
            $path = $parsed['path'];
        }

        $path = rawurldecode($path);
        $path = preg_replace('#/+#', '/', $path);
        $path = '/' . ltrim((string) $path, '/');

        if ($path !== '/' && substr($path, -1) === '/' && !preg_match('/\.html$/i', $path)) {
            $path = rtrim($path, '/');
        }

        return $path;
    }

    public static function current_request_path() {
        $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
        $uri = (string) $uri;
        $path = wp_parse_url($uri, PHP_URL_PATH);

        return self::normalize_old_path((string) $path);
    }

    public static function extract_slug_from_path($path) {
        $path = self::normalize_old_path($path);

        if (preg_match('#/p/([^/]+)\.html$#i', $path, $matches)) {
            return sanitize_title($matches[1]);
        }

        if (preg_match('#/\d{4}/\d{2}/([^/]+)\.html$#i', $path, $matches)) {
            return sanitize_title($matches[1]);
        }

        return sanitize_title(basename($path));
    }

    public static function get_allowed_import_html() {
        $allowed = wp_kses_allowed_html('post');

        $allowed['iframe'] = array(
            'src'             => true,
            'title'           => true,
            'width'           => true,
            'height'          => true,
            'loading'         => true,
            'allow'           => true,
            'allowfullscreen' => true,
            'frameborder'     => true,
            'referrerpolicy'  => true,
            'class'           => true,
            'id'              => true,
            'aria-label'      => true,
            'aria-hidden'     => true,
            'tabindex'        => true,
        );

        $allowed['figure'] = isset($allowed['figure']) && is_array($allowed['figure']) ? $allowed['figure'] : array();
        $allowed['figure']['class'] = true;
        $allowed['figure']['id']    = true;

        $allowed['figcaption'] = isset($allowed['figcaption']) && is_array($allowed['figcaption']) ? $allowed['figcaption'] : array();
        $allowed['figcaption']['class'] = true;
        $allowed['figcaption']['id']    = true;

        $allowed['picture'] = array(
            'class' => true,
            'id'    => true,
        );

        $allowed['source'] = array(
            'src'    => true,
            'srcset' => true,
            'sizes'  => true,
            'type'   => true,
            'media'  => true,
            'width'  => true,
            'height' => true,
        );

        $allowed['table'] = isset($allowed['table']) && is_array($allowed['table']) ? $allowed['table'] : array();
        $allowed['table']['class'] = true;
        $allowed['table']['id']    = true;

        $allowed['thead']    = isset($allowed['thead']) && is_array($allowed['thead']) ? $allowed['thead'] : array();
        $allowed['tbody']    = isset($allowed['tbody']) && is_array($allowed['tbody']) ? $allowed['tbody'] : array();
        $allowed['tfoot']    = isset($allowed['tfoot']) && is_array($allowed['tfoot']) ? $allowed['tfoot'] : array();
        $allowed['tr']       = isset($allowed['tr']) && is_array($allowed['tr']) ? $allowed['tr'] : array();
        $allowed['td']       = isset($allowed['td']) && is_array($allowed['td']) ? $allowed['td'] : array();
        $allowed['th']       = isset($allowed['th']) && is_array($allowed['th']) ? $allowed['th'] : array();
        $allowed['colgroup'] = isset($allowed['colgroup']) && is_array($allowed['colgroup']) ? $allowed['colgroup'] : array();
        $allowed['col']      = isset($allowed['col']) && is_array($allowed['col']) ? $allowed['col'] : array();

        $allowed['td']['colspan'] = true;
        $allowed['td']['rowspan'] = true;
        $allowed['td']['scope']   = true;
        $allowed['td']['abbr']    = true;
        $allowed['th']['colspan'] = true;
        $allowed['th']['rowspan'] = true;
        $allowed['th']['scope']   = true;
        $allowed['th']['abbr']    = true;
        $allowed['col']['span']   = true;
        $allowed['col']['width']  = true;

        return apply_filters('mhbi_allowed_import_html', $allowed);
    }

    public static function sanitize_imported_html($html) {
        $html = (string) $html;
        if ($html === '') {
            return '';
        }

        $sanitized = wp_kses($html, self::get_allowed_import_html());
        $sanitized = self::strip_imported_data_attributes($sanitized);

        return force_balance_tags($sanitized);
    }

    private static function strip_imported_data_attributes($html) {
        $html = (string) $html;
        if ($html === '') {
            return '';
        }

        if (class_exists('DOMDocument')) {
            $previous = libxml_use_internal_errors(true);
            $dom      = new DOMDocument();
            $loaded   = $dom->loadHTML(
                '<?xml encoding="utf-8" ?><body>' . $html . '</body>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
            );
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            if ($loaded) {
                $body = $dom->getElementsByTagName('body')->item(0);

                if ($body instanceof DOMNode) {
                    $elements = $body->getElementsByTagName('*');
                    $spans    = array();

                    foreach ($elements as $element) {
                        if (!($element instanceof DOMElement)) {
                            continue;
                        }

                        if ('span' === strtolower($element->tagName)) {
                            $spans[] = $element;
                        }

                        if (!$element->hasAttributes()) {
                            continue;
                        }

                        $attributes_to_remove = array();

                        foreach ($element->attributes as $attribute) {
                            if ($attribute instanceof DOMAttr && 0 === strpos(strtolower($attribute->name), 'data-')) {
                                $attributes_to_remove[] = $attribute->name;
                            }
                        }

                        foreach ($attributes_to_remove as $attribute_name) {
                            $element->removeAttribute($attribute_name);
                        }
                    }

                    foreach ($spans as $span) {
                        if (!($span instanceof DOMElement) || $span->parentNode === null || $span->hasAttributes()) {
                            continue;
                        }

                        while ($span->firstChild) {
                            $span->parentNode->insertBefore($span->firstChild, $span);
                        }

                        $span->parentNode->removeChild($span);
                    }

                    $clean_html = '';
                    foreach ($body->childNodes as $child_node) {
                        $clean_html .= $dom->saveHTML($child_node);
                    }

                    if ($clean_html !== '') {
                        return $clean_html;
                    }
                }
            }
        }

        $html = (string) preg_replace(
            "/\sdata-[a-zA-Z0-9_:-]+(?:\s*=\s*(?:\"[^\"]*\"|'[^']*'|[^\s>]+))?/u",
            '',
            $html
        );

        return (string) preg_replace('/<span>(.*?)<\/span>/uis', '$1', $html);
    }

    public static function extract_blogger_post_id($entry_id) {
        $entry_id = (string) $entry_id;
        if (preg_match('/post-(\d+)/', $entry_id, $matches)) {
            return $matches[1];
        }

        return '';
    }

    public static function get_redirect_table_name() {
        global $wpdb;

        return $wpdb->prefix . 'mhbi_redirects';
    }

    public static function get_redirect_cache_group() {
        return 'mhbi_redirects';
    }

    public static function get_redirect_cache_version() {
        $version = get_option('mhbi_redirect_cache_version', '1');

        return is_scalar($version) ? (string) $version : '1';
    }

    public static function bump_redirect_cache_version() {
        $version = sprintf('%.6F', microtime(true));
        update_option('mhbi_redirect_cache_version', $version, false);
        

        return $version;
    }

    private static function get_redirect_cache_key($suffix) {
        return 'v' . self::get_redirect_cache_version() . ':' . sanitize_key((string) $suffix);
    }

    public static function redirect_table_exists() {
        global $wpdb;

        $cache_key = self::get_redirect_cache_key('table_exists');
        $cached    = wp_cache_get($cache_key, self::get_redirect_cache_group());

        if (false !== $cached) {
            return (bool) $cached;
        }

        $table_name   = self::get_redirect_table_name();
        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading custom plugin table state.
        $exists       = ($table_exists === $table_name);

        wp_cache_set($cache_key, $exists ? 1 : 0, self::get_redirect_cache_group(), MINUTE_IN_SECONDS);

        return $exists;
    }

    public static function get_redirect_stats($limit = 20) {
        global $wpdb;

        $limit     = max(1, min(100, absint($limit)));
        $cache_key = self::get_redirect_cache_key('stats_' . $limit);
        $cached    = wp_cache_get($cache_key, self::get_redirect_cache_group());

        if (is_array($cached)) {
            return $cached;
        }

        $stats = array(
            'count'  => 0,
            'recent' => array(),
        );

        if (!self::redirect_table_exists()) {
            wp_cache_set($cache_key, $stats, self::get_redirect_cache_group(), MINUTE_IN_SECONDS);
            return $stats;
        }

        $table_name = self::get_redirect_table_name();

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading custom plugin table statistics.
        $redirect_count = $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM %i', $table_name)
        );
        $recent_rows    = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT old_path, post_id, old_host, updated_at FROM %i ORDER BY updated_at DESC, id DESC LIMIT %d',
                $table_name,
                $limit
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        $stats['count']  = (int) $redirect_count;
        $stats['recent'] = is_array($recent_rows) ? $recent_rows : array();

        wp_cache_set($cache_key, $stats, self::get_redirect_cache_group(), MINUTE_IN_SECONDS);

        return $stats;
    }

    public static function get_redirect_post_id_by_old_path($path) {
        global $wpdb;

        $path = self::normalize_old_path($path);
        if ($path === '') {
            return 0;
        }

        $cache_key = self::get_redirect_cache_key('path_' . md5($path));
        $cached    = wp_cache_get($cache_key, self::get_redirect_cache_group());

        if (false !== $cached) {
            return (int) $cached;
        }

        if (!self::redirect_table_exists()) {
            wp_cache_set($cache_key, 0, self::get_redirect_cache_group(), MINUTE_IN_SECONDS);
            return 0;
        }

        $table_name = self::get_redirect_table_name();

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading custom plugin table redirect mapping.
        $post_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT post_id FROM %i WHERE old_path = %s LIMIT 1',
                $table_name,
                $path
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        wp_cache_set($cache_key, $post_id, self::get_redirect_cache_group(), MINUTE_IN_SECONDS);

        return $post_id;
    }

    public static function maybe_get_array_value($array, $key, $default = '') {
        return isset($array[$key]) ? $array[$key] : $default;
    }
}
