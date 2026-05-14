<?php
if (!defined('ABSPATH')) {
    exit;
}

class MHBI_Admin {
    /** @var MHBI_Importer */
    private $importer;

    /** @var MHBI_Feed_Client */
    private $feed_client;

    public function __construct(MHBI_Importer $importer, MHBI_Feed_Client $feed_client) {
        $this->importer    = $importer;
        $this->feed_client = $feed_client;

        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    public function register_menu() {
        add_management_page(
            __('Bat Importer for Blogger', 'bat-importer-for-blogger'),
            __('Bat Importer for Blogger', 'bat-importer-for-blogger'),
            'manage_options',
            'bat-importer-for-blogger',
            array($this, 'render_page')
        );
    }

    public function enqueue_assets($hook) {
        if ($hook !== 'tools_page_bat-importer-for-blogger') {
            return;
        }

        wp_enqueue_style('mhbi-admin', MHBI_URL . 'assets/admin.css', array(), MHBI_VERSION);
        wp_enqueue_script('mhbi-admin', MHBI_URL . 'assets/admin.js', array(), MHBI_VERSION, true);
        wp_localize_script(
            'mhbi-admin',
            'mhbiAdmin',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('mhbi_import_nonce'),
                'i18n'    => array(
                    'starting'            => __('Preparing import…', 'bat-importer-for-blogger'),
                    'processing'          => __('Processing batch…', 'bat-importer-for-blogger'),
                    'done'                => __('Import finished.', 'bat-importer-for-blogger'),
                    'stopped'             => __('Import stopped.', 'bat-importer-for-blogger'),
                    'reset'               => __('State reset.', 'bat-importer-for-blogger'),
                    'fullReset'           => __('Plugin data reset completed.', 'bat-importer-for-blogger'),
                    'error'               => __('An error occurred.', 'bat-importer-for-blogger'),
                    'confirmStop'         => __('Stop the current import now?', 'bat-importer-for-blogger'),
                    'confirmReset'        => __('Clear the current import progress only?', 'bat-importer-for-blogger'),
                    'confirmFullReset'    => __('This will clear the import job, saved settings, redirect mappings, and all plugin tracking metadata from the database. Imported posts and media will stay in WordPress. Continue?', 'bat-importer-for-blogger'),
                    'idle'                => __('Idle.', 'bat-importer-for-blogger'),
                    'pausedNotice'        => __('Import paused. You can start again or run a full reset.', 'bat-importer-for-blogger'),
                    'running'             => __('Running', 'bat-importer-for-blogger'),
                    'ready'               => __('Ready', 'bat-importer-for-blogger'),
                    'finished'            => __('Finished', 'bat-importer-for-blogger'),
                    'stoppedBadge'        => __('Stopped', 'bat-importer-for-blogger'),
                    'postsPhase'          => __('Importing posts', 'bat-importer-for-blogger'),
                    'pagesPhase'          => __('Importing pages', 'bat-importer-for-blogger'),
                    'unknownPhase'        => __('Preparing', 'bat-importer-for-blogger'),
                    'publicOnly'          => __('Works with public Blogger blogs only.', 'bat-importer-for-blogger'),
                    'resumeHint'          => __('There is a saved import state on this site.', 'bat-importer-for-blogger'),
                    'statusPrefix'        => __('Status', 'bat-importer-for-blogger'),
                    'phasePrefix'         => __('Phase', 'bat-importer-for-blogger'),
                    'progressPrefix'      => __('Progress', 'bat-importer-for-blogger'),
                    'ofPosts'             => __('of discovered posts', 'bat-importer-for-blogger'),
                    'pagesFinalizing'     => __('Posts are done. Finalizing pages and cleanup…', 'bat-importer-for-blogger'),
                    'clearLog'            => __('Activity log cleared.', 'bat-importer-for-blogger'),
                    'saved'               => __('Settings are used when you start a new import.', 'bat-importer-for-blogger'),
                    /* translators: %s: error message text. */
                    'errorLog'            => __('Error: %s', 'bat-importer-for-blogger'),
                    /* translators: 1: processed post count, 2: total discovered post count. */
                    'progressCount'       => __('%1$d / %2$d of discovered posts', 'bat-importer-for-blogger'),
                    /* translators: %d: number of discovered posts processed. */
                    'progressCompleteSingle' => __('%d discovered post processed', 'bat-importer-for-blogger'),
                    /* translators: %d: number of discovered posts processed. */
                    'progressCompletePlural' => __('%d discovered posts processed', 'bat-importer-for-blogger'),
                ),
            )
        );
    }

    public function render_page() {
        $settings      = MHBI_Utils::get_settings();
        $job           = get_option('mhbi_import_job', array());
        $job_phase     = MHBI_Utils::maybe_get_array_value($job, 'phase', 'posts');
        $job_complete  = !empty($job['complete']);
        $job_stopped   = !empty($job['stopped']);
        $job_running   = !$job_complete && !$job_stopped && !empty($job['blog_id']);
        $job_processed = absint(MHBI_Utils::maybe_get_array_value($job, 'processed', 0));
        $job_imported  = absint(MHBI_Utils::maybe_get_array_value($job, 'imported', 0));
        $job_updated   = absint(MHBI_Utils::maybe_get_array_value($job, 'updated', 0));
        $job_skipped   = absint(MHBI_Utils::maybe_get_array_value($job, 'skipped', 0));
        $job_errors    = absint(MHBI_Utils::maybe_get_array_value($job, 'errors', 0));
        $job_total     = absint(MHBI_Utils::maybe_get_array_value($job, 'total_posts', 0));
        $job_batch     = absint(MHBI_Utils::maybe_get_array_value($job, 'batch_size', (int) $settings['batch_size']));
        $status_label  = __('Ready', 'bat-importer-for-blogger');

        if ($job_complete) {
            $status_label = __('Finished', 'bat-importer-for-blogger');
        } elseif ($job_stopped) {
            $status_label = __('Stopped', 'bat-importer-for-blogger');
        } elseif ($job_running) {
            $status_label = __('Running', 'bat-importer-for-blogger');
        }

        $phase_label = 'pages' === $job_phase ? __('Importing pages', 'bat-importer-for-blogger') : __('Importing posts', 'bat-importer-for-blogger');
        $blog_id_display = !empty($job['blog_id']) ? $job['blog_id'] : $settings['blog_id'];
        $blog_title      = !empty($job['blog_title']) ? $job['blog_title'] : $settings['last_blog_title'];
        $blog_url        = !empty($job['blog_url']) ? $job['blog_url'] : $settings['last_blog_url'];
        $last_message    = !empty($job['last_message']) ? $job['last_message'] : __('No activity yet.', 'bat-importer-for-blogger');
        $started_at      = !empty($job['started_at']) ? $job['started_at'] : '—';

        $redirect_stats   = MHBI_Utils::get_redirect_stats(20);
        $redirect_count   = (int) MHBI_Utils::maybe_get_array_value($redirect_stats, 'count', 0);
        $recent_redirects = (array) MHBI_Utils::maybe_get_array_value($redirect_stats, 'recent', array());
        ?>
        <div
            class="wrap mhbi-wrap"
            data-initial-running="<?php echo esc_attr($job_running ? '1' : '0'); ?>"
            data-initial-complete="<?php echo esc_attr($job_complete ? '1' : '0'); ?>"
            data-initial-stopped="<?php echo esc_attr($job_stopped ? '1' : '0'); ?>"
            data-initial-phase="<?php echo esc_attr($job_phase); ?>"
            data-initial-total-posts="<?php echo esc_attr((string) $job_total); ?>"
        >
            <div class="mhbi-hero">
                <div class="mhbi-hero__content">
                    <span class="mhbi-eyebrow"><?php esc_html_e('Blogger → WordPress migration', 'bat-importer-for-blogger'); ?></span>
                    <h1><?php esc_html_e('Bat Importer for Blogger', 'bat-importer-for-blogger'); ?></h1>
                    <p class="mhbi-hero__text"><?php esc_html_e('Import posts from a public Blogger site using only the Blog ID, keep your images inside WordPress, and preserve old links with redirects.', 'bat-importer-for-blogger'); ?></p>
                    <div class="mhbi-badges">
                        <span class="mhbi-badge mhbi-badge--dark"><?php esc_html_e('Blog ID only', 'bat-importer-for-blogger'); ?></span>
                        <span class="mhbi-badge"><?php esc_html_e('Batch processing', 'bat-importer-for-blogger'); ?></span>
                        <span class="mhbi-badge"><?php esc_html_e('301 redirects', 'bat-importer-for-blogger'); ?></span>
                        <span class="mhbi-badge"><?php esc_html_e('Public blogs only', 'bat-importer-for-blogger'); ?></span>
                    </div>
                </div>
                <div class="mhbi-hero__aside">
                    <div class="mhbi-status-panel">
                        <div class="mhbi-status-panel__row">
                            <span><?php esc_html_e('Current status', 'bat-importer-for-blogger'); ?></span>
                            <strong id="mhbi-status-badge" class="mhbi-status-pill <?php echo esc_attr($job_complete ? 'is-complete' : ($job_stopped ? 'is-stopped' : ($job_running ? 'is-running' : 'is-ready'))); ?>"><?php echo esc_html($status_label); ?></strong>
                        </div>
                        <div class="mhbi-status-panel__row">
                            <span><?php esc_html_e('Current phase', 'bat-importer-for-blogger'); ?></span>
                            <strong id="mhbi-phase-label"><?php echo esc_html($phase_label); ?></strong>
                        </div>
                        <div class="mhbi-status-panel__row">
                            <span><?php esc_html_e('Batch size', 'bat-importer-for-blogger'); ?></span>
                            <strong id="mhbi-batch-label"><?php echo esc_html((string) $job_batch); ?></strong>
                        </div>
                        <div class="mhbi-status-panel__hint"><?php esc_html_e('Tabbed workspace for monitoring progress, redirects, and import tools.', 'bat-importer-for-blogger'); ?></div>
                    </div>
                </div>
            </div>

            <div id="mhbi-notice" class="mhbi-notice" hidden></div>

            <div class="mhbi-tabs" role="tablist" aria-label="<?php esc_attr_e('Bat Importer for Blogger sections', 'bat-importer-for-blogger'); ?>">
                <button type="button" class="mhbi-tab is-active" id="mhbi-tab-dashboard" data-tab="dashboard" role="tab" aria-selected="true" aria-controls="mhbi-panel-dashboard"><?php esc_html_e('Dashboard', 'bat-importer-for-blogger'); ?></button>
                <button type="button" class="mhbi-tab" id="mhbi-tab-import" data-tab="import" role="tab" aria-selected="false" aria-controls="mhbi-panel-import"><?php esc_html_e('Import', 'bat-importer-for-blogger'); ?></button>
                <button type="button" class="mhbi-tab" id="mhbi-tab-redirects" data-tab="redirects" role="tab" aria-selected="false" aria-controls="mhbi-panel-redirects"><?php esc_html_e('Redirects', 'bat-importer-for-blogger'); ?></button>
                <button type="button" class="mhbi-tab" id="mhbi-tab-tools" data-tab="tools" role="tab" aria-selected="false" aria-controls="mhbi-panel-tools"><?php esc_html_e('Tools', 'bat-importer-for-blogger'); ?></button>
                <button type="button" class="mhbi-tab" id="mhbi-tab-logs" data-tab="logs" role="tab" aria-selected="false" aria-controls="mhbi-panel-logs"><?php esc_html_e('Logs', 'bat-importer-for-blogger'); ?></button>
            </div>

            <div class="mhbi-panels">
                <section class="mhbi-panel is-active" id="mhbi-panel-dashboard" data-panel="dashboard" role="tabpanel" aria-labelledby="mhbi-tab-dashboard">
                    <div class="mhbi-overview-grid">
                        <div class="mhbi-card mhbi-card--overview">
                            <div class="mhbi-card__header">
                                <div>
                                    <span class="mhbi-step"><?php esc_html_e('Overview', 'bat-importer-for-blogger'); ?></span>
                                    <h2><?php esc_html_e('Migration health', 'bat-importer-for-blogger'); ?></h2>
                                    <p><?php esc_html_e('Everything important in one place while the importer runs.', 'bat-importer-for-blogger'); ?></p>
                                </div>
                            </div>
                            <div class="mhbi-progress-panel">
                                <div class="mhbi-progress-meta">
                                    <strong id="mhbi-status"><?php echo esc_html($last_message); ?></strong>
                                    <span id="mhbi-progress-text"><?php esc_html_e('Idle.', 'bat-importer-for-blogger'); ?></span>
                                </div>
                                <div class="mhbi-progress-bar">
                                    <span id="mhbi-progress-fill"></span>
                                </div>
                            </div>
                            <div class="mhbi-stats-grid">
                                <div class="mhbi-stat-card">
                                    <span><?php esc_html_e('Processed', 'bat-importer-for-blogger'); ?></span>
                                    <strong id="mhbi-processed"><?php echo esc_html((string) $job_processed); ?></strong>
                                </div>
                                <div class="mhbi-stat-card">
                                    <span><?php esc_html_e('Imported', 'bat-importer-for-blogger'); ?></span>
                                    <strong id="mhbi-imported"><?php echo esc_html((string) $job_imported); ?></strong>
                                </div>
                                <div class="mhbi-stat-card">
                                    <span><?php esc_html_e('Updated', 'bat-importer-for-blogger'); ?></span>
                                    <strong id="mhbi-updated"><?php echo esc_html((string) $job_updated); ?></strong>
                                </div>
                                <div class="mhbi-stat-card">
                                    <span><?php esc_html_e('Skipped', 'bat-importer-for-blogger'); ?></span>
                                    <strong id="mhbi-skipped"><?php echo esc_html((string) $job_skipped); ?></strong>
                                </div>
                                <div class="mhbi-stat-card mhbi-stat-card--danger">
                                    <span><?php esc_html_e('Errors', 'bat-importer-for-blogger'); ?></span>
                                    <strong id="mhbi-errors"><?php echo esc_html((string) $job_errors); ?></strong>
                                </div>
                            </div>
                        </div>

                        <div class="mhbi-card mhbi-card--stack">
                            <div class="mhbi-card__header">
                                <div>
                                    <span class="mhbi-step"><?php esc_html_e('Source', 'bat-importer-for-blogger'); ?></span>
                                    <h2><?php esc_html_e('Current source', 'bat-importer-for-blogger'); ?></h2>
                                </div>
                            </div>
                            <dl class="mhbi-data-list">
                                <div><dt><?php esc_html_e('Blog ID', 'bat-importer-for-blogger'); ?></dt><dd><?php echo esc_html($blog_id_display ? $blog_id_display : '—'); ?></dd></div>
                                <div><dt><?php esc_html_e('Blog title', 'bat-importer-for-blogger'); ?></dt><dd><?php echo esc_html($blog_title ? $blog_title : '—'); ?></dd></div>
                                <div><dt><?php esc_html_e('Blog URL', 'bat-importer-for-blogger'); ?></dt><dd><?php if ($blog_url) : ?><a href="<?php echo esc_url($blog_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($blog_url); ?></a><?php else : ?>—<?php endif; ?></dd></div>
                                <div><dt><?php esc_html_e('Started', 'bat-importer-for-blogger'); ?></dt><dd><?php echo esc_html($started_at); ?></dd></div>
                                <div><dt><?php esc_html_e('Discovered posts', 'bat-importer-for-blogger'); ?></dt><dd><?php echo esc_html((string) $job_total); ?></dd></div>
                            </dl>
                            <div class="mhbi-inline-help">
                                <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
                                <p><?php esc_html_e('Use the Import tab to start or resume a migration. Use Redirects to inspect old Blogger paths mapped to imported posts.', 'bat-importer-for-blogger'); ?></p>
                            </div>
                            <div class="mhbi-quick-links">
                                <button type="button" class="button button-secondary mhbi-tab-jump" data-target-tab="import"><?php esc_html_e('Go to Import', 'bat-importer-for-blogger'); ?></button>
                                <button type="button" class="button button-secondary mhbi-button-stop" data-mhbi-stop <?php disabled(!$job_running); ?>><?php esc_html_e('Stop import', 'bat-importer-for-blogger'); ?></button>
                                <button type="button" class="button button-secondary mhbi-tab-jump" data-target-tab="redirects"><?php esc_html_e('View Redirects', 'bat-importer-for-blogger'); ?></button>
                                <button type="button" class="button button-secondary mhbi-tab-jump" data-target-tab="logs"><?php esc_html_e('Open Logs', 'bat-importer-for-blogger'); ?></button>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="mhbi-panel" id="mhbi-panel-import" data-panel="import" role="tabpanel" aria-labelledby="mhbi-tab-import" hidden>
                    <div class="mhbi-layout-single">
                        <section class="mhbi-card mhbi-card--form">
                            <div class="mhbi-card__header">
                                <div>
                                    <span class="mhbi-step"><?php esc_html_e('Step 1', 'bat-importer-for-blogger'); ?></span>
                                    <h2><?php esc_html_e('Connection and import options', 'bat-importer-for-blogger'); ?></h2>
                                    <p><?php esc_html_e('Enter the numeric Blogger Blog ID, choose what you want to import, then start the migration.', 'bat-importer-for-blogger'); ?></p>
                                </div>
                            </div>

                            <div class="mhbi-field-grid">
                                <div class="mhbi-field mhbi-field--full">
                                    <label for="mhbi_blog_id"><?php esc_html_e('Blogger Blog ID', 'bat-importer-for-blogger'); ?></label>
                                    <input type="text" id="mhbi_blog_id" inputmode="numeric" value="<?php echo esc_attr($settings['blog_id']); ?>" placeholder="1234567890123456789">
                                    <p class="description"><?php esc_html_e('Only the numeric Blog ID is required. This plugin works with public Blogger blogs.', 'bat-importer-for-blogger'); ?></p>
                                </div>

                                <div class="mhbi-field">
                                    <label for="mhbi_batch_size"><?php esc_html_e('Batch size', 'bat-importer-for-blogger'); ?></label>
                                    <input type="number" id="mhbi_batch_size" min="1" max="50" value="<?php echo esc_attr((string) $settings['batch_size']); ?>">
                                    <p class="description"><?php esc_html_e('Smaller values are gentler on hosting. Larger values finish faster.', 'bat-importer-for-blogger'); ?></p>
                                </div>
                            </div>

                            <div class="mhbi-option-grid">
                                <label class="mhbi-toggle-card">
                                    <input type="checkbox" id="mhbi_download_images" <?php checked(!empty($settings['download_images'])); ?>>
                                    <span class="mhbi-toggle-card__ui"></span>
                                    <span class="mhbi-toggle-card__content">
                                        <strong><?php esc_html_e('Download images into WordPress', 'bat-importer-for-blogger'); ?></strong>
                                        <small><?php esc_html_e('Copies Blogger-hosted images into your Media Library and replaces links in content.', 'bat-importer-for-blogger'); ?></small>
                                    </span>
                                </label>

                                <label class="mhbi-toggle-card">
                                    <input type="checkbox" id="mhbi_import_pages" <?php checked(!empty($settings['import_pages'])); ?>>
                                    <span class="mhbi-toggle-card__ui"></span>
                                    <span class="mhbi-toggle-card__content">
                                        <strong><?php esc_html_e('Import Blogger pages too', 'bat-importer-for-blogger'); ?></strong>
                                        <small><?php esc_html_e('After posts finish, the importer will also bring over static pages.', 'bat-importer-for-blogger'); ?></small>
                                    </span>
                                </label>

                                <label class="mhbi-toggle-card">
                                    <input type="checkbox" id="mhbi_enable_redirects" <?php checked(!empty($settings['enable_redirects'])); ?>>
                                    <span class="mhbi-toggle-card__ui"></span>
                                    <span class="mhbi-toggle-card__content">
                                        <strong><?php esc_html_e('Enable Blogger 301 redirects', 'bat-importer-for-blogger'); ?></strong>
                                        <small><?php esc_html_e('Maps old Blogger post paths to the new WordPress permalink when possible.', 'bat-importer-for-blogger'); ?></small>
                                    </span>
                                </label>

                                <label class="mhbi-toggle-card">
                                    <input type="checkbox" id="mhbi_redirect_404_home" <?php checked(!empty($settings['redirect_404_home'])); ?>>
                                    <span class="mhbi-toggle-card__ui"></span>
                                    <span class="mhbi-toggle-card__content">
                                        <strong><?php esc_html_e('Redirect unmatched 404s to homepage', 'bat-importer-for-blogger'); ?></strong>
                                        <small><?php esc_html_e('Fallback redirect if an old Blogger URL cannot be matched to an imported post.', 'bat-importer-for-blogger'); ?></small>
                                    </span>
                                </label>
                            </div>

                            <div class="mhbi-toolbar">
                                <div class="mhbi-toolbar__group">
                                    <button type="button" class="button button-primary button-hero" id="mhbi-start-btn"><?php esc_html_e('Start import', 'bat-importer-for-blogger'); ?></button>
                                    <button type="button" class="button button-secondary mhbi-button-stop" id="mhbi-stop-btn" data-mhbi-stop <?php disabled(!$job_running); ?>><?php esc_html_e('Stop import', 'bat-importer-for-blogger'); ?></button>
                                </div>
                                <div class="mhbi-toolbar__group mhbi-toolbar__group--secondary">
                                    <button type="button" class="button button-secondary mhbi-tab-jump" data-target-tab="tools"><?php esc_html_e('Open tools', 'bat-importer-for-blogger'); ?></button>
                                    <button type="button" class="button button-secondary mhbi-tab-jump" data-target-tab="logs"><?php esc_html_e('Watch logs', 'bat-importer-for-blogger'); ?></button>
                                </div>
                            </div>

                            <div class="mhbi-inline-help">
                                <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
                                <p><?php esc_html_e('This importer uses public Blogger feeds. Private or restricted blogs will not be accessible with Blog ID only.', 'bat-importer-for-blogger'); ?></p>
                            </div>
                        </section>
                    </div>
                </section>

                <section class="mhbi-panel" id="mhbi-panel-redirects" data-panel="redirects" role="tabpanel" aria-labelledby="mhbi-tab-redirects" hidden>
                    <div class="mhbi-overview-grid mhbi-overview-grid--3">
                        <div class="mhbi-card mhbi-card--metric">
                            <span class="mhbi-step"><?php esc_html_e('Redirect mode', 'bat-importer-for-blogger'); ?></span>
                            <strong><?php echo !empty($settings['enable_redirects']) ? esc_html__('Enabled', 'bat-importer-for-blogger') : esc_html__('Disabled', 'bat-importer-for-blogger'); ?></strong>
                            <p><?php esc_html_e('Old Blogger paths can redirect to imported posts with 301 status codes.', 'bat-importer-for-blogger'); ?></p>
                        </div>
                        <div class="mhbi-card mhbi-card--metric">
                            <span class="mhbi-step"><?php esc_html_e('404 fallback', 'bat-importer-for-blogger'); ?></span>
                            <strong><?php echo !empty($settings['redirect_404_home']) ? esc_html__('Homepage redirect on', 'bat-importer-for-blogger') : esc_html__('Homepage redirect off', 'bat-importer-for-blogger'); ?></strong>
                            <p><?php esc_html_e('Useful when you want unmatched old Blogger URLs to resolve somewhere instead of staying 404.', 'bat-importer-for-blogger'); ?></p>
                        </div>
                        <div class="mhbi-card mhbi-card--metric">
                            <span class="mhbi-step"><?php esc_html_e('Mappings', 'bat-importer-for-blogger'); ?></span>
                            <strong><?php echo esc_html((string) $redirect_count); ?></strong>
                            <p><?php esc_html_e('Stored redirect rules from imported Blogger content.', 'bat-importer-for-blogger'); ?></p>
                        </div>
                    </div>

                    <div class="mhbi-card mhbi-card--table">
                        <div class="mhbi-card__header">
                            <div>
                                <span class="mhbi-step"><?php esc_html_e('Recent rules', 'bat-importer-for-blogger'); ?></span>
                                <h2><?php esc_html_e('Redirect mappings preview', 'bat-importer-for-blogger'); ?></h2>
                                <p><?php esc_html_e('Latest Blogger paths currently stored in the plugin redirect table.', 'bat-importer-for-blogger'); ?></p>
                            </div>
                            <button type="button" class="button button-secondary mhbi-tab-jump" data-target-tab="tools"><?php esc_html_e('Reset mappings', 'bat-importer-for-blogger'); ?></button>
                        </div>

                        <?php if (!empty($recent_redirects)) : ?>
                            <div class="mhbi-table-wrap">
                                <table class="widefat striped mhbi-table">
                                    <thead>
                                        <tr>
                                            <th><?php esc_html_e('Old path', 'bat-importer-for-blogger'); ?></th>
                                            <th><?php esc_html_e('Target post', 'bat-importer-for-blogger'); ?></th>
                                            <th><?php esc_html_e('Old host', 'bat-importer-for-blogger'); ?></th>
                                            <th><?php esc_html_e('Updated', 'bat-importer-for-blogger'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_redirects as $row) : ?>
                                            <?php
                                            $target_permalink = get_permalink((int) $row['post_id']);
                                            $target_title     = get_the_title((int) $row['post_id']);
                                            ?>
                                            <tr>
                                                <td><code><?php echo esc_html($row['old_path']); ?></code></td>
                                                <td>
                                                    <?php if ($target_permalink) : ?>
                                                        <a href="<?php echo esc_url($target_permalink); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($target_title ? $target_title : '#' . (int) $row['post_id']); ?></a>
                                                    <?php else : ?>
                                                        <span><?php echo esc_html($target_title ? $target_title : '#' . (int) $row['post_id']); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo esc_html($row['old_host'] ? $row['old_host'] : '—'); ?></td>
                                                <td><?php echo esc_html($row['updated_at']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else : ?>
                            <div class="mhbi-empty-state">
                                <span class="dashicons dashicons-randomize"></span>
                                <h3><?php esc_html_e('No redirect mappings yet', 'bat-importer-for-blogger'); ?></h3>
                                <p><?php esc_html_e('Start an import and the redirect rules for imported Blogger content will appear here.', 'bat-importer-for-blogger'); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="mhbi-panel" id="mhbi-panel-tools" data-panel="tools" role="tabpanel" aria-labelledby="mhbi-tab-tools" hidden>
                    <div class="mhbi-overview-grid">
                        <div class="mhbi-card mhbi-card--stack">
                            <div class="mhbi-card__header">
                                <div>
                                    <span class="mhbi-step"><?php esc_html_e('Tools', 'bat-importer-for-blogger'); ?></span>
                                    <h2><?php esc_html_e('Import control actions', 'bat-importer-for-blogger'); ?></h2>
                                    <p><?php esc_html_e('Use stop, reset, or full reset depending on whether you want to pause, clear the current job, or wipe plugin tracking data.', 'bat-importer-for-blogger'); ?></p>
                                </div>
                            </div>
                            <div class="mhbi-toolbar mhbi-toolbar--stacked">
                                <div class="mhbi-toolbar__group">
                                    <button type="button" class="button button-secondary mhbi-button-stop" data-mhbi-stop <?php disabled(!$job_running); ?>><?php esc_html_e('Stop import', 'bat-importer-for-blogger'); ?></button>
                                    <button type="button" class="button button-secondary" id="mhbi-reset-btn"><?php esc_html_e('Reset current job', 'bat-importer-for-blogger'); ?></button>
                                    <button type="button" class="button button-link-delete" id="mhbi-full-reset-btn"><?php esc_html_e('Full reset plugin data', 'bat-importer-for-blogger'); ?></button>
                                </div>
                            </div>
                            <div class="mhbi-inline-help">
                                <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                                <p><?php esc_html_e('Full reset removes saved settings, redirect mappings, and plugin metadata from the database, but it does not delete imported posts or media from WordPress.', 'bat-importer-for-blogger'); ?></p>
                            </div>
                        </div>

                        <div class="mhbi-card mhbi-card--stack">
                            <div class="mhbi-card__header">
                                <div>
                                    <span class="mhbi-step"><?php esc_html_e('Snapshot', 'bat-importer-for-blogger'); ?></span>
                                    <h2><?php esc_html_e('Current saved state', 'bat-importer-for-blogger'); ?></h2>
                                </div>
                            </div>
                            <dl class="mhbi-data-list">
                                <div><dt><?php esc_html_e('Job status', 'bat-importer-for-blogger'); ?></dt><dd><?php echo esc_html($status_label); ?></dd></div>
                                <div><dt><?php esc_html_e('Current phase', 'bat-importer-for-blogger'); ?></dt><dd><?php echo esc_html($phase_label); ?></dd></div>
                                <div><dt><?php esc_html_e('Last message', 'bat-importer-for-blogger'); ?></dt><dd><?php echo esc_html($last_message); ?></dd></div>
                                <div><dt><?php esc_html_e('Download images', 'bat-importer-for-blogger'); ?></dt><dd><?php echo !empty($settings['download_images']) ? esc_html__('Yes', 'bat-importer-for-blogger') : esc_html__('No', 'bat-importer-for-blogger'); ?></dd></div>
                                <div><dt><?php esc_html_e('Import pages', 'bat-importer-for-blogger'); ?></dt><dd><?php echo !empty($settings['import_pages']) ? esc_html__('Yes', 'bat-importer-for-blogger') : esc_html__('No', 'bat-importer-for-blogger'); ?></dd></div>
                            </dl>
                        </div>
                    </div>
                </section>

                <section class="mhbi-panel" id="mhbi-panel-logs" data-panel="logs" role="tabpanel" aria-labelledby="mhbi-tab-logs" hidden>
                    <div class="mhbi-card mhbi-log-card">
                        <div class="mhbi-log-card__header">
                            <div>
                                <span class="mhbi-step"><?php esc_html_e('Live log', 'bat-importer-for-blogger'); ?></span>
                                <h2><?php esc_html_e('Activity log', 'bat-importer-for-blogger'); ?></h2>
                                <p><?php esc_html_e('Batch messages are appended here while the import runs in the browser.', 'bat-importer-for-blogger'); ?></p>
                            </div>
                            <button type="button" class="button button-secondary" id="mhbi-clear-log-btn"><?php esc_html_e('Clear log', 'bat-importer-for-blogger'); ?></button>
                        </div>
                        <pre id="mhbi-log" aria-live="polite"></pre>
                    </div>
                </section>
            </div>
        </div>
        <?php
    }
}
