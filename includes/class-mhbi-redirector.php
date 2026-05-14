<?php
if (!defined('ABSPATH')) {
    exit;
}

class MHBI_Redirector {
    public function __construct() {
        add_action('template_redirect', array($this, 'maybe_redirect'), 99999);
    }

    public function maybe_redirect() {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }

        $settings = MHBI_Utils::get_settings();
        if (empty($settings['enable_redirects'])) {
            return;
        }

        if (!is_404()) {
            return;
        }

        $path = MHBI_Utils::current_request_path();
        if (!$path) {
            return;
        }

        if (strpos($path, 'sitemap') !== false) {
            return;
        }

        $target = $this->match_special_blogger_paths($path);
        if (!$target) {
            $target = $this->match_imported_post_path($path);
        }

        if ($target) {
            wp_safe_redirect($target, 301);
            exit;
        }

        if (!empty($settings['redirect_404_home'])) {
            wp_safe_redirect(home_url('/'), 301);
            exit;
        }
    }

    private function match_special_blogger_paths($path) {
        if (preg_match('#^/feeds/posts/default(?:/)?$#i', $path) || preg_match('#^/feeds/posts/default/rss$#i', $path)) {
            return get_feed_link('rss2');
        }

        if (preg_match('#^/search/label/([^/]+)$#i', $path, $matches)) {
            $label = sanitize_text_field(str_replace('+', ' ', rawurldecode($matches[1])));
            if ($label !== '') {
                $term = term_exists($label, 'category');
                if (is_array($term) && !empty($term['term_id'])) {
                    return get_term_link((int) $term['term_id'], 'category');
                }

                $slug = sanitize_title($label);
                $term = get_term_by('slug', $slug, 'category');
                if ($term && !is_wp_error($term)) {
                    return get_term_link($term);
                }
            }
        }

        return '';
    }

    private function match_imported_post_path($path) {
        $path    = MHBI_Utils::normalize_old_path($path);
        $post_id = MHBI_Utils::get_redirect_post_id_by_old_path($path);
        if ($post_id) {
            $permalink = get_permalink($post_id);
            if ($permalink) {
                return $permalink;
            }
        }

        $slug = MHBI_Utils::extract_slug_from_path($path);
        if ($slug !== '') {
            $posts = get_posts(
                array(
                    'name'           => $slug,
                    'post_type'      => array('post', 'page'),
                    'post_status'    => 'publish',
                    'fields'         => 'ids',
                    'posts_per_page' => 1,
                    'no_found_rows'  => true,
                )
            );

            if (!empty($posts[0])) {
                return get_permalink((int) $posts[0]);
            }

            $posts = get_posts(
                array(
                    'post_type'      => array('post', 'page'),
                    'post_status'    => 'any',
                    'meta_key'       => '_mhbi_old_slug', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                    'meta_value'     => $slug, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                    'fields'         => 'ids',
                    'posts_per_page' => 1,
                    'no_found_rows'  => true,
                )
            );

            if (!empty($posts[0])) {
                return get_permalink((int) $posts[0]);
            }
        }

        return '';
    }
}
