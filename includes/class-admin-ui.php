<?php

/**
 * Admin UI
 * 
 * Adds bulk generation interface to the preset admin page
 */

if (! defined('ABSPATH')) {
    exit;
}

class MKL_PC_Preset_Generator_Admin_UI
{

    private static $instance = null;
    private $run_manager;

    /**
     * Get singleton instance
     */
    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        $this->run_manager = MKL_PC_Preset_Run_Manager::instance();

        // Use the same hooks as the preset admin system
        add_action('mkl_pc_scripts_product_page_after', [$this, 'enqueue_scripts']);
        add_action('mkl_pc_frontend_templates_after', [$this, 'add_ui_templates']);

        // AJAX handlers
        add_action('wp_ajax_mkl_pc_generate_presets_estimate', [$this, 'ajax_estimate']);
        add_action('wp_ajax_mkl_pc_begin_generation_run', [$this, 'ajax_begin_run']);
        add_action('wp_ajax_mkl_pc_cancel_generation_run', [$this, 'ajax_cancel_run']);
        add_action('wp_ajax_mkl_pc_generate_presets_batch', [$this, 'ajax_generate_batch']);
        add_action('wp_ajax_mkl_pc_get_preset_snapshot', [$this, 'ajax_get_preset_snapshot']);
        add_action('wp_ajax_mkl_pc_save_expanded_preset', [$this, 'ajax_save_expanded_preset']);
        add_action('wp_ajax_mkl_pc_delete_all_presets', [$this, 'ajax_delete_all']);

        // Ensure the Configuration image meta box shows a preview even if subsizes are missing.
        add_filter('admin_post_thumbnail_html', [$this, 'override_configuration_image_box'], 10, 2);

        // Block editor and media modal often rely on attachment sizes. In CLI-created
        // attachments, subsizes may be missing (no GD/Imagick). Inject safe fallbacks
        // so the preview still appears in the Featured/Configuration image panel.
        add_filter('wp_prepare_attachment_for_js', [$this, 'inject_sizes_fallback'], 10, 2);
        add_filter('rest_prepare_attachment', [$this, 'inject_rest_sizes_fallback'], 10, 3);
    }

    /**
     * Override the featured image box HTML for our CPT when subsizes are missing,
     * falling back to the full image so editors still see a preview.
     */
    public function override_configuration_image_box($content, $post_id)
    {
        $post = get_post($post_id);
        if (! $post || $post->post_type !== 'mkl_pc_configuration') {
            return $content;
        }

        $thumb_id = get_post_thumbnail_id($post_id);
        if (! $thumb_id) {
            return $content;
        }

        $meta = wp_get_attachment_metadata($thumb_id);
        $has_sizes = is_array($meta) && ! empty($meta['sizes']);

        if ($has_sizes) {
            return $content;
        }

        $url = wp_get_attachment_url($thumb_id);
        if (! $url) {
            return $content;
        }

        $fallback = '<p><img src="' . esc_url($url) . '" alt="" style="max-width:150px;height:auto;border:1px solid #ccd0d4;"/></p>';
        return $fallback . $content;
    }

    /**
     * Ensure attachment JS object has sizes for media modal/block editor previews.
     * When CLI created the image without subsizes, provide synthetic sizes pointing to the full URL.
     */
    public function inject_sizes_fallback($response, $attachment)
    {
        if (!is_array($response)) {
            return $response;
        }

        // Only images
        if (empty($response['type']) || $response['type'] !== 'image') {
            return $response;
        }

        // If sizes already present, leave as-is
        if (!empty($response['sizes']) && is_array($response['sizes'])) {
            return $response;
        }

        $attachment_id = isset($response['id']) ? intval($response['id']) : (isset($attachment->ID) ? intval($attachment->ID) : 0);
        if (!$attachment_id) {
            return $response;
        }

        $meta = wp_get_attachment_metadata($attachment_id);
        // Limit to our generator images to avoid touching other media
        $file_rel = is_array($meta) && !empty($meta['file']) ? (string) $meta['file'] : '';
        if ($file_rel === '' || strpos($file_rel, 'mkl-pc-config-images/') === false) {
            return $response;
        }

        $url = wp_get_attachment_url($attachment_id);
        if (!$url) {
            return $response;
        }

        $width  = is_array($meta) && !empty($meta['width']) ? intval($meta['width']) : 0;
        $height = is_array($meta) && !empty($meta['height']) ? intval($meta['height']) : 0;

        if ($width <= 0 || $height <= 0) {
            $file_abs = get_attached_file($attachment_id);
            if ($file_abs && file_exists($file_abs)) {
                $size = @getimagesize($file_abs);
                if (is_array($size) && !empty($size[0]) && !empty($size[1])) {
                    $width = intval($size[0]);
                    $height = intval($size[1]);
                }
            }
        }

        // Build minimal sizes pointing to the full image URL
        $thumb_w = min(150, $width ?: 150);
        $thumb_h = min(150, $height ?: 150);
        $medium_w = min(300, $width ?: 300);
        $medium_h = min(300, $height ?: 300);

        $fallback_sizes = [
            'thumbnail' => [
                'url' => $url,
                'width' => $thumb_w,
                'height' => $thumb_h,
                'orientation' => ($thumb_w >= $thumb_h) ? 'landscape' : 'portrait',
            ],
            'medium' => [
                'url' => $url,
                'width' => $medium_w,
                'height' => $medium_h,
                'orientation' => ($medium_w >= $medium_h) ? 'landscape' : 'portrait',
            ],
        ];

        $response['sizes'] = $fallback_sizes;
        return $response;
    }

    /**
     * Also patch REST responses for attachments so Gutenberg uses the fallback sizes.
     */
    public function inject_rest_sizes_fallback($response, $post, $request)
    {
        if (!($response instanceof WP_REST_Response)) {
            return $response;
        }

        $data = $response->get_data();
        if (empty($data) || empty($data['media_type']) || $data['media_type'] !== 'image') {
            return $response;
        }

        $details = isset($data['media_details']) && is_array($data['media_details']) ? $data['media_details'] : [];
        $sizes   = isset($details['sizes']) ? $details['sizes'] : [];
        if (!empty($sizes)) {
            return $response; // sizes already present
        }

        $source_url = isset($data['source_url']) ? $data['source_url'] : '';
        if ($source_url === '') {
            return $response;
        }

        $file_rel = isset($details['file']) ? (string) $details['file'] : '';
        if ($file_rel === '' || strpos($file_rel, 'mkl-pc-config-images/') === false) {
            return $response;
        }

        $width  = isset($details['width']) ? intval($details['width']) : 0;
        $height = isset($details['height']) ? intval($details['height']) : 0;
        $thumb_w = min(150, $width ?: 150);
        $thumb_h = min(150, $height ?: 150);
        $medium_w = min(300, $width ?: 300);
        $medium_h = min(300, $height ?: 300);

        $details['sizes'] = [
            'thumbnail' => [
                'file' => basename($file_rel),
                'width' => $thumb_w,
                'height' => $thumb_h,
                'mime_type' => isset($data['mime_type']) ? $data['mime_type'] : 'image/png',
                'source_url' => $source_url,
            ],
            'medium' => [
                'file' => basename($file_rel),
                'width' => $medium_w,
                'height' => $medium_h,
                'mime_type' => isset($data['mime_type']) ? $data['mime_type'] : 'image/png',
                'source_url' => $source_url,
            ],
        ];

        $data['media_details'] = $details;
        $response->set_data($data);
        return $response;
    }

    /**
     * Add UI templates to preset admin page
     */
    public function add_ui_templates()
    {
        // This hook only fires on preset admin pages, so no need for extra checks
        $product_id = isset($_REQUEST['pc-presets-admin']) ? intval($_REQUEST['pc-presets-admin']) : 0;

        if (! $product_id) {
            return;
        }
?>
        <style>
            .mkl-pc-bulk-generator {
                background: #f8f9fa;
                border: 1px solid #dee2e6;
                border-radius: 4px;
                padding: 20px;
                margin-bottom: 20px;
            }

            .mkl-pc-bulk-generator h3 {
                margin-top: 0;
                color: #333;
            }

            .mkl-pc-bulk-generator-actions {
                display: flex;
                gap: 10px;
                align-items: center;
                flex-wrap: wrap;
            }

            .mkl-pc-bulk-generator-actions button {
                padding: 10px 20px;
                font-size: 14px;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                transition: all 0.3s;
            }

            .mkl-pc-bulk-generator-actions button.primary {
                background: #0073aa;
                color: white;
            }

            .mkl-pc-bulk-generator-actions button.primary:hover {
                background: #005177;
            }

            .mkl-pc-bulk-generator-actions button.secondary {
                background: #6c757d;
                color: white;
            }

            .mkl-pc-bulk-generator-actions button.secondary:hover {
                background: #545b62;
            }

            .mkl-pc-bulk-generator-actions button.danger {
                background: #dc3545;
                color: white;
            }

            .mkl-pc-bulk-generator-actions button.danger:hover {
                background: #c82333;
            }

            .mkl-pc-bulk-generator-actions button:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }

            .mkl-pc-bulk-generator-info {
                margin: 0;
                padding: 0;
                background: transparent;
                border-radius: 0;
                font-size: 13px;
            }

            .mkl-pc-bulk-panels {
                display: grid;
                gap: 15px;
                margin-top: 15px;
                grid-template-columns: 1fr;
            }

            .mkl-pc-bulk-panel {
                background: #ffffff;
                border-radius: 6px;
                box-shadow: 0 1px 2px rgba(15, 23, 42, 0.08);
                padding: 16px;
            }

            .mkl-pc-bulk-generator-progress {
                margin-top: 15px;
                display: none;
            }

            .mkl-pc-bulk-generator-progress.active {
                display: block;
            }

            .progress-bar-container {
                width: 100%;
                height: 30px;
                background: #e9ecef;
                border-radius: 4px;
                overflow: hidden;
                position: relative;
            }

            .progress-bar {
                height: 100%;
                background: linear-gradient(90deg, #0073aa, #00a0d2);
                transition: width 0.3s;
                display: flex;
                align-items: center;
                justify-content: center;
                color: white;
                font-weight: bold;
                font-size: 12px;
            }

            .progress-status {
                margin-top: 10px;
                font-size: 13px;
                color: #666;
            }

            .mkl-pc-bulk-stats {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                gap: 10px;
                margin-top: 10px;
            }

            .mkl-pc-bulk-stat {
                background: white;
                padding: 10px;
                border-radius: 4px;
                text-align: center;
            }

            .mkl-pc-bulk-stat-value {
                font-size: 24px;
                font-weight: bold;
                color: #0073aa;
            }

            .mkl-pc-bulk-stat-label {
                font-size: 11px;
                color: #666;
                text-transform: uppercase;
                margin-top: 5px;
            }

            .mkl-pc-estimate-results {
                margin-top: 16px;
                padding: 12px;
                border: 1px solid #e2e8f0;
                border-radius: 6px;
                background: #ffffff;
            }

            .mkl-pc-estimate-results .estimate-label {
                font-size: 12px;
                text-transform: uppercase;
                letter-spacing: 0.06em;
                color: #64748b;
                margin-bottom: 4px;
            }

            .mkl-pc-estimate-results .estimate-value {
                font-size: 14px;
                color: #0f172a;
                line-height: 1.4;
            }

            .mkl-pc-estimate-results .estimate-value.estimate--success {
                color: #047857;
            }

            .mkl-pc-estimate-results .estimate-value.estimate--info {
                color: #0f172a;
            }

            .mkl-pc-estimate-results .estimate-value.estimate--warn {
                color: #b7791f;
            }

            .mkl-pc-estimate-results .estimate-value.estimate--error {
                color: #dc2626;
            }

            .mkl-pc-bulk-generator-actions button.stop {
                background: #f0ad4e;
                color: #1f2d3d;
            }

            .mkl-pc-bulk-generator-actions button.stop:hover {
                background: #d79531;
            }

            .mkl-pc-bulk-generator-actions button.stop:disabled {
                background: #fde2af;
                color: #a3741c;
            }

            .mkl-pc-bulk-live {
                display: flex;
                flex-direction: column;
                gap: 12px;
            }

            .mkl-pc-bulk-live-status {
                display: flex;
                justify-content: space-between;
                align-items: center;
                font-size: 13px;
                color: #4a5568;
            }

            .mkl-pc-bulk-live-status span:first-child {
                font-weight: 600;
                letter-spacing: 0.02em;
                text-transform: uppercase;
                font-size: 11px;
                color: #64748b;
            }

            .mkl-pc-bulk-live-status .status-text {
                font-weight: 600;
            }

            .status-text.status--info {
                color: #1f2937;
            }

            .status-text.status--success {
                color: #217a2c;
            }

            .status-text.status--warn {
                color: #b7791f;
            }

            .status-text.status--error {
                color: #c53030;
            }

            .mkl-pc-bulk-live-stats {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
                gap: 10px;
            }

            .mkl-pc-bulk-live-card {
                background: #f8fafc;
                border-radius: 6px;
                padding: 10px 12px;
                text-align: center;
            }

            .mkl-pc-bulk-live-card .value {
                font-size: 20px;
                font-weight: 600;
                color: #006799;
            }

            .mkl-pc-bulk-live-card .label {
                font-size: 11px;
                text-transform: uppercase;
                color: #64748b;
                letter-spacing: 0.03em;
                margin-top: 4px;
            }

            .mkl-pc-bulk-live-log {
                border: 1px solid #e2e8f0;
                border-radius: 6px;
                background: #ffffff;
                max-height: 160px;
                overflow: hidden;
                overflow-x: hidden;
            }

            .mkl-pc-bulk-live-log ul {
                list-style: none;
                margin: 0;
                padding: 10px 12px;
                max-height: 160px;
                overflow-y: auto;
                display: flex;
                flex-direction: column;
                gap: 6px;
                word-break: break-word;
            }

            .mkl-pc-bulk-live-log li {
                font-size: 12px;
                display: grid;
                grid-template-columns: minmax(0, 1fr) auto;
                align-items: start;
                gap: 8px;
                color: #334155;
                word-break: break-word;
            }

            .mkl-pc-bulk-live-log li .timestamp {
                color: #9ca3af;
                font-variant-numeric: tabular-nums;
                white-space: nowrap;
                justify-self: end;
            }

            @media (max-width: 640px) {
                .mkl-pc-bulk-live-log li {
                    grid-template-columns: 1fr;
                }

                .mkl-pc-bulk-live-log li .timestamp {
                    justify-self: start;
                }
            }

            .log-entry--success {
                color: #1f7a3d;
            }

            .log-entry--warn {
                color: #b7791f;
            }

            .log-entry--error {
                color: #c53030;
            }
        </style>

        <script type="text/html" id="tmpl-mkl-pc-bulk-generator-ui">
            <div class="mkl-pc-bulk-generator">
                <h3><?php esc_html_e('Bulk Preset Generator', 'mkl-pc-preset-generator'); ?></h3>
                <p><?php esc_html_e('Automatically generate all valid configuration combinations based on conditional logic rules.', 'mkl-pc-preset-generator'); ?></p>

                <div class="mkl-pc-bulk-generator-actions">
                    <button type="button" class="mkl-pc-generate-btn primary" data-product-id="<?php echo esc_attr($product_id); ?>" disabled>
                        <?php esc_html_e('Generate All Presets', 'mkl-pc-preset-generator'); ?>
                    </button>
                    <button type="button" class="mkl-pc-estimate-btn secondary" data-product-id="<?php echo esc_attr($product_id); ?>">
                        <?php esc_html_e('Estimate Valid Presets', 'mkl-pc-preset-generator'); ?>
                    </button>
                    <button type="button" class="mkl-pc-delete-all-btn danger" data-product-id="<?php echo esc_attr($product_id); ?>">
                        <?php esc_html_e('Delete All Presets', 'mkl-pc-preset-generator'); ?>
                    </button>
                    <button type="button" class="mkl-pc-stop-btn stop" disabled>
                        <?php esc_html_e('Stop Run', 'mkl-pc-preset-generator'); ?>
                    </button>
                </div>

                <div class="mkl-pc-bulk-panels">
                    <div class="mkl-pc-bulk-panel">
                        <div class="mkl-pc-bulk-generator-info">
                            <div class="mkl-pc-bulk-stats">
                                <div class="mkl-pc-bulk-stat">
                                    <div class="mkl-pc-bulk-stat-value" data-stat="existing">-</div>
                                    <div class="mkl-pc-bulk-stat-label"><?php esc_html_e('Existing Presets', 'mkl-pc-preset-generator'); ?></div>
                                </div>
                                <div class="mkl-pc-bulk-stat">
                                    <div class="mkl-pc-bulk-stat-value" data-stat="generated">0</div>
                                    <div class="mkl-pc-bulk-stat-label"><?php esc_html_e('Generated', 'mkl-pc-preset-generator'); ?></div>
                                </div>
                            </div>
                            <div class="mkl-pc-estimate-results">
                                <div class="estimate-label"><?php esc_html_e('Estimate', 'mkl-pc-preset-generator'); ?></div>
                                <div class="estimate-value" data-live="estimate-output"><?php esc_html_e('Tap estimate to calculate.', 'mkl-pc-preset-generator'); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="mkl-pc-bulk-panel mkl-pc-bulk-live">
                        <div class="mkl-pc-bulk-live-status">
                            <span><?php esc_html_e('Status', 'mkl-pc-preset-generator'); ?></span>
                            <span class="status-text status--info" data-live="status"><?php esc_html_e('Ready to start.', 'mkl-pc-preset-generator'); ?></span>
                        </div>
                        <div class="mkl-pc-bulk-live-stats">
                            <div class="mkl-pc-bulk-live-card">
                                <div class="value" data-live="elapsed">0:00</div>
                                <div class="label"><?php esc_html_e('Elapsed', 'mkl-pc-preset-generator'); ?></div>
                            </div>
                            <div class="mkl-pc-bulk-live-card">
                                <div class="value" data-live="rate">0</div>
                                <div class="label"><?php esc_html_e('Presets / min', 'mkl-pc-preset-generator'); ?></div>
                            </div>
                            <div class="mkl-pc-bulk-live-card">
                                <div class="value" data-live="avg-apply">-</div>
                                <div class="label"><?php esc_html_e('Avg Apply', 'mkl-pc-preset-generator'); ?></div>
                            </div>
                            <div class="mkl-pc-bulk-live-card">
                                <div class="value" data-live="avg-save">-</div>
                                <div class="label"><?php esc_html_e('Avg Save', 'mkl-pc-preset-generator'); ?></div>
                            </div>
                            <div class="mkl-pc-bulk-live-card">
                                <div class="value" data-live="async-thumbs">0</div>
                                <div class="label"><?php esc_html_e('Async Thumbs', 'mkl-pc-preset-generator'); ?></div>
                            </div>
                            <div class="mkl-pc-bulk-live-card">
                                <div class="value" data-live="skipped-duplicates">0</div>
                                <div class="label"><?php esc_html_e('Skipped (Dup)', 'mkl-pc-preset-generator'); ?></div>
                            </div>
                        </div>
                        <div class="mkl-pc-bulk-live-log">
                            <ul data-live-log>
                                <li class="log-entry log-entry--info">
                                    <span><?php esc_html_e('Activity will appear here once generation starts.', 'mkl-pc-preset-generator'); ?></span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="mkl-pc-bulk-generator-progress">
                    <div class="progress-bar-container">
                        <div class="progress-bar" style="width: 0%;">0%</div>
                    </div>
                    <div class="progress-status"></div>
                </div>
            </div>
        </script>
<?php
    }

    /**
     * Enqueue scripts
     */
    public function enqueue_scripts()
    {
        // This hook only fires on preset admin pages, so no need for extra checks
        if (! isset($_REQUEST['pc-presets-admin'])) {
            return;
        }

        $product_id = intval($_REQUEST['pc-presets-admin']);

        wp_enqueue_script(
            'mkl-pc-bulk-generator',
            MKL_PC_PRESET_GENERATOR_URL . 'assets/js/bulk-generator.js',
            ['jquery', 'backbone', 'wp-hooks'],
            MKL_PC_PRESET_GENERATOR_VERSION,
            true
        );

        error_log('MKL PC Bulk Generator: Script URL: ' . MKL_PC_PRESET_GENERATOR_URL . 'assets/js/bulk-generator.js');

        global $wpdb;
        $existing_total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = %s AND post_status = %s",
                $product_id,
                'mkl_pc_configuration',
                'preset'
            )
        );

        wp_localize_script('mkl-pc-bulk-generator', 'MKL_PC_BulkGenerator', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mkl_pc_bulk_generator'),
            'strings' => [
                'preparing' => __('Preparing preset run...', 'mkl-pc-preset-generator'),
                'generating' => __('Generating presets...', 'mkl-pc-preset-generator'),
                'complete' => __('Generation complete!', 'mkl-pc-preset-generator'),
                'error' => __('An error occurred', 'mkl-pc-preset-generator'),
                'ready' => __('Ready to start.', 'mkl-pc-preset-generator'),
                'searching' => __('Searching for valid combinations...', 'mkl-pc-preset-generator'),
                'saving' => __('Saving preset...', 'mkl-pc-preset-generator'),
                'cancelling' => __('Cancelling...', 'mkl-pc-preset-generator'),
                'cancelled' => __('Generation cancelled by user.', 'mkl-pc-preset-generator'),
                'deleting' => __('Deleting presets...', 'mkl-pc-preset-generator'),
                'deleted' => __('Presets deleted.', 'mkl-pc-preset-generator'),
                'log_empty' => __('Activity will appear here once generation starts.', 'mkl-pc-preset-generator'),
                'confirm_start' => __('Start generating all valid preset combinations?', 'mkl-pc-preset-generator'),
                'confirm_delete' => __('Are you sure you want to delete all presets for this product? This cannot be undone.', 'mkl-pc-preset-generator'),
                'estimate_action' => __('Estimate Valid Presets', 'mkl-pc-preset-generator'),
                'estimating' => __('Estimating...', 'mkl-pc-preset-generator'),
                'estimate_prompt' => __('Tap estimate to calculate.', 'mkl-pc-preset-generator'),
                'estimate_complete' => __('Estimate complete.', 'mkl-pc-preset-generator'),
                'estimate_failed' => __('Failed to estimate presets.', 'mkl-pc-preset-generator'),
            ],
            'batch_size' => (int) apply_filters('mkl_pc_preset_generator_batch_size', 50, $product_id),
            'existing_total' => $existing_total,
            'debug' => true,
        ]);

        error_log('MKL PC Bulk Generator: Scripts enqueued and localized');
    }

    /**
     * AJAX: Snapshot of existing presets for a product.
     */
    public function ajax_get_preset_snapshot()
    {
        check_ajax_referer('mkl_pc_bulk_generator', 'nonce');

        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'mkl-pc-preset-generator')]);
        }

        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;

        if (! $product_id) {
            wp_send_json_error(['message' => __('Invalid product ID', 'mkl-pc-preset-generator')]);
        }

        global $wpdb;

        $include_titles = true;
        if (isset($_POST['include_titles'])) {
            $include_titles = filter_var($_POST['include_titles'], FILTER_VALIDATE_BOOLEAN);
        }

        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = %s AND post_status = %s",
                $product_id,
                'mkl_pc_configuration',
                'preset'
            )
        );

        $titles = [];
        if ($include_titles && $total > 0) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT post_title FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = %s AND post_status = %s",
                    $product_id,
                    'mkl_pc_configuration',
                    'preset'
                ),
                ARRAY_A
            );

            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if (isset($row['post_title'])) {
                        $titles[] = $row['post_title'];
                    }
                }
            }
        }

        wp_send_json_success([
            'product_id' => $product_id,
            'count' => $total,
            'titles' => array_values($titles),
            'titles_included' => $include_titles,
        ]);
    }

    /**
     * AJAX: Estimate combinations
     */
    public function ajax_estimate()
    {
        try {
            check_ajax_referer('mkl_pc_bulk_generator', 'nonce');

            if (! current_user_can('manage_woocommerce')) {
                wp_send_json_error(['message' => __('Permission denied', 'mkl-pc-preset-generator')]);
            }

            $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;

            if (! $product_id) {
                wp_send_json_error(['message' => __('Invalid product ID', 'mkl-pc-preset-generator')]);
            }

            $generator = new MKL_PC_Preset_Combination_Generator($product_id);
            $saver = new MKL_PC_Preset_Saver($product_id);

            // Get all layers with their choice counts
            $layers = $generator->get_user_layers();
            $layer_info = [];
            $simple_estimate = 1;

            error_log("=== ANALYZING LAYERS ===");
            error_log("Total user-facing layers found: " . count($layers));

            foreach ($layers as $layer) {
                $choices = $generator->get_layer_choices($layer['_id']);
                $choice_count = count(array_filter($choices, function ($c) {
                    return !isset($c['is_group']) || !$c['is_group'];
                }));

                // Add 1 for "no selection" if not required
                $is_required = isset($layer['required']) && !empty($layer['required']);
                if (!$is_required && $layer['type'] === 'simple') {
                    $choice_count++;
                }

                error_log(sprintf(
                    "Layer: %-30s | Choices: %d | Required: %s",
                    $layer['name'],
                    $choice_count,
                    $is_required ? 'YES' : 'NO'
                ));

                $layer_info[] = [
                    'id' => $layer['_id'],
                    'name' => $layer['name'],
                    'choices' => $choice_count,
                    'required' => $is_required
                ];

                $simple_estimate *= $choice_count;
            }

            error_log("RAW CALCULATION: " . number_format($simple_estimate) . " combinations");

            $validator = new MKL_PC_Preset_Conditional_Validator($product_id);
            $existing = $saver->get_preset_count();
            $total_theoretical = $simple_estimate;

            $estimate_data = $this->estimate_valid_configurations(
                $generator,
                $validator,
                $total_theoretical,
                $product_id
            );

            $pass_rate_percent = isset($estimate_data['pass_rate'])
                ? round($estimate_data['pass_rate'] * 100, 2)
                : 0;

            if (!empty($estimate_data['exact'])) {
                $message = sprintf(
                    __('Exact: %1$s valid of %2$s combinations checked.', 'mkl-pc-preset-generator'),
                    number_format($estimate_data['valid_total']),
                    number_format($estimate_data['checked_total'])
                );
            } else {
                $lower = isset($estimate_data['lower_ci'])
                    ? max(0, round($estimate_data['lower_ci']))
                    : 0;
                $upper = isset($estimate_data['upper_ci'])
                    ? max(0, round($estimate_data['upper_ci']))
                    : 0;
                $estimate_text = isset($estimate_data['valid_estimate'])
                    ? number_format(round($estimate_data['valid_estimate']))
                    : '0';

                if ($upper > 0 && $lower > 0) {
                    $message = sprintf(
                        __('Estimated: %1$s valid (95%% CI: %2$s – %3$s) based on %4$s samples.', 'mkl-pc-preset-generator'),
                        $estimate_text,
                        number_format($lower),
                        number_format($upper),
                        number_format($estimate_data['samples'])
                    );
                } else {
                    $message = sprintf(
                        __('Estimated: %1$s valid based on %2$s samples.', 'mkl-pc-preset-generator'),
                        $estimate_text,
                        number_format($estimate_data['samples'])
                    );
                }
            }

            wp_send_json_success([
                'valid_count' => isset($estimate_data['valid_total'])
                    ? $estimate_data['valid_total']
                    : (isset($estimate_data['valid_estimate']) ? round($estimate_data['valid_estimate']) : 0),
                'total_checked' => isset($estimate_data['checked_total'])
                    ? $estimate_data['checked_total']
                    : (isset($estimate_data['samples']) ? $estimate_data['samples'] : 0),
                'existing' => $existing,
                'layers' => $layer_info,
                'total_layers' => count($layers),
                'total_possible' => $total_theoretical,
                'pass_rate' => $pass_rate_percent,
                'estimate' => $estimate_data,
                'message' => $message,
            ]);
        } catch (Exception $e) {
            error_log('MKL PC Bulk Generator Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Begin or join a bulk generation run.
     */
    public function ajax_begin_run()
    {
        try {
            check_ajax_referer('mkl_pc_bulk_generator', 'nonce');

            if (! current_user_can('manage_woocommerce')) {
                wp_send_json_error(['message' => __('Permission denied', 'mkl-pc-preset-generator')]);
            }

            $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
            if (! $product_id) {
                wp_send_json_error(['message' => __('Invalid product ID', 'mkl-pc-preset-generator')]);
            }

            $requested_chunk = isset($_POST['chunk_size']) ? intval($_POST['chunk_size']) : null;
            $force_new = !empty($_POST['force_new']);

            $payload = $this->run_manager->begin_run($product_id, [
                'chunk_size' => $requested_chunk,
                'force_new' => $force_new,
            ]);

            wp_send_json_success([
                'run' => $payload,
            ]);
        } catch (Exception $e) {
            error_log('MKL PC Bulk Generator Begin Run Error: ' . $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Cancel an active bulk generation run.
     */
    public function ajax_cancel_run()
    {
        try {
            check_ajax_referer('mkl_pc_bulk_generator', 'nonce');

            if (! current_user_can('manage_woocommerce')) {
                wp_send_json_error(['message' => __('Permission denied', 'mkl-pc-preset-generator')]);
            }

            $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
            $run_id = isset($_POST['run_id']) ? sanitize_text_field(wp_unslash($_POST['run_id'])) : '';

            if (! $product_id || $run_id === '') {
                wp_send_json_error(['message' => __('Invalid cancel request.', 'mkl-pc-preset-generator')]);
            }

            $payload = $this->run_manager->cancel_run($product_id, $run_id);
            if (! $payload) {
                wp_send_json_error([
                    'message' => __('Run context no longer available.', 'mkl-pc-preset-generator'),
                    'code' => 'run_mismatch',
                ]);
            }

            wp_send_json_success([
                'run' => $payload,
                'message' => __('Generation cancelled.', 'mkl-pc-preset-generator'),
            ]);
        } catch (Exception $e) {
            error_log('MKL PC Bulk Generator Cancel Run Error: ' . $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Generate batch of presets
     */
    public function ajax_generate_batch()
    {
        $reservation_id = null;
        $assigned_offset = 0;
        $chunk_size = 0;
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $run_id = isset($_POST['run_id']) ? sanitize_text_field(wp_unslash($_POST['run_id'])) : '';

        try {
            check_ajax_referer('mkl_pc_bulk_generator', 'nonce');

            if (! current_user_can('manage_woocommerce')) {
                wp_send_json_error(['message' => __('Permission denied', 'mkl-pc-preset-generator')]);
            }

            if (! $product_id) {
                wp_send_json_error(['message' => __('Invalid product ID', 'mkl-pc-preset-generator')]);
            }

            if ($run_id === '') {
                wp_send_json_error([
                    'message' => __('Run context missing. Please start a new run.', 'mkl-pc-preset-generator'),
                    'code' => 'run_missing',
                ]);
            }

            $requested_batch = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 0;

            set_time_limit(300);

            $reservation_info = $this->run_manager->reserve_batch($product_id, $run_id, [
                'chunk_size' => $requested_batch,
            ]);

            if (!is_array($reservation_info) || 'mismatch' === $reservation_info['status']) {
                wp_send_json_error([
                    'message' => __('Run context no longer available. Please start a new run.', 'mkl-pc-preset-generator'),
                    'code' => 'run_mismatch',
                ]);
            }

            $reservation = isset($reservation_info['reservation']) ? $reservation_info['reservation'] : null;
            $payload = isset($reservation_info['state']) ? $reservation_info['state'] : [];

            if (! $reservation) {
                $message = !empty($reservation_info['message'])
                    ? $reservation_info['message']
                    : (!empty($payload['is_complete'])
                        ? __('Generation complete.', 'mkl-pc-preset-generator')
                        : __('Waiting for available batches...', 'mkl-pc-preset-generator'));

                wp_send_json_success([
                    'saved' => 0,
                    'skipped' => 0,
                    'offset' => isset($payload['next_offset']) ? $payload['next_offset'] : 0,
                    'total' => isset($payload['attempted_total']) ? $payload['attempted_total'] : 0,
                    'is_complete' => !empty($payload['is_complete']),
                    'progress' => 0,
                    'total_generated' => isset($payload['attempted_total']) ? $payload['attempted_total'] : 0,
                    'attempted_total' => isset($payload['attempted_total']) ? $payload['attempted_total'] : 0,
                    'saved_total' => isset($payload['saved_total']) ? $payload['saved_total'] : 0,
                    'run' => $payload,
                    'message' => $message,
                    'attempted_batch' => 0,
                    'saved_batch' => 0,
                    'valid_combinations' => [],
                    'chunk_size' => isset($payload['chunk_size']) ? $payload['chunk_size'] : 0,
                ]);
            }

            $reservation_id = $reservation['id'];
            $assigned_offset = isset($reservation['offset']) ? (int) $reservation['offset'] : 0;
            $chunk_size = isset($reservation['limit']) ? (int) $reservation['limit'] : (isset($payload['chunk_size']) ? (int) $payload['chunk_size'] : 0);

            $worker = new MKL_PC_Preset_Bulk_Worker($product_id, [
                'save_presets' => false,
                'expand_for_ui' => true,
            ]);

            $worker_result = $worker->process($assigned_offset, $chunk_size, [
                'run_id' => $run_id,
                'reservation_id' => $reservation_id,
            ]);

            $consumed = isset($worker_result['attempted']) ? (int) $worker_result['attempted'] : 0;
            $saved = isset($worker_result['prepared']) ? (int) $worker_result['prepared'] : 0;
            $skipped = isset($worker_result['skipped']) ? (int) $worker_result['skipped'] : 0;
            $valid_combinations = isset($worker_result['valid_combinations']) ? (array) $worker_result['valid_combinations'] : [];

            $updated_payload = $this->run_manager->complete_reservation($product_id, $run_id, $reservation_id, [
                'offset' => $assigned_offset,
                'limit' => $chunk_size,
                'attempted' => $consumed,
                'saved' => $saved,
                'skipped' => $skipped,
            ]);

            if ($updated_payload) {
                $payload = $updated_payload;
            } elseif (!is_array($payload)) {
                $payload = [];
            }

            $response = [
                'saved' => 0,
                'skipped' => $skipped,
                'offset' => isset($payload['next_offset']) ? $payload['next_offset'] : 0,
                'claimed_offset' => $assigned_offset,
                'total' => isset($payload['attempted_total']) ? $payload['attempted_total'] : 0,
                'is_complete' => !empty($payload['is_complete']),
                'progress' => 0,
                'total_generated' => isset($payload['attempted_total']) ? $payload['attempted_total'] : 0,
                'attempted_total' => isset($payload['attempted_total']) ? $payload['attempted_total'] : 0,
                'saved_total' => isset($payload['saved_total']) ? $payload['saved_total'] : 0,
                'safety_limit' => 0,
                'target_total' => 0,
                'run_limit' => 0,
                'message' => !empty($payload['is_complete'])
                    ? __('Generation complete.', 'mkl-pc-preset-generator')
                    : '',
                'attempted_batch' => $consumed,
                'saved_batch' => $saved,
                'valid_combinations' => $valid_combinations,
                'run' => $payload,
                'chunk_size' => $chunk_size,
            ];

            wp_send_json_success($response);
        } catch (Exception $e) {
            if ($reservation_id && $product_id) {
                $this->run_manager->release_reservation($product_id, $run_id, $reservation_id);
            }

            error_log('MKL PC Bulk Generator Batch Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Save expanded preset (called from frontend after PC.fe.save_data.save())
     */
    public function ajax_save_expanded_preset()
    {
        check_ajax_referer('mkl_pc_bulk_generator', 'nonce');

        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'mkl-pc-preset-generator')]);
        }

        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $preset_name = isset($_POST['preset_name']) ? sanitize_text_field($_POST['preset_name']) : '';
        $configuration = isset($_POST['configuration']) ? $_POST['configuration'] : '';
        $skip_thumbnail = !empty($_POST['skip_thumbnail']);

        if (! $product_id || ! $preset_name || ! $configuration) {
            wp_send_json_error(['message' => __('Missing required parameters', 'mkl-pc-preset-generator')]);
        }

        // Decode the configuration if it's a JSON string
        if (is_string($configuration)) {
            $configuration = json_decode(stripslashes($configuration), true);
        }

        $configuration = $this->normalize_configuration_layers($configuration);
        $normalized_title = trim(wp_strip_all_tags($preset_name));
        $config_hash = $this->generate_configuration_hash($configuration);

        if ($config_hash) {
            $existing_by_hash = $this->find_existing_preset_by_hash($product_id, $config_hash);
            if ($existing_by_hash) {
                error_log("Skipping expanded preset save. Duplicate configuration detected (#$existing_by_hash)");
                wp_send_json_error([
                    'message' => __('Duplicate preset configuration already exists', 'mkl-pc-preset-generator'),
                    'duplicate' => true,
                    'preset_id' => $existing_by_hash,
                    'reason' => 'configuration',
                ]);
            }
        }

        if ($normalized_title !== '') {
            $existing_by_title = $this->find_existing_preset_by_title($product_id, $normalized_title);
            if ($existing_by_title) {
                error_log("Skipping expanded preset save. Duplicate title detected (#$existing_by_title)");
                wp_send_json_error([
                    'message' => __('Duplicate preset title already exists', 'mkl-pc-preset-generator'),
                    'duplicate' => true,
                    'preset_id' => $existing_by_title,
                    'reason' => 'title',
                ]);
            }
        }

        error_log("Saving expanded preset: $preset_name with " . count($configuration) . " layers");

        // Save directly using the Configuration class (bypass our saver's combination-to-config conversion)
        try {
            $preset = new Mkl_PC_Preset_Configuration(0);
            $preset->save_image_async = true;
            $preset->should_save_image = true;

            $content_string = wp_json_encode($configuration);

            $saved = $preset->save([
                'content' => $content_string,
                'product_id' => $product_id,
                'customer_id' => get_current_user_id(),
                'title' => $preset_name,
                'configuration_id' => 0,
            ]);

            if (isset($saved['saved']) && $saved['saved']) {
                $preset_id = isset($saved['config_id']) ? intval($saved['config_id']) : (isset($saved['ID']) ? intval($saved['ID']) : 0);

                // Ensure post status is 'preset'
                wp_update_post([
                    'ID' => $preset_id,
                    'post_status' => 'preset',
                ]);

                if ($preset_id) {
                    update_post_meta($preset_id, '_product_id', $product_id);
                    if ($config_hash) {
                        update_post_meta($preset_id, '_config_hash', $config_hash);
                    }
                    if ($normalized_title !== '') {
                        update_post_meta($preset_id, '_preset_title_normalized', strtolower($normalized_title));
                    }
                }

                $save_image_async = isset($saved['save_image_async']) ? $saved['save_image_async'] : false;

                // Trigger image generation
                if (
                    !$skip_thumbnail &&
                    $save_image_async &&
                    is_array($save_image_async) &&
                    isset($save_image_async['should_save']) &&
                    $save_image_async['should_save'] &&
                    $preset_id
                ) {
                    $preset->save_image($preset->content, $preset_id);
                }

                error_log("Successfully saved expanded preset #$preset_id");
                $response = [
                    'preset_id' => $preset_id,
                    'message' => isset($saved['message']) ? $saved['message'] : '',
                    'thumbnail' => [
                        'mode' => ($save_image_async && is_array($save_image_async) && !empty($save_image_async['should_save']))
                            ? 'async'
                            : 'sync',
                    ],
                ];

                if ($save_image_async && is_array($save_image_async)) {
                    $response['thumbnail'] = array_merge($response['thumbnail'], $save_image_async);
                }

                wp_send_json_success($response);
            } else {
                $error_msg = isset($saved['error']) ? $saved['error'] : 'Unknown error';
                error_log("Failed to save expanded preset: $error_msg");
                wp_send_json_error(['message' => $error_msg]);
            }
        } catch (Exception $e) {
            error_log("Exception saving expanded preset: " . $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Delete all presets
     */
    public function ajax_delete_all()
    {
        check_ajax_referer('mkl_pc_bulk_generator', 'nonce');

        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'mkl-pc-preset-generator')]);
        }

        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;

        if (! $product_id) {
            wp_send_json_error(['message' => __('Invalid product ID', 'mkl-pc-preset-generator')]);
        }

        $saver = new MKL_PC_Preset_Saver($product_id);
        $deleted = $saver->delete_all_presets();

        $this->run_manager->clear_state($product_id);

        wp_send_json_success([
            'deleted' => $deleted,
        ]);
    }

    /**
     * Estimate valid configuration count using adaptive sampling or exhaustive evaluation.
     *
     * @param MKL_PC_Preset_Combination_Generator     $generator
     * @param MKL_PC_Preset_Conditional_Validator     $validator
     * @param float|int                               $total_theoretical
     * @param int                                     $product_id
     * @return array
     */
    private function estimate_valid_configurations(
        MKL_PC_Preset_Combination_Generator $generator,
        MKL_PC_Preset_Conditional_Validator $validator,
        $total_theoretical,
        $product_id
    ) {
        unset($generator, $validator);
        $smart_generator = new MKL_PC_Smart_Combination_Generator($product_id);

        $sample_limit = max(1, (int) apply_filters('mkl_pc_preset_generator_estimate_sample_limit', 5000, $product_id));
        $time_limit = max(0.1, (float) apply_filters('mkl_pc_preset_generator_estimate_time_limit', 5.0, $product_id));

        $attempted = 0;
        $valid = 0;
        $timed_out = false;
        $start = microtime(true);

        while ($attempted < $sample_limit && (microtime(true) - $start) < $time_limit) {
            $sample = $smart_generator->sample_random_combination();
            if (empty($sample)) {
                break;
            }

            $attempted++;
            if (!empty($sample['valid'])) {
                $valid++;
            }
        }

        $duration = microtime(true) - $start;
        if ($duration >= $time_limit && $attempted < $sample_limit) {
            $timed_out = true;
        }
        $pass_rate = $attempted > 0 ? $valid / $attempted : 0;

        $result = [
            'samples' => $attempted,
            'valid_samples' => $valid,
            'pass_rate' => $pass_rate,
            'duration' => round($duration, 3),
            'exact' => false,
            'truncated' => ($attempted < $sample_limit) || $timed_out,
        ];

        if ($pass_rate === 1.0 && $attempted >= $sample_limit) {
            $result['exact'] = true;
            $result['checked_total'] = $attempted;
            $result['valid_total'] = $attempted;
            $result['pass_rate'] = 1.0;

            return $result;
        }

        $valid_estimate = $pass_rate * $total_theoretical;
        $result['valid_estimate'] = (int) round(min($total_theoretical, max(0, $valid_estimate)));

        if ($attempted > 0) {
            list($lower_rate, $upper_rate) = $this->wilson_interval($valid, $attempted, 0.95);
            $lower_estimate = max(0, round($lower_rate * $total_theoretical));
            $upper_estimate = max($lower_estimate, round($upper_rate * $total_theoretical));
            $result['lower_ci'] = $lower_estimate;
            $result['upper_ci'] = $upper_estimate;
        } else {
            $result['lower_ci'] = 0;
            $result['upper_ci'] = 0;
        }

        return $result;
    }

    /**
     * Locate existing preset ID by configuration hash.
     *
     * @param int $product_id
     * @param string $config_hash
     * @return int
     */
    private function find_existing_preset_by_hash($product_id, $config_hash)
    {
        if (empty($config_hash)) {
            return 0;
        }

        $existing = get_posts([
            'post_type' => 'mkl_pc_configuration',
            'post_status' => 'preset',
            'post_parent' => $product_id,
            'meta_key' => '_config_hash',
            'meta_value' => $config_hash,
            'posts_per_page' => 1,
            'fields' => 'ids',
        ]);

        return !empty($existing) ? intval($existing[0]) : 0;
    }

    /**
     * Locate existing preset ID by exact title match (per product).
     *
     * @param int $product_id
     * @param string $title
     * @return int
     */
    private function find_existing_preset_by_title($product_id, $title)
    {
        $title = trim($title);
        if ($title === '') {
            return 0;
        }

        global $wpdb;

        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_title = %s AND post_type = %s AND post_status = %s AND post_parent = %d LIMIT 1",
            $title,
            'mkl_pc_configuration',
            'preset',
            $product_id
        ));

        return $existing_id ? intval($existing_id) : 0;
    }

    private function wilson_interval($successes, $trials, $confidence = 0.95)
    {
        if ($trials <= 0) {
            return [0.0, 0.0];
        }

        $z = 1.96; // defaults to 95%
        if ($confidence !== 0.95) {
            $z = $this->confidence_to_z($confidence);
        }

        $phat = max(0.0, min(1.0, $successes / $trials));
        $z2 = $z * $z;
        $denominator = 1 + ($z2 / $trials);
        $center = $phat + $z2 / (2 * $trials);
        $adjustment = $z * sqrt(($phat * (1 - $phat) + $z2 / (4 * $trials)) / $trials);

        $lower = ($center - $adjustment) / $denominator;
        $upper = ($center + $adjustment) / $denominator;

        return [
            max(0.0, $lower),
            min(1.0, $upper),
        ];
    }

    private function confidence_to_z($confidence)
    {
        // Simple mapping for common confidence levels; default to 1.96
        switch (round($confidence, 2)) {
            case 0.90:
                return 1.645;
            case 0.95:
                return 1.96;
            case 0.99:
                return 2.576;
            default:
                return 1.96;
        }
    }

    /**
     * Build a stable hash representation of the expanded configuration.
     *
     * @param array $configuration
     * @return string|null
     */
    private function generate_configuration_hash($configuration)
    {
        if (!is_array($configuration) || empty($configuration)) {
            return null;
        }

        $pairs = [];

        foreach ($configuration as $entry) {
            $is_choice = $this->get_layer_property($entry, 'is_choice');
            if (!$is_choice) {
                continue;
            }

            $layer_id = $this->get_layer_property($entry, 'layer_id');
            $choice_id = $this->get_layer_property($entry, 'choice_id');
            $choice_name = $this->get_layer_property($entry, 'name');

            if ($layer_id === null || $choice_id === null || $choice_id === '') {
                continue;
            }

            if (is_string($choice_name) && strtolower($choice_name) === 'none') {
                continue;
            }

            $layer_key = (string) $layer_id;
            $choice_key = (string) $choice_id;

            if ($layer_key === '' || $choice_key === '') {
                continue;
            }

            $pairs[] = $layer_key . ':' . $choice_key;
        }

        if (empty($pairs)) {
            return null;
        }

        sort($pairs, SORT_STRING);

        return md5(implode('|', $pairs));
    }

    /**
     * Ensure configuration layers follow image order to prevent incorrect overlay rendering
     *
     * @param array $configuration
     * @return array
     */
    private function normalize_configuration_layers($configuration)
    {
        if (!is_array($configuration) || empty($configuration)) {
            return $configuration;
        }

        $indexed = [];
        foreach ($configuration as $index => $layer) {
            $indexed[] = [
                'order' => $this->resolve_layer_order_value($layer),
                'index' => $index,
                'layer' => $layer,
            ];
        }

        usort($indexed, function ($a, $b) {
            if ($a['order'] === $b['order']) {
                return $a['index'] <=> $b['index'];
            }

            return ($a['order'] < $b['order']) ? -1 : 1;
        });

        return array_map(function ($item) {
            return $item['layer'];
        }, $indexed);
    }

    /**
     * Resolve ordering value for a configuration layer (array or object)
     *
     * @param mixed $layer
     * @return int
     */
    private function resolve_layer_order_value($layer)
    {
        $image_order = $this->get_layer_property($layer, 'image_order');
        if ($image_order !== null && $image_order !== '') {
            return intval($image_order);
        }

        $order = $this->get_layer_property($layer, 'order');
        if ($order !== null && $order !== '') {
            return intval($order);
        }

        $layer_id = $this->get_layer_property($layer, 'layer_id');
        if ($layer_id !== null && $layer_id !== '') {
            return intval($layer_id);
        }

        return 100000;
    }

    /**
     * Safely fetch property value from array or object
     *
     * @param mixed $layer
     * @param string $property
     * @return mixed|null
     */
    private function get_layer_property($layer, $property)
    {
        if (is_array($layer) && array_key_exists($property, $layer)) {
            return $layer[$property];
        }

        if (is_object($layer) && isset($layer->$property)) {
            return $layer->$property;
        }

        return null;
    }
}
