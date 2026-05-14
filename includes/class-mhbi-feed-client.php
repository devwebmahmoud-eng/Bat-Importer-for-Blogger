<?php
if (!defined('ABSPATH')) {
    exit;
}

class MHBI_Feed_Client {
    public function fetch_feed_page($blog_id, $feed_type = 'posts', $start_index = 1, $max_results = 10) {
        $blog_id = preg_replace('/\D+/', '', (string) $blog_id);
        if ($blog_id === '') {
            return new WP_Error('mhbi_invalid_blog_id', __('Invalid Blogger Blog ID.', 'bat-importer-for-blogger'));
        }

        $feed_type    = $feed_type === 'pages' ? 'pages' : 'posts';
        $start_index  = max(1, (int) $start_index);
        $max_results  = max(1, min(50, (int) $max_results));
        $feed_url     = sprintf(
            'https://www.blogger.com/feeds/%1$s/%2$s/default?alt=atom&orderby=published&start-index=%3$d&max-results=%4$d',
            rawurlencode($blog_id),
            rawurlencode($feed_type),
            $start_index,
            $max_results
        );

        $response = wp_remote_get(
            $feed_url,
            array(
                'timeout'    => 25,
                'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url('/'),
                'sslverify'  => true,
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300 || $body === '') {
            return new WP_Error(
                'mhbi_feed_request_failed',
                sprintf(
                    /* translators: %d: HTTP status code returned by Blogger. */
                    __('Blogger feed request failed. HTTP %d.', 'bat-importer-for-blogger'),
                    $code
                )
            );
        }

        return $this->parse_feed_xml($body, $feed_type);
    }

    public function fetch_blog_snapshot($blog_id) {
        $result = $this->fetch_feed_page($blog_id, 'posts', 1, 1);
        if (is_wp_error($result)) {
            return $result;
        }

        return array(
            'title'       => MHBI_Utils::maybe_get_array_value($result, 'feed_title'),
            'site_url'    => MHBI_Utils::maybe_get_array_value($result, 'feed_alternate_url'),
            'total_posts' => (int) MHBI_Utils::maybe_get_array_value($result, 'total_results', 0),
        );
    }

    private function parse_feed_xml($xml_string, $feed_type) {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xml_string);

        if (!$xml instanceof SimpleXMLElement) {
            $messages = array();
            foreach (libxml_get_errors() as $error) {
                $messages[] = trim($error->message);
            }
            libxml_clear_errors();

            return new WP_Error(
                'mhbi_invalid_xml',
                __('Unable to parse Blogger feed XML.', 'bat-importer-for-blogger') . ' ' . implode(' | ', array_filter($messages))
            );
        }

        $open_search = $xml->children('http://a9.com/-/spec/opensearchrss/1.0/');
        $feed_title  = sanitize_text_field((string) $xml->title);
        $alt_url     = '';

        foreach ($xml->link as $link) {
            $attrs = $link->attributes();
            if ((string) $attrs['rel'] === 'alternate') {
                $alt_url = esc_url_raw((string) $attrs['href']);
                break;
            }
        }

        $entries = array();
        foreach ($xml->entry as $entry) {
            $entries[] = $this->parse_entry($entry, $feed_type);
        }

        return array(
            'feed_title'         => $feed_title,
            'feed_alternate_url' => $alt_url,
            'total_results'      => isset($open_search->totalResults) ? (int) $open_search->totalResults : count($entries),
            'start_index'        => isset($open_search->startIndex) ? (int) $open_search->startIndex : 1,
            'items_per_page'     => isset($open_search->itemsPerPage) ? (int) $open_search->itemsPerPage : count($entries),
            'entries'            => $entries,
        );
    }

    private function parse_entry(SimpleXMLElement $entry, $feed_type) {
        $alternate_url = '';
        $comment_feed  = '';

        foreach ($entry->link as $link) {
            $attrs = $link->attributes();
            $rel   = (string) $attrs['rel'];
            $href  = (string) $attrs['href'];

            if ($rel === 'alternate' && $href !== '') {
                $alternate_url = esc_url_raw($href);
            }

            if ($rel === 'replies' && $href !== '') {
                $comment_feed = esc_url_raw($href);
            }
        }

        $labels = array();
        foreach ($entry->category as $category) {
            $attrs = $category->attributes();
            if (isset($attrs['term'])) {
                $term = sanitize_text_field((string) $attrs['term']);
                if ($term !== '' && strpos($term, '#') !== 0) {
                    $labels[] = $term;
                }
            }
        }

        $content = (string) $entry->content;
        if ($content === '') {
            $content = (string) $entry->summary;
        }

        $author = '';
        if (isset($entry->author->name)) {
            $author = sanitize_text_field((string) $entry->author->name);
        }

        $old_path = $alternate_url ? MHBI_Utils::normalize_old_path($alternate_url) : '';

        return array(
            'type'             => $feed_type === 'pages' ? 'page' : 'post',
            'blogger_post_id'  => MHBI_Utils::extract_blogger_post_id((string) $entry->id),
            'entry_id'         => sanitize_text_field((string) $entry->id),
            'title'            => wp_strip_all_tags((string) $entry->title),
            'content'          => (string) $content,
            'published'        => sanitize_text_field((string) $entry->published),
            'updated'          => sanitize_text_field((string) $entry->updated),
            'author'           => $author,
            'labels'           => array_values(array_unique($labels)),
            'old_url'          => $alternate_url,
            'old_path'         => $old_path,
            'old_slug'         => $old_path ? MHBI_Utils::extract_slug_from_path($old_path) : '',
            'comment_feed_url' => $comment_feed,
        );
    }
}
