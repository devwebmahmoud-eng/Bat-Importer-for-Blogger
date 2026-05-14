<?php
if (!defined('ABSPATH')) {
    exit;
}

class MHBI_Importer {
    /** @var MHBI_Feed_Client */
    private $feed_client;

    public function __construct(MHBI_Feed_Client $feed_client) {
        $this->feed_client = $feed_client;
    }

    private function get_untitled_label() {
        return __('(Untitled)', 'bat-importer-for-blogger');
    }

    private function get_phase_label($phase) {
        return 'pages' === $phase
            ? __('pages', 'bat-importer-for-blogger')
            : __('posts', 'bat-importer-for-blogger');
    }

    private function get_entry_type_label($type) {
        return 'page' === $type
            ? __('page', 'bat-importer-for-blogger')
            : __('post', 'bat-importer-for-blogger');
    }

    private function get_action_label($action) {
        if ('updated' === $action) {
            return __('Updated', 'bat-importer-for-blogger');
        }

        return __('Imported', 'bat-importer-for-blogger');
    }

    private function get_job() {
        return (array) get_option('mhbi_import_job', array());
    }

    private function save_job(array $job) {
        update_option('mhbi_import_job', $job, false);
        
    }

    private function delete_job() {
        delete_option('mhbi_import_job');
        
    }

    public function ajax_start_import() {
        $this->guard_ajax();

        $request  = $this->get_start_import_request_data();
        $blog_id  = $request['blog_id'];

        if ($blog_id === '') {
            wp_send_json_error(array('message' => __('Please enter a valid numeric Blogger Blog ID.', 'bat-importer-for-blogger')), 400);
        }

        $snapshot = $this->feed_client->fetch_blog_snapshot($blog_id);
        if (is_wp_error($snapshot)) {
            wp_send_json_error(array('message' => $snapshot->get_error_message()), 400);
        }

        $settings = MHBI_Utils::update_settings(
            array(
                'blog_id'           => $blog_id,
                'download_images'   => $request['download_images'],
                'import_pages'      => $request['import_pages'],
                'enable_redirects'  => $request['enable_redirects'],
                'redirect_404_home' => $request['redirect_404_home'],
                'batch_size'        => $request['batch_size'],
                'last_blog_title'   => MHBI_Utils::maybe_get_array_value($snapshot, 'title'),
                'last_blog_url'     => MHBI_Utils::maybe_get_array_value($snapshot, 'site_url'),
            )
        );

        $job = array(
            'blog_id'            => $blog_id,
            'phase'              => 'posts',
            'current_index'      => 1,
            'processed'          => 0,
            'imported'           => 0,
            'updated'            => 0,
            'skipped'            => 0,
            'errors'             => 0,
            'complete'           => false,
            'stopped'            => false,
            'last_message'       => '',
            'started_at'         => current_time('mysql'),
            'blog_title'         => MHBI_Utils::maybe_get_array_value($snapshot, 'title'),
            'blog_url'           => MHBI_Utils::maybe_get_array_value($snapshot, 'site_url'),
            'total_posts'        => (int) MHBI_Utils::maybe_get_array_value($snapshot, 'total_posts', 0),
            'batch_size'         => (int) $settings['batch_size'],
            'download_images'    => (int) $settings['download_images'],
            'import_pages'       => (int) $settings['import_pages'],
        );

        $this->save_job($job);

        wp_send_json_success(
            array(
                'message' => __('Import prepared successfully.', 'bat-importer-for-blogger'),
                'job'     => $job,
            )
        );
    }

    public function ajax_process_batch() {
        $this->guard_ajax();

        $job = $this->get_job();
        if (empty($job) || empty($job['blog_id'])) {
            wp_send_json_error(array('message' => __('No active import job found.', 'bat-importer-for-blogger')), 400);
        }

        if (!empty($job['complete'])) {
            wp_send_json_success(array('message' => __('Import already completed.', 'bat-importer-for-blogger'), 'job' => $job, 'logs' => array()));
        }

        if (!empty($job['stopped'])) {
            $job['last_message'] = __('Import stopped by user.', 'bat-importer-for-blogger');
            $this->save_job($job);

            wp_send_json_success(array('message' => $job['last_message'], 'job' => $job, 'logs' => array()));
        }

        $logs       = array();
        $batch_size = max(1, min(50, (int) MHBI_Utils::maybe_get_array_value($job, 'batch_size', 10)));
        $phase      = MHBI_Utils::maybe_get_array_value($job, 'phase', 'posts');

        $feed = $this->feed_client->fetch_feed_page($job['blog_id'], $phase, (int) $job['current_index'], $batch_size);
        if (is_wp_error($feed)) {
            $job['errors']       = (int) $job['errors'] + 1;
            $job['last_message'] = $feed->get_error_message();
            $this->save_job($job);

            wp_send_json_error(array('message' => $feed->get_error_message(), 'job' => $job), 400);
        }

        $entries = isset($feed['entries']) && is_array($feed['entries']) ? $feed['entries'] : array();

        if (empty($entries)) {
            if ($phase === 'posts' && !empty($job['import_pages'])) {
                $job['phase']         = 'pages';
                $job['current_index'] = 1;
                $job['last_message']  = __('Posts finished. Switching to pages.', 'bat-importer-for-blogger');
                $this->save_job($job);

                wp_send_json_success(array('message' => $job['last_message'], 'job' => $job, 'logs' => array()));
            }

            $job['complete']     = true;
            $job['last_message'] = __('Import completed successfully.', 'bat-importer-for-blogger');
            $this->save_job($job);

            wp_send_json_success(array('message' => $job['last_message'], 'job' => $job, 'logs' => array()));
        }

        foreach ($entries as $entry) {
            $result = $this->import_entry($entry, $job);
            $job['processed'] = (int) $job['processed'] + 1;
            $logs[]           = $result['message'];

            if ($result['status'] === 'imported') {
                $job['imported'] = (int) $job['imported'] + 1;
            } elseif ($result['status'] === 'updated') {
                $job['updated'] = (int) $job['updated'] + 1;
            } elseif ($result['status'] === 'skipped') {
                $job['skipped'] = (int) $job['skipped'] + 1;
            } else {
                $job['errors'] = (int) $job['errors'] + 1;
            }
        }

        $job['current_index'] = (int) $job['current_index'] + count($entries);
        $job['last_message']  = sprintf(
            /* translators: 1: number of items in last batch, 2: current phase. */
            _n(
                'Processed %1$d item from the %2$s feed.',
                'Processed %1$d items from the %2$s feed.',
                count($entries),
                'bat-importer-for-blogger'
            ),
            count($entries),
            $this->get_phase_label($phase)
        );

        if (count($entries) < $batch_size) {
            if ($phase === 'posts' && !empty($job['import_pages'])) {
                $job['phase']         = 'pages';
                $job['current_index'] = 1;
            } else {
                $job['complete'] = true;
            }
        }

        $this->save_job($job);

        wp_send_json_success(array('message' => $job['last_message'], 'job' => $job, 'logs' => $logs));
    }

    public function ajax_reset_import() {
        $this->guard_ajax();
        $this->delete_job();
        wp_send_json_success(array('message' => __('Import state cleared.', 'bat-importer-for-blogger')));
    }

    public function ajax_stop_import() {
        $this->guard_ajax();

        $job = $this->get_job();
        if (empty($job) || empty($job['blog_id'])) {
            wp_send_json_error(array('message' => __('No active import job found.', 'bat-importer-for-blogger')), 400);
        }

        $job['stopped']      = true;
        $job['last_message'] = __('Import stopped by user.', 'bat-importer-for-blogger');
        $this->save_job($job);

        wp_send_json_success(array('message' => $job['last_message'], 'job' => $job));
    }

    public function ajax_full_reset() {
        $this->guard_ajax();
        $this->reset_plugin_data();

        wp_send_json_success(array('message' => __('Plugin data reset completed.', 'bat-importer-for-blogger')));
    }

    private function reset_plugin_data() {
        global $wpdb;

        $this->delete_job();
        update_option('mhbi_settings', MHBI_Utils::get_default_settings(), false);
        

        $table_name = MHBI_Utils::get_redirect_table_name();

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Clearing custom plugin table during full reset.
        $wpdb->query(
            $wpdb->prepare('DELETE FROM %i', $table_name)
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        MHBI_Utils::bump_redirect_cache_version();

        $meta_keys = array(
            '_mhbi_blog_id',
            '_mhbi_blogger_post_id',
            '_mhbi_old_permalink',
            '_mhbi_old_path',
            '_mhbi_old_slug',
            '_mhbi_original_author',
            '_mhbi_source_image',
        );

        foreach ($meta_keys as $meta_key) {
            delete_metadata('post', 0, $meta_key, '', true);
        }
    }

    private function get_start_import_request_data() {
        $raw_post = wp_unslash($_POST); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified in self::guard_ajax().
        $raw_post = is_array($raw_post) ? $raw_post : array();

        return array(
            'blog_id'           => isset($raw_post['blog_id']) ? preg_replace('/\D+/', '', (string) $raw_post['blog_id']) : '',
            'download_images'   => MHBI_Utils::sanitize_checkbox($raw_post['download_images'] ?? 0),
            'import_pages'      => MHBI_Utils::sanitize_checkbox($raw_post['import_pages'] ?? 0),
            'enable_redirects'  => MHBI_Utils::sanitize_checkbox($raw_post['enable_redirects'] ?? 0),
            'redirect_404_home' => MHBI_Utils::sanitize_checkbox($raw_post['redirect_404_home'] ?? 0),
            'batch_size'        => isset($raw_post['batch_size']) ? max(1, min(50, absint($raw_post['batch_size']))) : 10,
        );
    }

    private function guard_ajax() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You are not allowed to do this.', 'bat-importer-for-blogger')), 403);
        }

        if (!check_ajax_referer('mhbi_import_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed.', 'bat-importer-for-blogger')), 403);
        }
    }

    private function import_entry($entry, $job) {
        $type        = $entry['type'] === 'page' ? 'page' : 'post';
        $existing_id = $this->find_existing_post($entry);
        $post_status = 'publish';
        $post_date   = $this->normalize_mysql_date($entry['published']);
        $content     = MHBI_Utils::sanitize_imported_html((string) $entry['content']);

        $post_categories = $type === 'post' ? $this->resolve_post_categories($entry['labels']) : array();

        $postarr = array(
            'post_type'     => $type,
            'post_status'   => $post_status,
            'post_title'    => $entry['title'] !== '' ? $entry['title'] : $this->get_untitled_label(),
            'post_content'  => $content,
            'post_date'     => $post_date,
            'post_category' => $post_categories,
        );

        if ($existing_id) {
            $postarr['ID'] = $existing_id;
            $post_id       = wp_update_post(wp_slash($postarr), true);
            $action        = 'updated';
        } else {
            $post_id = wp_insert_post(wp_slash($postarr), true);
            $action  = 'imported';
        }

        if (is_wp_error($post_id)) {
            return array(
                'status'  => 'error',
                'message' => sprintf(
                    /* translators: 1: post title, 2: error message. */
                    __('Failed to import "%1$s": %2$s', 'bat-importer-for-blogger'),
                    $entry['title'] !== '' ? $entry['title'] : $this->get_untitled_label(),
                    $post_id->get_error_message()
                ),
            );
        }

        update_post_meta($post_id, '_mhbi_blog_id', sanitize_text_field($job['blog_id']));
        update_post_meta($post_id, '_mhbi_blogger_post_id', sanitize_text_field($entry['blogger_post_id']));
        update_post_meta($post_id, '_mhbi_old_permalink', esc_url_raw($entry['old_url']));
        update_post_meta($post_id, '_mhbi_old_path', MHBI_Utils::normalize_old_path($entry['old_path']));
        update_post_meta($post_id, '_mhbi_old_slug', sanitize_title($entry['old_slug']));
        update_post_meta($post_id, '_mhbi_original_author', sanitize_text_field($entry['author']));

        if ($type === 'post') {
            wp_set_post_terms($post_id, array(), 'post_tag', false);

            $target_categories = $post_categories;
            if (empty($target_categories)) {
                $default_category = absint(get_option('default_category'));
                if ($default_category > 0) {
                    $target_categories = array($default_category);
                }
            }

            wp_set_post_categories($post_id, array_values(array_unique(array_filter(array_map('absint', $target_categories)))), false);
        }

        if (!empty($job['download_images'])) {
            $processed = $this->download_and_replace_images($post_id, $content, $post_date);
            if ($processed['content'] !== $content) {
                $sanitized_processed_content = MHBI_Utils::sanitize_imported_html((string) $processed['content']);

                wp_update_post(
                    array(
                        'ID'           => $post_id,
                        'post_content' => wp_slash($sanitized_processed_content),
                    )
                );
            }
        }

        if (!empty($entry['old_path'])) {
            $this->store_redirect_mapping($post_id, $entry['old_path'], $entry['old_url']);
        }

        return array(
            'status'  => $action,
            'message' => sprintf(
                /* translators: 1: action, 2: type, 3: title */
                __('%1$s %2$s: %3$s', 'bat-importer-for-blogger'),
                $this->get_action_label($action),
                $this->get_entry_type_label($type),
                $entry['title'] !== '' ? $entry['title'] : $this->get_untitled_label()
            ),
        );
    }


    private function resolve_post_categories($labels) {
        $labels = is_array($labels) ? $labels : array();
        $category_ids = array();

        foreach ($labels as $label) {
            $label = sanitize_text_field((string) $label);
            if ($label === '') {
                continue;
            }

            $existing_term = term_exists($label, 'category');
            if (is_array($existing_term) && !empty($existing_term['term_id'])) {
                $category_ids[] = (int) $existing_term['term_id'];
                continue;
            }

            $slug = sanitize_title($label);
            if ($slug !== '') {
                $existing_by_slug = get_term_by('slug', $slug, 'category');
                if ($existing_by_slug && !is_wp_error($existing_by_slug)) {
                    $category_ids[] = (int) $existing_by_slug->term_id;
                    continue;
                }
            }

            $created_term = wp_insert_term($label, 'category');
            if (is_wp_error($created_term)) {
                continue;
            }

            if (is_array($created_term) && !empty($created_term['term_id'])) {
                $category_ids[] = (int) $created_term['term_id'];
            }
        }

        $category_ids = array_values(array_unique(array_filter(array_map('absint', $category_ids))));

        return $category_ids;
    }

    private function find_existing_post($entry) {
        $blogger_post_id = sanitize_text_field($entry['blogger_post_id']);
        if ($blogger_post_id !== '') {
            $posts = get_posts(
                array(
                    'post_type'      => array('post', 'page'),
                    'post_status'    => 'any',
                    'meta_key'       => '_mhbi_blogger_post_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                    'meta_value'     => $blogger_post_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                    'fields'         => 'ids',
                    'posts_per_page' => 1,
                    'no_found_rows'  => true,
                )
            );

            if (!empty($posts[0])) {
                return (int) $posts[0];
            }
        }

        $old_path = MHBI_Utils::normalize_old_path($entry['old_path']);
        if ($old_path !== '') {
            $posts = get_posts(
                array(
                    'post_type'      => array('post', 'page'),
                    'post_status'    => 'any',
                    'meta_key'       => '_mhbi_old_path', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                    'meta_value'     => $old_path, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                    'fields'         => 'ids',
                    'posts_per_page' => 1,
                    'no_found_rows'  => true,
                )
            );

            if (!empty($posts[0])) {
                return (int) $posts[0];
            }
        }

        return 0;
    }

    private function normalize_mysql_date($date_string) {
        $timestamp = strtotime((string) $date_string);
        if (!$timestamp) {
            $timestamp = current_time('timestamp');
        }

        return gmdate('Y-m-d H:i:s', $timestamp + (get_option('gmt_offset') * HOUR_IN_SECONDS));
    }

    private function store_redirect_mapping($post_id, $old_path, $old_url = '') {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mhbi_redirects';
        $old_path   = MHBI_Utils::normalize_old_path($old_path);
        $host       = '';

        if ($old_url) {
            $host = (string) wp_parse_url($old_url, PHP_URL_HOST);
            $host = strtolower(preg_replace('/^www\./i', '', $host));
        }

        $wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $table_name,
            array(
                'old_path'    => $old_path,
                'post_id'     => (int) $post_id,
                'old_host'    => sanitize_text_field($host),
                'created_at'  => current_time('mysql'),
                'updated_at'  => current_time('mysql'),
            ),
            array('%s', '%d', '%s', '%s', '%s')
        );

        MHBI_Utils::bump_redirect_cache_version();
    }

    private function download_and_replace_images($post_id, $content, $post_date) {
        if ($content === '') {
            return array('content' => $content);
        }

        if (class_exists('DOMDocument')) {
            $dom_processed = $this->download_and_replace_images_with_dom($post_id, $content, $post_date);
            if (is_array($dom_processed)) {
                return $dom_processed;
            }
        }

        preg_match_all('#https?://[^\s"\'<>)]*(?:blogspot|googleusercontent|photos\d*\.blogger\.com)[^\s"\'<>)]*#i', $content, $matches);
        $urls = array_values(array_unique(array_filter(isset($matches[0]) ? $matches[0] : array())));

        if (empty($urls)) {
            return array('content' => $content);
        }

        $existing_thumbnail_id   = get_post_thumbnail_id($post_id);
        $first_image_id          = $existing_thumbnail_id;
        $featured_source_url     = '';
        $featured_original_match = '';

        foreach ($urls as $index => $url) {
            $normalized_url = esc_url_raw(html_entity_decode($url, ENT_QUOTES, 'UTF-8'));
            if ($normalized_url === '' || !$this->is_supported_blogger_image_url($normalized_url)) {
                continue;
            }

            $attachment_id = $this->get_or_sideload_attachment_for_image($normalized_url, $post_id, $post_date);
            if (!$attachment_id) {
                continue;
            }

            $new_url = wp_get_attachment_url($attachment_id);
            if ($new_url) {
                $content = str_replace($url, $new_url, $content);
                if (!$first_image_id) {
                    $first_image_id = $attachment_id;
                }

                if (!$existing_thumbnail_id && 0 === $index) {
                    $featured_source_url     = $normalized_url;
                    $featured_original_match = $url;
                }
            }
        }

        if ($first_image_id && !$existing_thumbnail_id) {
            set_post_thumbnail($post_id, $first_image_id);
            $existing_thumbnail_id = $first_image_id;
        }

        if ($existing_thumbnail_id) {
            $content = $this->remove_featured_image_from_content($content, $existing_thumbnail_id, $featured_source_url, $featured_original_match);
        }

        return array('content' => $content);
    }

    private function download_and_replace_images_with_dom($post_id, $content, $post_date) {
        $wrapped = '<!DOCTYPE html><html><body>' . $content . '</body></html>';
        $dom     = new DOMDocument();

        $previous = libxml_use_internal_errors(true);
        $loaded   = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return null;
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            return null;
        }

        $images = array();
        foreach ($body->getElementsByTagName('img') as $image_node) {
            $images[] = $image_node;
        }

        if (empty($images)) {
            return array('content' => $content);
        }

        $existing_thumbnail_id  = get_post_thumbnail_id($post_id);
        $featured_attachment_id = $existing_thumbnail_id ? (int) $existing_thumbnail_id : 0;
        $featured_source_url    = '';
        $featured_original_match = '';

        foreach ($images as $image_node) {
            if (!$image_node || !$image_node->parentNode) {
                continue;
            }

            $display_source_url = $this->get_primary_image_source_from_node($image_node);
            if ($display_source_url === '' || !$this->is_supported_blogger_image_url($display_source_url)) {
                continue;
            }

            $download_source_url = $this->get_preferred_download_source_from_node($image_node, $display_source_url);
            $attachment_id       = $this->get_or_sideload_attachment_for_image($download_source_url, $post_id, $post_date, array($display_source_url));
            if (!$attachment_id) {
                continue;
            }

            $new_url = wp_get_attachment_url($attachment_id);
            if (!$new_url) {
                continue;
            }

            $this->replace_image_node_urls($image_node, $display_source_url, $new_url);
            $this->replace_parent_anchor_image_url($image_node, $new_url);

            if (!$featured_attachment_id) {
                $featured_attachment_id  = (int) $attachment_id;
                $featured_source_url     = $display_source_url;
                $featured_original_match = $display_source_url;
            }
        }

        if ($featured_attachment_id && !$existing_thumbnail_id) {
            set_post_thumbnail($post_id, $featured_attachment_id);
            $existing_thumbnail_id = $featured_attachment_id;
        }

        $html = $this->get_inner_html($body);
        if ($existing_thumbnail_id) {
            $html = $this->remove_featured_image_from_content($html, $existing_thumbnail_id, $featured_source_url, $featured_original_match);
        }

        return array('content' => $html);
    }

    private function get_primary_image_source_from_node($image_node) {
        if (!$image_node || $image_node->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }

        $attributes = array('src', 'data-src', 'data-lazy-src', 'data-original');
        foreach ($attributes as $attribute) {
            $value = $this->normalize_image_attribute_url($image_node->getAttribute($attribute));
            if ($value !== '') {
                return $value;
            }
        }

        $set_attributes = array('srcset', 'data-srcset');
        foreach ($set_attributes as $attribute) {
            $value = $this->extract_first_url_from_srcset($image_node->getAttribute($attribute));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function get_preferred_download_source_from_node($image_node, $fallback_url) {
        $parent = $image_node ? $image_node->parentNode : null;
        if ($parent && $parent->nodeType === XML_ELEMENT_NODE && strtolower($parent->nodeName) === 'a') {
            $href = $this->normalize_image_attribute_url($parent->getAttribute('href'));
            if ($href !== '' && $this->is_supported_blogger_image_url($href)) {
                return $href;
            }
        }

        return $fallback_url;
    }

    private function replace_image_node_urls($image_node, $old_url, $new_url) {
        if (!$image_node || !$new_url) {
            return;
        }

        $simple_attributes = array('src', 'data-src', 'data-lazy-src', 'data-original');
        foreach ($simple_attributes as $attribute) {
            $value = $this->normalize_image_attribute_url($image_node->getAttribute($attribute));
            if ($value !== '' && $this->image_urls_are_equivalent($value, $old_url)) {
                $image_node->setAttribute($attribute, $new_url);
            }
        }

        $set_attributes = array('srcset', 'data-srcset');
        foreach ($set_attributes as $attribute) {
            if ($image_node->hasAttribute($attribute)) {
                $image_node->removeAttribute($attribute);
            }
        }
    }

    private function replace_parent_anchor_image_url($image_node, $new_url) {
        if (!$image_node || !$new_url) {
            return;
        }

        $parent = $image_node->parentNode;
        if ($parent && $parent->nodeType === XML_ELEMENT_NODE && strtolower($parent->nodeName) === 'a') {
            $href = $this->normalize_image_attribute_url($parent->getAttribute('href'));
            if ($href !== '' && $this->is_supported_blogger_image_url($href)) {
                $parent->setAttribute('href', $new_url);
            }
        }
    }

    private function normalize_image_attribute_url($value) {
        $value = html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return esc_url_raw($value);
    }

    private function extract_first_url_from_srcset($value) {
        $value = html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $parts = preg_split('/\s*,\s*/', $value);
        if (empty($parts[0])) {
            return '';
        }

        $first = trim((string) $parts[0]);
        $first = preg_replace('/\s+\d+[wx]?$/i', '', $first);

        return esc_url_raw($first);
    }

    private function image_urls_are_equivalent($left, $right) {
        $left  = $this->normalize_image_url_for_compare($left);
        $right = $this->normalize_image_url_for_compare($right);

        if ($left === '' || $right === '') {
            return false;
        }

        return $left === $right || strpos($left, $right) !== false || strpos($right, $left) !== false;
    }

    private function is_supported_blogger_image_url($url) {
        return (bool) preg_match('#^https?://[^\s"\'<>)]*(?:blogspot|googleusercontent|photos\d*\.blogger\.com)[^\s"\'<>)]*$#i', (string) $url);
    }

    private function get_or_sideload_attachment_for_image($normalized_url, $post_id, $post_date, $extra_source_urls = array()) {
        $normalized_url = esc_url_raw(html_entity_decode((string) $normalized_url, ENT_QUOTES, 'UTF-8'));
        if ($normalized_url === '') {
            return 0;
        }

        $cached_id = $this->find_existing_attachment_by_source_url($normalized_url);
        if ($cached_id) {
            foreach ((array) $extra_source_urls as $extra_source_url) {
                $extra_source_url = esc_url_raw(html_entity_decode((string) $extra_source_url, ENT_QUOTES, 'UTF-8'));
                if ($extra_source_url !== '') {
                    add_post_meta($cached_id, '_mhbi_source_image', $extra_source_url, false);
                }
            }
            return (int) $cached_id;
        }

        $tmp = download_url($normalized_url);
        if (is_wp_error($tmp)) {
            return 0;
        }

        $path      = (string) wp_parse_url($normalized_url, PHP_URL_PATH);
        $basename  = basename($path);
        $basename  = preg_replace('/[?#].*$/', '', $basename);
        $basename  = sanitize_file_name($basename);

        if ($basename === '' || strpos($basename, '.') === false) {
            $basename = 'blogger-image-' . wp_generate_password(10, false) . '.jpg';
        }

        $file = array(
            'name'     => $basename,
            'tmp_name' => $tmp,
        );

        $attachment_id = media_handle_sideload($file, $post_id, '', array('post_date' => $post_date));
        if (is_wp_error($attachment_id)) {
            wp_delete_file($tmp);
            return 0;
        }

        add_post_meta($attachment_id, '_mhbi_source_image', $normalized_url, false);
        foreach ((array) $extra_source_urls as $extra_source_url) {
            $extra_source_url = esc_url_raw(html_entity_decode((string) $extra_source_url, ENT_QUOTES, 'UTF-8'));
            if ($extra_source_url !== '') {
                add_post_meta($attachment_id, '_mhbi_source_image', $extra_source_url, false);
            }
        }

        return (int) $attachment_id;
    }

    private function remove_featured_image_from_content($content, $attachment_id, $source_url = '', $original_match = '') {
        if ($content === '' || !$attachment_id) {
            return $content;
        }

        $featured_variants = $this->build_attachment_image_variants($attachment_id, $source_url, $original_match);
        if (empty($featured_variants)) {
            return $content;
        }

        if (!class_exists('DOMDocument')) {
            return $this->remove_featured_image_with_regex($content, $featured_variants);
        }

        $wrapped = '<!DOCTYPE html><html><body>' . $content . '</body></html>';
        $dom     = new DOMDocument();

        $previous = libxml_use_internal_errors(true);
        $loaded   = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return $this->remove_featured_image_with_regex($content, $featured_variants);
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            return $this->remove_featured_image_with_regex($content, $featured_variants);
        }

        $images         = $body->getElementsByTagName('img');
        $matching_nodes = array();

        foreach ($images as $image) {
            if ($this->image_node_matches_variants($image, $featured_variants)) {
                $matching_nodes[] = $image;
            }
        }

        if (empty($matching_nodes)) {
            return $this->remove_featured_image_with_regex($content, $featured_variants);
        }

        foreach ($matching_nodes as $matching_image) {
            if (!$matching_image->parentNode) {
                continue;
            }

            $caption_candidates = $this->get_image_caption_candidates($matching_image);
            $cleanup_start      = $matching_image->parentNode;
            $removable_node     = $this->find_removable_featured_image_container($matching_image, $featured_variants, $caption_candidates, $body);

            if ($removable_node && $removable_node->parentNode) {
                $parent       = $removable_node->parentNode;
                $caption_node = $this->find_adjacent_caption_node($removable_node, $caption_candidates);

                $parent->removeChild($removable_node);
                if ($caption_node && $caption_node->parentNode === $parent) {
                    $parent->removeChild($caption_node);
                }

                $this->cleanup_empty_ancestor_nodes($parent, $body);
                continue;
            }

            $caption_node = $this->find_adjacent_caption_node($matching_image, $caption_candidates);
            $this->remove_matching_image_node($matching_image);

            if ($caption_node && $caption_node->parentNode) {
                $caption_parent = $caption_node->parentNode;
                $caption_parent->removeChild($caption_node);
                $this->cleanup_empty_ancestor_nodes($caption_parent, $body);
            }

            if ($cleanup_start) {
                $this->cleanup_empty_ancestor_nodes($cleanup_start, $body);
            }
        }

        return $this->cleanup_leading_empty_markup($this->get_inner_html($body));
    }

    private function get_image_caption_candidates($image_node) {
        $candidates = array();

        if (!$image_node || $image_node->nodeType !== XML_ELEMENT_NODE) {
            return $candidates;
        }

        foreach (array('alt', 'title', 'aria-label', 'data-caption') as $attribute) {
            $value = $this->normalize_caption_text($image_node->getAttribute($attribute));
            if ($value !== '') {
                $candidates[] = $value;
            }
        }

        $parent = $image_node->parentNode;
        if ($parent && $parent->nodeType === XML_ELEMENT_NODE && strtolower($parent->nodeName) === 'a') {
            foreach (array('title', 'aria-label', 'data-caption') as $attribute) {
                $value = $this->normalize_caption_text($parent->getAttribute($attribute));
                if ($value !== '') {
                    $candidates[] = $value;
                }
            }
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    private function find_removable_featured_image_container($image_node, $featured_variants, $caption_candidates, $stop_node) {
        $removable = null;
        $current   = $image_node;
        $depth     = 0;

        while ($current && $current !== $stop_node && $depth < 4) {
            if ($current->nodeType === XML_ELEMENT_NODE) {
                if ($this->node_contains_only_matching_image($current, $featured_variants) || $this->node_contains_only_matching_image_and_caption($current, $featured_variants, $caption_candidates)) {
                    $removable = $current;
                } elseif ($removable) {
                    break;
                }
            }

            $current = $current->parentNode;
            $depth++;
        }

        return $removable;
    }

    private function find_adjacent_caption_node($node, $caption_candidates = array()) {
        if (!$node) {
            return null;
        }

        $candidate = $node->nextSibling;
        while ($candidate) {
            if ($candidate->nodeType === XML_TEXT_NODE) {
                if (trim(preg_replace('/\x{00A0}/u', ' ', $candidate->textContent)) === '') {
                    $candidate = $candidate->nextSibling;
                    continue;
                }

                return null;
            }

            if ($candidate->nodeType !== XML_ELEMENT_NODE) {
                $candidate = $candidate->nextSibling;
                continue;
            }

            $tag_name = strtolower($candidate->nodeName);
            if ($tag_name === 'br') {
                $candidate = $candidate->nextSibling;
                continue;
            }

            return $this->node_is_caption_like($candidate, $caption_candidates, true) ? $candidate : null;
        }

        return null;
    }

    private function build_attachment_image_variants($attachment_id, $source_url = '', $original_match = '') {
        $variants = array();

        $attachment_url = wp_get_attachment_url($attachment_id);
        if ($attachment_url) {
            $variants = array_merge($variants, $this->build_image_url_variants($attachment_url));
        }

        $stored_source = get_post_meta($attachment_id, '_mhbi_source_image', true);
        if ($stored_source) {
            $variants = array_merge($variants, $this->build_image_url_variants($stored_source));
        }

        if ($source_url) {
            $variants = array_merge($variants, $this->build_image_url_variants($source_url));
        }

        if ($original_match) {
            $variants = array_merge($variants, $this->build_image_url_variants($original_match));
        }

        return array_values(array_unique(array_filter($variants)));
    }

    private function find_first_matching_image_node($node, $featured_variants) {
        if (!$node) {
            return null;
        }

        if ($node->nodeType === XML_ELEMENT_NODE && strtolower($node->nodeName) === 'img' && $this->image_node_matches_variants($node, $featured_variants)) {
            return $node;
        }

        if (!method_exists($node, 'getElementsByTagName')) {
            return null;
        }

        $images = $node->getElementsByTagName('img');
        foreach ($images as $image) {
            if ($this->image_node_matches_variants($image, $featured_variants)) {
                return $image;
            }
        }

        return null;
    }

    private function node_contains_only_matching_image($node, $featured_variants) {
        if (!$node || $node->nodeType !== XML_ELEMENT_NODE) {
            return false;
        }

        $tag_name = strtolower($node->nodeName);
        $allowed  = array('img', 'p', 'div', 'figure', 'span', 'a');
        if (!in_array($tag_name, $allowed, true)) {
            return false;
        }

        if ('img' === $tag_name) {
            return $this->image_node_matches_variants($node, $featured_variants);
        }

        $matching_image = $this->find_first_matching_image_node($node, $featured_variants);
        if (!$matching_image) {
            return false;
        }

        $images = $node->getElementsByTagName('img');
        if ($images->length !== 1) {
            return false;
        }

        $text = trim(preg_replace('/\x{00A0}/u', ' ', wp_strip_all_tags($node->textContent)));
        return $text === '';
    }

    private function node_contains_only_matching_image_and_caption($node, $featured_variants, $caption_candidates = array()) {
        if (!$node || $node->nodeType !== XML_ELEMENT_NODE) {
            return false;
        }

        $tag_name = strtolower($node->nodeName);
        $allowed  = array('figure', 'div', 'p', 'span', 'a');
        if (!in_array($tag_name, $allowed, true)) {
            return false;
        }

        $matching_image = $this->find_first_matching_image_node($node, $featured_variants);
        if (!$matching_image) {
            return false;
        }

        $images = $node->getElementsByTagName('img');
        if ($images->length !== 1) {
            return false;
        }

        $clone = $node->cloneNode(true);
        if (!$clone) {
            return false;
        }

        $clone_image = $this->find_first_matching_image_node($clone, $featured_variants);
        if (!$clone_image || !$clone_image->parentNode) {
            return false;
        }

        $clone_image->parentNode->removeChild($clone_image);
        $this->remove_empty_wrappers_in_node($clone);

        return $this->node_is_caption_like($clone, $caption_candidates, false);
    }

    private function remove_empty_wrappers_in_node($node) {
        if (!$node || !method_exists($node, 'getElementsByTagName')) {
            return;
        }

        do {
            $changed  = false;
            $elements = array();
            foreach ($node->getElementsByTagName('*') as $element) {
                $elements[] = $element;
            }

            for ($i = count($elements) - 1; $i >= 0; $i--) {
                $element = $elements[$i];
                if (!$element || !$element->parentNode) {
                    continue;
                }

                $tag_name = strtolower($element->nodeName);
                if (!in_array($tag_name, array('a', 'span', 'div', 'p', 'figure'), true)) {
                    continue;
                }

                $text = $this->normalize_caption_text($element->textContent);
                if ($text === '' && $element->getElementsByTagName('img')->length === 0) {
                    $element->parentNode->removeChild($element);
                    $changed = true;
                }
            }
        } while ($changed);
    }

    private function node_is_caption_like($node, $caption_candidates = array(), $strict = false) {
        if (!$node || $node->nodeType !== XML_ELEMENT_NODE) {
            return false;
        }

        foreach (array('img', 'video', 'iframe', 'table', 'blockquote', 'ul', 'ol') as $forbidden_tag) {
            if ($node->getElementsByTagName($forbidden_tag)->length > 0) {
                return false;
            }
        }

        $text = $this->normalize_caption_text($node->textContent);
        if ($text === '') {
            return false;
        }

        $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if ($length > ($strict ? 160 : 220)) {
            return false;
        }

        $tag_name = strtolower($node->nodeName);
        if ($tag_name === 'figcaption') {
            return true;
        }

        if ($this->caption_text_matches_candidates($text, $caption_candidates)) {
            return true;
        }

        if ($this->node_has_caption_markers($node)) {
            return true;
        }

        return !$strict && $length <= 90;
    }

    private function caption_text_matches_candidates($text, $caption_candidates) {
        $normalized_text = $this->normalize_caption_text($text);
        if ($normalized_text === '') {
            return false;
        }

        foreach ((array) $caption_candidates as $candidate) {
            $candidate = $this->normalize_caption_text($candidate);
            if ($candidate === '') {
                continue;
            }

            if ($normalized_text === $candidate || strpos($normalized_text, $candidate) !== false || strpos($candidate, $normalized_text) !== false) {
                return true;
            }
        }

        return false;
    }

    private function node_has_caption_markers($node) {
        if (!$node || $node->nodeType !== XML_ELEMENT_NODE) {
            return false;
        }

        $haystack = array(
            $node->getAttribute('class'),
            $node->getAttribute('style'),
            $node->getAttribute('align'),
            $node->getAttribute('id'),
        );

        $joined = strtolower(implode(' ', array_map('strval', $haystack)));
        if ($joined === '') {
            return false;
        }

        return (bool) preg_match('/caption|tr-caption|aligncenter|text-align\s*:\s*center|\bcenter\b/', $joined);
    }

    private function normalize_caption_text($text) {
        $text = html_entity_decode((string) $text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\x{00A0}/u', ' ', $text);
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        return $text;
    }

    private function image_node_matches_variants($img, $featured_variants) {
        if (!$img || $img->nodeType !== XML_ELEMENT_NODE) {
            return false;
        }

        $attributes = array('src', 'data-src', 'data-lazy-src', 'data-original', 'srcset', 'data-srcset');
        foreach ($attributes as $attribute) {
            $value = $img->getAttribute($attribute);
            if ($value !== '' && $this->image_src_matches_variants($value, $featured_variants)) {
                return true;
            }
        }

        return false;
    }

    private function image_src_matches_variants($src, $featured_variants) {
        $candidate = $this->normalize_image_url_for_compare($src);
        if ($candidate === '') {
            return false;
        }

        foreach ($featured_variants as $variant) {
            if ($variant === '') {
                continue;
            }

            if (strpos($candidate, $variant) !== false || strpos($variant, $candidate) !== false) {
                return true;
            }
        }

        return false;
    }

    private function build_image_url_variants($url) {
        $variants   = array();
        $normalized = $this->normalize_image_url_for_compare($url);

        if ($normalized === '') {
            return array();
        }

        $variants[] = $normalized;

        $path = (string) wp_parse_url('https:' . ltrim($normalized, '/'), PHP_URL_PATH);
        if ($path === '') {
            $path = (string) wp_parse_url($normalized, PHP_URL_PATH);
        }

        if ($path !== '') {
            $path = rawurldecode($path);
            $variants[] = $path;
            $variants[] = preg_replace('#-(\d+)x(\d+)(?=\.[a-z0-9]+$)#i', '', $path);
            $variants[] = preg_replace('#/(?:s\d+|w\d+-h\d+|w\d+|h\d+)(?=/)#i', '', $path);

            $basename = basename($path);
            if ($basename !== '') {
                $variants[] = $basename;
                $variants[] = preg_replace('#-(\d+)x(\d+)(?=\.[a-z0-9]+$)#i', '', $basename);
            }
        }

        return array_values(array_unique(array_filter($variants)));
    }

    private function normalize_image_url_for_compare($url) {
        $url = html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8');
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (strpos($url, ',') !== false && strpos($url, ' ') !== false) {
            $parts = preg_split('/\s*,\s*/', $url);
            if (!empty($parts[0])) {
                $first = trim((string) $parts[0]);
                $url   = trim((string) preg_replace('/\s+\d+[wx]?$/i', '', $first));
            }
        }

        $url = preg_replace('/^https?:/i', '', $url);
        $url = preg_replace('/\?.*$/', '', $url);
        $url = preg_replace('/#.*$/', '', $url);
        $url = preg_replace('/=w\d+(-h\d+)?(?:-[a-z])?$/i', '', $url);
        $url = preg_replace('#/(?:s\d+|w\d+-h\d+|w\d+|h\d+)(?=/)#i', '/', $url);
        $url = rawurldecode($url);

        return strtolower($url);
    }

    private function remove_featured_image_with_regex($content, $featured_variants) {
        $content = (string) $content;

        foreach ($featured_variants as $variant) {
            $quoted   = preg_quote($variant, '#');
            $patterns = array(
                '#<(p|div|figure|span|a)[^>]*>\s*(?:<a[^>]*>\s*)?<img[^>]+(?:src|data-src|data-lazy-src|data-original|srcset|data-srcset)=["\x27][^"\x27]*' . $quoted . '[^"\x27]*["\x27][^>]*>\s*(?:</a>\s*)?</\1>#is',
                '#<img[^>]+(?:src|data-src|data-lazy-src|data-original|srcset|data-srcset)=["\x27][^"\x27]*' . $quoted . '[^"\x27]*["\x27][^>]*>\s*#is',
            );

            foreach ($patterns as $pattern) {
                $content = preg_replace($pattern, '', $content);
            }
        }

        return $this->cleanup_leading_empty_markup($content);
    }

    private function remove_matching_image_node($image_node) {
        if (!$image_node || !$image_node->parentNode) {
            return;
        }

        $parent = $image_node->parentNode;
        $parent->removeChild($image_node);
    }

    private function cleanup_empty_ancestor_nodes($node, $stop_node) {
        $current = $node;
        while ($current && $current !== $stop_node) {
            if ($current->nodeType !== XML_ELEMENT_NODE) {
                $current = $current->parentNode;
                continue;
            }

            $tag_name = strtolower($current->nodeName);
            if (!in_array($tag_name, array('p', 'div', 'figure', 'span', 'a'), true)) {
                break;
            }

            $text = trim(preg_replace('/\x{00A0}/u', ' ', wp_strip_all_tags($current->textContent)));
            if ($text !== '' || $current->getElementsByTagName('img')->length > 0) {
                break;
            }

            $parent = $current->parentNode;
            if (!$parent) {
                break;
            }

            $parent->removeChild($current);
            $current = $parent;
        }
    }

    private function get_meaningful_text_length($node) {
        $text = trim(preg_replace('/\x{00A0}/u', ' ', wp_strip_all_tags($node->textContent)));
        return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    }

    private function cleanup_leading_empty_markup($content) {
        $content = (string) $content;

        do {
            $previous = $content;
            $content  = preg_replace('#^(?:\s|&nbsp;|<br\s*/?>)+#i', '', $content);
            $content  = preg_replace('#^<(p|div|figure|span|a)[^>]*>\s*(?:&nbsp;|<br\s*/?>|\s)*</\1>#i', '', $content);
        } while ($content !== $previous);

        return $content;
    }

    private function get_inner_html($node) {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument->saveHTML($child);
        }

        return $html;
    }

    private function find_existing_attachment_by_source_url($url) {
        $posts = get_posts(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'meta_key'       => '_mhbi_source_image', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_value'     => $url, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'fields'         => 'ids',
                'posts_per_page' => 1,
                'no_found_rows'  => true,
            )
        );

        return !empty($posts[0]) ? (int) $posts[0] : 0;
    }
}
