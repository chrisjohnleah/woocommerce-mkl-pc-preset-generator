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
        // Use the same hooks as the preset admin system
        add_action('mkl_pc_scripts_product_page_after', [$this, 'enqueue_scripts']);
        add_action('mkl_pc_frontend_templates_after', [$this, 'add_ui_templates']);

        // AJAX handlers
        add_action('wp_ajax_mkl_pc_generate_presets_estimate', [$this, 'ajax_estimate']);
        add_action('wp_ajax_mkl_pc_begin_generation_run', [$this, 'ajax_begin_run']);
        add_action('wp_ajax_mkl_pc_cancel_generation_run', [$this, 'ajax_cancel_run']);
        add_action('wp_ajax_mkl_pc_generate_presets_batch', [$this, 'ajax_generate_batch']);
        add_action('wp_ajax_mkl_pc_get_preset_snapshot', [$this, 'ajax_get_preset_snapshot']);
        add_action('wp_ajax_mkl_pc_list_layers_choices', [$this, 'ajax_list_layers_choices']);
        add_action('wp_ajax_mkl_pc_save_expanded_preset', [$this, 'ajax_save_expanded_preset']);
        add_action('wp_ajax_mkl_pc_delete_all_presets', [$this, 'ajax_delete_all']);
        add_action('wp_ajax_mkl_pc_preview_orphaned_presets', [$this, 'ajax_preview_orphaned_presets']);
        add_action('wp_ajax_mkl_pc_cleanup_orphaned_presets', [$this, 'ajax_cleanup_orphaned_presets']);
        add_action('wp_ajax_mkl_pc_generate_preset_thumbnail', [$this, 'ajax_generate_preset_thumbnail']);

        // High-priority compatibility override: ensure a thumbnail URL is returned for the core endpoint
        // without modifying upstream plugins. If we can produce a URL, we respond and short-circuit.
        add_action('wp_ajax_mkl_pc_generate_configuration_image', [$this, 'ajax_generate_configuration_image_compat'], 1);
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

            .mkl-pc-bulk-generator-actions button.warning {
                background: #ff9800;
                color: white;
            }

            .mkl-pc-bulk-generator-actions button.warning:hover {
                background: #e68900;
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
                    <button type="button" class="mkl-pc-cleanup-orphaned-btn warning" data-product-id="<?php echo esc_attr($product_id); ?>">
                        <?php esc_html_e('Clean Up Orphaned Presets', 'mkl-pc-preset-generator'); ?>
                    </button>
                    <button type="button" class="mkl-pc-stop-btn stop" disabled>
                        <?php esc_html_e('Stop Run', 'mkl-pc-preset-generator'); ?>
                    </button>
                </div>

                <div class="mkl-pc-bulk-panels">
                    <div class="mkl-pc-bulk-panel" id="mkl-pc-variations-panel">
                        <div class="mkl-pc-variations-header" style="display:flex;flex-direction:column;gap:4px;">
                            <strong><?php esc_html_e('Layer Variations', 'mkl-pc-preset-generator'); ?></strong>
                            <p class="description" style="margin:0;">
                                <?php esc_html_e('Pick up to two layers. When you start a generation run the plugin will iterate every valid choice for those layers on top of each base preset.', 'mkl-pc-preset-generator'); ?>
                            </p>
                        </div>
                        <div class="mkl-pc-variations-body" style="display:grid;gap:12px;margin-top:12px;">
                            <div class="mkl-pc-variations-layers" data-variation-layer-list></div>
                            <div class="mkl-pc-variations-options" style="display:flex;flex-wrap:wrap;gap:16px;align-items:center;">
                                <label style="display:flex;align-items:center;gap:6px;">
                                    <input type="checkbox" data-variation-include-base checked />
                                    <?php esc_html_e('Include the current selection', 'mkl-pc-preset-generator'); ?>
                                </label>
                                <label style="display:flex;align-items:center;gap:6px;">
                                    <?php esc_html_e('Max variations', 'mkl-pc-preset-generator'); ?>
                                    <input type="number" min="0" max="500" step="1" value="0" data-variation-limit style="width:90px;" />
                                </label>
                            </div>
                            <p class="description" data-variation-message></p>
                        </div>
                    </div>
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
                'variations_hint' => __('No variation layers selected. Base presets will be generated as normal.', 'mkl-pc-preset-generator'),
                'variations_active' => __('Layer variations active for: %s.', 'mkl-pc-preset-generator'),
                'variations_none' => __('No additional presets were queued for the chosen layers.', 'mkl-pc-preset-generator'),
                'variations_limit' => __('Variation limit reached – refine your selection or raise the limit.', 'mkl-pc-preset-generator'),
                'variations_select' => __('Select at least one layer to iterate.', 'mkl-pc-preset-generator'),
                'variations_max_layers' => __('Select no more than two layers for this tool.', 'mkl-pc-preset-generator'),
                'variations_invalid' => __('Select at least one layer or include the current selection.', 'mkl-pc-preset-generator'),
                'variations_summary' => __('Queued %1$s variation presets (skipped %2$s duplicates, %3$s invalid).', 'mkl-pc-preset-generator'),
            ],
            'batch_size' => (int) apply_filters('mkl_pc_preset_generator_batch_size', 50, $product_id),
            'existing_total' => $existing_total,
            'debug' => true,
        ]);

        error_log('MKL PC Bulk Generator: Scripts enqueued and localized');
    }

    /**
     * AJAX: Snapshot of existing presets for a product.
     * Uses batched queries to avoid memory exhaustion with thousands of presets.
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

        $include_hashes = true;
        if (isset($_POST['include_hashes'])) {
            $include_hashes = filter_var($_POST['include_hashes'], FILTER_VALIDATE_BOOLEAN);
        }

        // Fast count query
        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = %s AND post_status = %s",
                $product_id,
                'mkl_pc_configuration',
                'preset'
            )
        );

        $hashes = [];
        $titles = [];
        
        if ($include_hashes && $total > 0) {
            // Use batched queries to avoid memory exhaustion
            $batch_size = 1000;
            $offset = 0;
            
            do {
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT p.post_title, hash.meta_value AS config_hash
                         FROM {$wpdb->posts} p
                         LEFT JOIN {$wpdb->postmeta} hash
                            ON hash.post_id = p.ID
                           AND hash.meta_key = '_config_hash'
                         WHERE p.post_parent = %d
                           AND p.post_type = %s
                           AND p.post_status = %s
                         ORDER BY p.ID ASC
                         LIMIT %d OFFSET %d",
                        $product_id,
                        'mkl_pc_configuration',
                        'preset',
                        $batch_size,
                        $offset
                    ),
                    ARRAY_A
                );

                if (is_array($rows) && ! empty($rows)) {
                    foreach ($rows as $row) {
                        if (! empty($row['config_hash'])) {
                            $hashes[] = $row['config_hash'];
                        }
                        if (! empty($row['post_title'])) {
                            $titles[] = $row['post_title'];
                        }
                    }
                    
                    // Free memory after each batch
                    unset($rows);
                    
                    $offset += $batch_size;
                } else {
                    break;
                }
            } while (true);
        }

        wp_send_json_success([
            'product_id' => $product_id,
            'count' => $total,
            'hashes' => array_values($hashes),
            'titles' => array_values($titles),
            'hashes_included' => $include_hashes,
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
     * AJAX: List user-facing layers and choices for a product, to drive the Locks UI
     */
    public function ajax_list_layers_choices()
    {
        check_ajax_referer('mkl_pc_bulk_generator', 'nonce');

        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'mkl-pc-preset-generator')]);
        }

        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        if (! $product_id) {
            wp_send_json_error(['message' => __('Invalid product ID', 'mkl-pc-preset-generator')]);
        }

        try {
            $db = \MKL\PC\Plugin::instance()->db;
            $combination_generator = new MKL_PC_Preset_Combination_Generator($product_id);
            $user_layers = $combination_generator->get_user_layers();
            $content_rows = $db->get('content', $product_id);
            $content_map = [];
            if (is_array($content_rows)) {
                foreach ($content_rows as $row) {
                    if (isset($row['layerId'])) {
                        $content_map[$row['layerId']] = $row;
                    }
                }
            }

            $layers = [];
            foreach ((array)$user_layers as $layer) {
                $lid = isset($layer['_id']) ? $layer['_id'] : (isset($layer['id']) ? $layer['id'] : null);
                if (!$lid) continue;
                $lname = isset($layer['name']) ? $layer['name'] : '';
                $type = isset($layer['type']) ? $layer['type'] : 'simple';
                $is_required = !empty($layer['required']);
                $entry = isset($content_map[$lid]) ? $content_map[$lid] : [];
                $avail = isset($entry['choices']) && is_array($entry['choices']) ? $entry['choices'] : [];
                $choices = [];
                if (!$is_required && $type === 'simple') {
                    $choices[] = ['id' => null, 'name' => __('Any', 'mkl-pc-preset-generator')];
                }
                foreach ($avail as $choice) {
                    if (! empty($choice['is_group'])) continue;
                    $cid = isset($choice['id']) ? $choice['id'] : (isset($choice['_id']) ? $choice['_id'] : null);
                    if ($cid === null) continue;
                    $cname = isset($choice['name']) ? $choice['name'] : '';
                    $choices[] = ['id' => intval($cid), 'name' => $cname];
                }
                $layers[] = [
                    'id' => intval($lid),
                    'name' => $lname,
                    'choices' => $choices,
                ];
            }

            wp_send_json_success([
                'layers' => $layers,
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Begin or join a bulk generation run.
     */
    public function ajax_begin_run()
    {
        $lock_token = null;

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
            $constraints = [];
            if (!empty($_POST['constraints'])) {
                $raw = $_POST['constraints'];
                if (is_string($raw)) {
                    $decoded = json_decode(stripslashes($raw), true);
                    if (is_array($decoded)) $constraints = $decoded;
                } elseif (is_array($raw)) {
                    $constraints = $raw;
                }
                // sanitize
                $clean = [];
                foreach ($constraints as $lid => $vals) {
                    $lid = intval($lid);
                    if ($lid <= 0) continue;
                    if (is_array($vals)) {
                        $arr = array_values(array_unique(array_map('intval', $vals)));
                    } else {
                        $arr = [intval($vals)];
                    }
                    $arr = array_filter($arr, function ($v) {
                        return $v >= 0;
                    });
                    if (!empty($arr)) $clean[$lid] = $arr;
                }
                $constraints = $clean;
            }
            $force_new = !empty($_POST['force_new']);

            $variation_axes = [];
            if (isset($_POST['variation_axes'])) {
                $raw_axes = $_POST['variation_axes'];
                if (is_string($raw_axes)) {
                    $decoded_axes = json_decode(stripslashes($raw_axes), true);
                    if (is_array($decoded_axes)) {
                        $variation_axes = $decoded_axes;
                    }
                } elseif (is_array($raw_axes)) {
                    $variation_axes = $raw_axes;
                }
            }

            $variation_axes = array_map('intval', (array) $variation_axes);
            $variation_axes = array_filter($variation_axes, function ($value) {
                return $value > 0;
            });
            $variation_axes = array_values(array_unique($variation_axes));

            if (count($variation_axes) > 2) {
                wp_send_json_error([
                    'message' => __('Select no more than two layers for this tool.', 'mkl-pc-preset-generator'),
                ]);
            }

            $variation_include_base = true;
            if (isset($_POST['variation_include_base'])) {
                $raw_include = $_POST['variation_include_base'];
                if (is_string($raw_include)) {
                    $variation_include_base = in_array(strtolower($raw_include), ['1', 'true', 'yes'], true);
                } else {
                    $variation_include_base = (bool) intval($raw_include);
                }
            }

            if (empty($variation_axes) && ! $variation_include_base) {
                wp_send_json_error([
                    'message' => __('Select at least one layer or include the current selection.', 'mkl-pc-preset-generator'),
                ]);
            }

            $variation_limit = 0;
            if (isset($_POST['variation_limit'])) {
                $variation_limit = max(0, intval($_POST['variation_limit']));
            }

            $variation_axis_names = [];
            if (! empty($variation_axes)) {
                $layer_name_map = [];
                try {
                    $name_generator = new MKL_PC_Preset_Combination_Generator($product_id);
                    $all_layers = $name_generator->get_user_layers();
                    foreach ((array) $all_layers as $layer) {
                        if (! isset($layer['_id'])) {
                            continue;
                        }
                        $layer_id_int = (int) $layer['_id'];
                        $layer_name_map[$layer_id_int] = isset($layer['name']) ? $layer['name'] : '';
                    }
                } catch (Exception $e) {
                    error_log('Unable to resolve variation layer names: ' . $e->getMessage());
                }

                foreach ($variation_axes as $axis_id) {
                    $variation_axis_names[] = isset($layer_name_map[$axis_id]) && $layer_name_map[$axis_id] !== ''
                        ? $layer_name_map[$axis_id]
                        : sprintf(__('Layer %d', 'mkl-pc-preset-generator'), $axis_id);
                }
            }

            $lock_token = $this->acquire_run_lock($product_id);
            if (! $lock_token) {
                wp_send_json_error([
                    'message' => __('Preparing another batch. Please retry in a moment.', 'mkl-pc-preset-generator'),
                    'code' => 'run_busy',
                ]);
            }

            $state = $this->get_run_state($product_id);

            if ($force_new || $this->should_reset_run_state($state)) {
                $state = $this->create_run_state($product_id, $requested_chunk);
            } else {
                if (!is_array($state)) {
                    $state = $this->create_run_state($product_id, $requested_chunk);
                }
                if ($requested_chunk !== null && empty($state['chunk_size_locked'])) {
                    $state['chunk_size'] = $this->normalize_batch_size($requested_chunk, $product_id);
                    $state['chunk_size_locked'] = true;
                }
            }

            $state['updated_at'] = time();
            $state['constraints'] = $constraints;
            $state['variations'] = [
                'axes' => $variation_axes,
                'include_base' => $variation_include_base,
                'limit' => $variation_limit,
                'axis_names' => $variation_axis_names,
            ];

            $this->save_run_state($product_id, $state);
            $this->release_run_lock($product_id, $lock_token);

            wp_send_json_success([
                'run' => $this->prepare_run_payload($state),
            ]);
        } catch (Exception $e) {
            if ($lock_token) {
                $this->release_run_lock(isset($product_id) ? $product_id : 0, $lock_token);
            }
            error_log('MKL PC Bulk Generator Begin Run Error: ' . $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Cancel an active bulk generation run.
     */
    public function ajax_cancel_run()
    {
        $lock_token = null;

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

            $lock_token = $this->acquire_run_lock($product_id);
            if (! $lock_token) {
                wp_send_json_error([
                    'message' => __('Unable to cancel run at the moment. Please retry.', 'mkl-pc-preset-generator'),
                    'code' => 'run_busy',
                ]);
            }

            $state = $this->get_run_state($product_id);
            if (!is_array($state) || !isset($state['run_id']) || $state['run_id'] !== $run_id) {
                $this->release_run_lock($product_id, $lock_token);
                wp_send_json_error([
                    'message' => __('Run context no longer available.', 'mkl-pc-preset-generator'),
                    'code' => 'run_mismatch',
                ]);
            }

            $payload = $this->prepare_run_payload($state);
            $payload['cancelled'] = true;

            $this->clear_run_state($product_id);
            $this->release_run_lock($product_id, $lock_token);

            wp_send_json_success([
                'run' => $payload,
                'message' => __('Generation cancelled.', 'mkl-pc-preset-generator'),
            ]);
        } catch (Exception $e) {
            if ($lock_token) {
                $this->release_run_lock(isset($product_id) ? $product_id : 0, $lock_token);
            }
            error_log('MKL PC Bulk Generator Cancel Run Error: ' . $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Generate batch of presets
     */
    public function ajax_generate_batch()
    {
        $lock_token = null;
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

            $lock_token = $this->acquire_run_lock($product_id);
            if (! $lock_token) {
                wp_send_json_error([
                    'message' => __('Preparing another batch. Please retry in a moment.', 'mkl-pc-preset-generator'),
                    'code' => 'run_busy',
                ]);
            }

            $state = $this->get_run_state($product_id);
            if (!is_array($state) || empty($state['run_id']) || $state['run_id'] !== $run_id) {
                $this->release_run_lock($product_id, $lock_token);
                wp_send_json_error([
                    'message' => __('Run context no longer available. Please start a new run.', 'mkl-pc-preset-generator'),
                    'code' => 'run_mismatch',
                ]);
            }

            $this->cleanup_expired_reservations($state);

            if ($requested_batch > 0 && empty($state['chunk_size_locked'])) {
                $state['chunk_size'] = $this->normalize_batch_size($requested_batch, $product_id);
                $state['chunk_size_locked'] = true;
            }

            $chunk_size = isset($state['chunk_size'])
                ? (int) $state['chunk_size']
                : $this->normalize_batch_size(0, $product_id);

            if ($chunk_size < 1) {
                $chunk_size = $this->normalize_batch_size($chunk_size, $product_id);
                $state['chunk_size'] = $chunk_size;
            }

            $reservation = $this->claim_next_reservation($state, $chunk_size, $run_id);

            if (! $reservation) {
                $payload = $this->prepare_run_payload($state);
                $this->save_run_state($product_id, $state);
                $this->release_run_lock($product_id, $lock_token);

                wp_send_json_success([
                    'saved' => 0,
                    'skipped' => 0,
                    'offset' => $payload['next_offset'],
                    'total' => $payload['attempted_total'],
                    'is_complete' => !empty($payload['is_complete']),
                    'progress' => 0,
                    'total_generated' => $payload['attempted_total'],
                    'attempted_total' => $payload['attempted_total'],
                    'saved_total' => $payload['saved_total'],
                    'run' => $payload,
                    'message' => !empty($payload['is_complete'])
                        ? __('Generation complete.', 'mkl-pc-preset-generator')
                        : __('Waiting for available batches...', 'mkl-pc-preset-generator'),
                    'attempted_batch' => 0,
                    'saved_batch' => 0,
                    'valid_combinations' => [],
                ]);
            }

            $reservation_id = $reservation['id'];
            $assigned_offset = isset($reservation['offset']) ? (int) $reservation['offset'] : 0;
            $chunk_size = isset($reservation['limit']) ? (int) $reservation['limit'] : $chunk_size;

            $this->save_run_state($product_id, $state);
            $this->release_run_lock($product_id, $lock_token);
            $lock_token = null;

            $smart_generator = new MKL_PC_Smart_Combination_Generator($product_id);
            if (!empty($state['constraints']) && is_array($state['constraints'])) {
                $smart_generator->set_constraints($state['constraints']);
            }
            $saver = new MKL_PC_Preset_Saver($product_id);
            $config_builder = new MKL_PC_Configuration_Builder($product_id);

            $batch = $smart_generator->generate_batch($assigned_offset, $chunk_size);

            $saved = 0;
            $skipped = 0;
            $valid_combinations = [];
            $consumed = 0;

            $variation_settings = isset($state['variations']) && is_array($state['variations'])
                ? $state['variations']
                : [];
            $variation_axes = isset($variation_settings['axes'])
                ? array_values(array_unique(array_map('intval', (array) $variation_settings['axes'])))
                : [];
            $variation_axes = array_filter($variation_axes, function ($value) {
                return $value > 0;
            });
            $variation_include_base = isset($variation_settings['include_base'])
                ? (bool) $variation_settings['include_base']
                : true;
            $variation_limit = isset($variation_settings['limit'])
                ? max(0, (int) $variation_settings['limit'])
                : 0;
            $variation_axis_names = isset($variation_settings['axis_names']) && is_array($variation_settings['axis_names'])
                ? array_values($variation_settings['axis_names'])
                : [];
            $variations_enabled = ! empty($variation_axes);
            $variation_expander = new MKL_PC_Layer_Variation_Expander($product_id);
            $variation_added = 0;
            $variation_skipped_totals = [
                'base' => 0,
                'duplicate' => 0,
                'invalid' => 0,
            ];
            $variation_limit_reached = false;

            $core_layers_required = apply_filters('mkl_pc_preset_generator_core_layers', ['Size', 'Colour', 'Worktop'], $product_id);

            foreach ($batch as $combination) {
                $consumed++;

                $evaluation = $this->evaluate_core_layers($combination, $core_layers_required);
                if (! $evaluation['valid']) {
                    $skipped++;
                    if (!empty($evaluation['missing'])) {
                        error_log('Smart Generator: skipped combination missing core layers (' . implode(', ', $evaluation['missing']) . ')');
                    }
                    continue;
                }

                try {
                    $expanded_configuration = $config_builder->build_complete_configuration($combination);
                } catch (Exception $e) {
                    error_log('Bulk Generator: Failed to build expanded configuration - ' . $e->getMessage());
                    $expanded_configuration = [];
                }

                $entries_for_combination = [];

                $base_preset_name = $saver->generate_preset_name($combination, []);

                if (! $variations_enabled || $variation_include_base) {
                    $base_already_exists = $variation_expander->combination_is_already_saved($combination, $base_preset_name);

                    if ($base_already_exists) {
                        $variation_skipped_totals['duplicate']++;
                    } else {
                        $entries_for_combination[] = [
                            'base_combination' => $combination,
                            'preset_name' => $base_preset_name,
                            'expanded_configuration' => $expanded_configuration,
                            'is_variation' => false,
                        ];
                        $variation_expander->register_combination($combination, $base_preset_name);
                    }
                } else {
                    $variation_skipped_totals['base']++;
                }

                if ($variations_enabled) {

                    try {
                        $expansion_result = $variation_expander->expand_from_combination(
                            $combination,
                            $variation_axes,
                            [
                                'include_base' => false,
                                'limit' => $variation_limit,
                                'skip_existing' => true,
                            ]
                        );
                    } catch (Exception $e) {
                        error_log('Variation expansion failed: ' . $e->getMessage());
                        $expansion_result = [
                            'variations' => [],
                            'skipped' => [
                                'base' => 0,
                                'duplicate' => 0,
                                'invalid' => 0,
                            ],
                            'limit_reached' => false,
                        ];
                    }

                    if (isset($expansion_result['skipped']) && is_array($expansion_result['skipped'])) {
                        foreach ($variation_skipped_totals as $key => $value) {
                            if (isset($expansion_result['skipped'][$key])) {
                                $variation_skipped_totals[$key] += (int) $expansion_result['skipped'][$key];
                            }
                        }
                    }

                    if (! empty($expansion_result['limit_reached'])) {
                        $variation_limit_reached = true;
                    }

                    if (! empty($expansion_result['variations']) && is_array($expansion_result['variations'])) {
                        foreach ($expansion_result['variations'] as $variation_entry) {
                            $entries_for_combination[] = [
                                'base_combination' => isset($variation_entry['base_combination']) ? $variation_entry['base_combination'] : $combination,
                                'preset_name' => isset($variation_entry['preset_name']) ? $variation_entry['preset_name'] : $saver->generate_preset_name($combination, []),
                                'expanded_configuration' => isset($variation_entry['expanded_configuration']) ? $variation_entry['expanded_configuration'] : $expanded_configuration,
                                'is_variation' => true,
                            ];
                        }

                        $variation_added += count($expansion_result['variations']);
                    }
                }

                foreach ($entries_for_combination as $entry) {
                    $valid_combinations[] = $entry;
                }

                $saved += count($entries_for_combination);
            }

            $state_after = null;
            $lock_token = $this->acquire_run_lock($product_id);
            if ($lock_token) {
                $state_after = $this->get_run_state($product_id);
                if (is_array($state_after) && isset($state_after['run_id']) && $state_after['run_id'] === $run_id) {
                    $this->cleanup_expired_reservations($state_after);
                    $this->finalize_reservation(
                        $state_after,
                        $reservation_id,
                        $assigned_offset,
                        $consumed,
                        $chunk_size,
                        ['skipped' => $skipped]
                    );
                    $this->save_run_state($product_id, $state_after);
                }
                $this->release_run_lock($product_id, $lock_token);
                $lock_token = null;
            }

            $payload = $state_after
                ? $this->prepare_run_payload($state_after)
                : $this->prepare_run_payload($state);

            $response = [
                'saved' => 0,
                'skipped' => $skipped,
                'offset' => $payload['next_offset'],
                'claimed_offset' => $assigned_offset,
                'total' => $payload['attempted_total'],
                'is_complete' => !empty($payload['is_complete']),
                'progress' => 0,
                'total_generated' => $payload['attempted_total'],
                'attempted_total' => $payload['attempted_total'],
                'saved_total' => $payload['saved_total'],
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

            if ($variations_enabled || ! $variation_include_base) {
                $response['variation_summary'] = [
                    'added' => $variation_added,
                    'skipped' => $variation_skipped_totals,
                    'limit_reached' => $variation_limit_reached,
                    'axes' => $variation_axes,
                    'axis_names' => $variation_axis_names,
                    'include_base' => $variation_include_base,
                ];
            }

            wp_send_json_success($response);
        } catch (Exception $e) {
            if ($lock_token) {
                $this->release_run_lock($product_id, $lock_token);
            }

            if ($reservation_id && $product_id) {
                $lock_for_release = $this->acquire_run_lock($product_id);
                if ($lock_for_release) {
                    $state = $this->get_run_state($product_id);
                    if (is_array($state) && (!isset($state['run_id']) || empty($run_id) || $state['run_id'] === $run_id)) {
                        $this->release_reservation($state, $reservation_id);
                        $this->save_run_state($product_id, $state);
                    }
                    $this->release_run_lock($product_id, $lock_for_release);
                }
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

        $configuration = $this->normalize_configuration_layers($configuration, $product_id);
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
                // Title collision but configuration hash is different: auto-rename to a unique title
                $original_title = $normalized_title;
                $normalized_title = $this->make_unique_preset_name($product_id, $normalized_title);
                $preset_name = $normalized_title;
                error_log("Duplicate title detected (#$existing_by_title). Auto-renamed to '$normalized_title' from '$original_title'.");
            }
        }

        error_log("Saving expanded preset: $preset_name with " . count($configuration) . " layers");

        // Run diagnostics on the configuration
        $diagnostic_report = MKL_PC_Preset_Image_Diagnostics::analyze_configuration($configuration, 0);
        MKL_PC_Preset_Image_Diagnostics::log_report($diagnostic_report);

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
                $image_result = null;

                // Trigger image generation using our wrapper
                if (
                    !$skip_thumbnail &&
                    $save_image_async &&
                    is_array($save_image_async) &&
                    isset($save_image_async['should_save']) &&
                    $save_image_async['should_save'] &&
                    $preset_id
                ) {
                    error_log("Image generation requested for preset #$preset_id");

                    // Use our image generator wrapper for better error handling
                    // Pass null to let generator use stored content or decode as needed
                    $image_result = MKL_PC_Preset_Image_Generator::generate_image($preset_id, null);

                    if (is_wp_error($image_result)) {
                        error_log("Image generation failed: " . $image_result->get_error_message());
                        // Don't fail the preset save, just log the error
                    } else {
                        error_log("Image generation successful, attachment ID: $image_result");
                    }
                }

                error_log("Successfully saved expanded preset #$preset_id");
                $response = [
                    'preset_id' => $preset_id,
                    'message' => isset($saved['message']) ? $saved['message'] : '',
                    'title' => $preset_name,
                    'thumbnail' => [
                        'mode' => ($save_image_async && is_array($save_image_async) && !empty($save_image_async['should_save']))
                            ? 'async'
                            : 'sync',
                    ],
                ];

                if (!is_wp_error($image_result) && $image_result) {
                    // Extra safety: ensure featured image is set on the preset
                    $ok_thumb = set_post_thumbnail($preset_id, $image_result);
                    if (!$ok_thumb || (int) get_post_thumbnail_id($preset_id) !== (int) $image_result) {
                        update_post_meta($preset_id, '_thumbnail_id', (int) $image_result);
                    }
                    // We managed to generate synchronously; reflect that in response
                    $response['thumbnail']['mode'] = 'sync';
                    $response['thumbnail']['attachment_id'] = $image_result;
                    $thumb_url = wp_get_attachment_image_url($image_result, 'thumbnail');
                    if (!$thumb_url) {
                        $thumb_url = wp_get_attachment_image_url($image_result, 'full');
                    }
                    if (!$thumb_url) {
                        $thumb_url = wp_get_attachment_url($image_result);
                    }
                    $response['thumbnail']['url'] = $thumb_url;
                } elseif ($save_image_async && is_array($save_image_async)) {
                    // Fall back to async contract
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

        $this->clear_run_state($product_id);

        wp_send_json_success([
            'deleted' => $deleted,
        ]);
    }

    /**
     * AJAX: Get list of orphaned presets (presets without images) for preview
     */
    public function ajax_preview_orphaned_presets()
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
        $orphaned = $saver->get_presets_without_images();

        wp_send_json_success([
            'total' => count($orphaned),
            'presets' => $orphaned,
        ]);
    }

    /**
     * AJAX: Clean up orphaned presets (presets without images)
     */
    public function ajax_cleanup_orphaned_presets()
    {
        check_ajax_referer('mkl_pc_bulk_generator', 'nonce');

        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'mkl-pc-preset-generator')]);
        }

        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;

        if (! $product_id) {
            wp_send_json_error(['message' => __('Invalid product ID', 'mkl-pc-preset-generator')]);
        }

        $confirm = isset($_POST['confirm']) ? filter_var($_POST['confirm'], FILTER_VALIDATE_BOOLEAN) : false;

        if (! $confirm) {
            wp_send_json_error(['message' => __('Confirmation required', 'mkl-pc-preset-generator')]);
        }

        $saver = new MKL_PC_Preset_Saver($product_id);
        $stats = $saver->cleanup_presets_without_images();

        wp_send_json_success([
            'checked' => $stats['checked'],
            'deleted' => $stats['deleted'],
            'errors' => $stats['errors'],
            'message' => sprintf(
                __('Checked %d presets, deleted %d without images', 'mkl-pc-preset-generator'),
                $stats['checked'],
                $stats['deleted']
            ),
        ]);
    }

    /**
     * AJAX: Generate (or ensure) a thumbnail for an existing preset.
     */
    public function ajax_generate_preset_thumbnail()
    {
        check_ajax_referer('mkl_pc_bulk_generator', 'nonce');

        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'mkl-pc-preset-generator')]);
        }

        $preset_id = isset($_POST['preset_id']) ? intval($_POST['preset_id']) : 0;
        if (!$preset_id) {
            wp_send_json_error(['message' => __('Invalid preset ID', 'mkl-pc-preset-generator')]);
        }

        $post = get_post($preset_id);
        if (!$post || $post->post_type !== 'mkl_pc_configuration') {
            wp_send_json_error(['message' => __('Not a valid preset', 'mkl-pc-preset-generator')]);
        }

        // If a thumbnail already exists, return it
        $thumb_id = get_post_thumbnail_id($preset_id);
        if ($thumb_id) {
            $url = wp_get_attachment_image_url($thumb_id, 'thumbnail');
            if (!$url) {
                $url = wp_get_attachment_image_url($thumb_id, 'full');
            }
            if (!$url) {
                $url = wp_get_attachment_url($thumb_id);
            }
            wp_send_json_success([
                'attachment_id' => $thumb_id,
                'url' => $url,
                'already_exists' => true,
            ]);
        }

        // Generate using the shared image generator wrapper
        $result = MKL_PC_Preset_Image_Generator::generate_image($preset_id, null);
        if (is_wp_error($result) || !$result) {
            $msg = is_wp_error($result) ? $result->get_error_message() : __('Image generation failed', 'mkl-pc-preset-generator');
            wp_send_json_error(['message' => $msg]);
        }

        // Ensure thumbnail is set
        if ($preset_id) {
            $ok = set_post_thumbnail($preset_id, $result);
            if (!$ok || (int) get_post_thumbnail_id($preset_id) !== (int) $result) {
                update_post_meta($preset_id, '_thumbnail_id', (int) $result);
            }
        }
        // Ensure sizes metadata exists; regenerate if missing
        $path = get_attached_file($result);
        $relative = get_post_meta($result, '_wp_attached_file', true);
        $uploads = wp_upload_dir();
        $expected_url = $relative ? trailingslashit($uploads['baseurl']) . ltrim($relative, '/') : '';
        $meta = wp_get_attachment_metadata($result);
        if ((!is_array($meta) || empty($meta['sizes'])) && $path && file_exists($path)) {
            if (!function_exists('wp_generate_attachment_metadata') && file_exists(ABSPATH . 'wp-admin/includes/image.php')) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
            }
            if (function_exists('wp_generate_attachment_metadata')) {
                $new_meta = wp_generate_attachment_metadata($result, $path);
                if ($new_meta) {
                    wp_update_attachment_metadata($result, $new_meta);
                }
            }
        }
        // Ensure mime type and GUID are correct
        $attach = get_post($result);
        if ($attach && empty($attach->post_mime_type) && $path) {
            $ft = wp_check_filetype($path);
            if (!empty($ft['type'])) {
                wp_update_post([
                    'ID' => $result,
                    'post_mime_type' => $ft['type'],
                ]);
            }
        }
        if ($attach && $expected_url) {
            $needs_guid_fix = stripos((string)$attach->guid, 'file%20already%20exists') !== false || stripos((string)$attach->guid, 'file already exists') !== false;
            if ($needs_guid_fix) {
                wp_update_post([
                    'ID' => $result,
                    'guid' => $expected_url,
                ]);
            }
        }

        $url = wp_get_attachment_image_url($result, 'thumbnail');
        if (!$url) {
            $url = wp_get_attachment_image_url($result, 'full');
        }
        if (!$url) {
            $url = wp_get_attachment_url($result);
        }
        wp_send_json_success([
            'attachment_id' => $result,
            'url' => $url,
            'already_exists' => false,
        ]);
    }

    /**
     * Compatibility: intercept core image generation endpoint to ensure a URL is returned
     * even when the file already exists, without editing upstream plugin code.
     * If we successfully create or resolve an attachment, we return and stop further handlers.
     * Otherwise, we let other handlers (upstream) continue by returning early.
     */
    public function ajax_generate_configuration_image_compat()
    {
        if (!is_user_logged_in()) {
            return; // let upstream handle
        }

        // Mirror upstream nonce name to stay compatible with UI calls
        $security = isset($_POST['security']) ? $_POST['security'] : '';
        if (!$security) {
            return;
        }
        try {
            check_ajax_referer('save-config-image', 'security');
        } catch (\Throwable $e) {
            return; // invalid or different nonce, let upstream handle
        }

        $config_id = isset($_POST['config_id']) ? intval($_POST['config_id']) : 0;
        if (!$config_id) {
            return;
        }

        // If a thumbnail already exists, return it immediately
        $thumb_id = get_post_thumbnail_id($config_id);
        if ($thumb_id) {
            $thumb_url = wp_get_attachment_thumb_url($thumb_id);
            if ($thumb_url) {
                error_log("Compat: existing thumbnail found for #$config_id -> $thumb_url");
                wp_send_json_success(['thumbnail' => $thumb_url]);
            }
        }

        // Use our robust wrapper to generate or resolve existing file/attachment
        error_log("Compat: attempting image generation for preset #$config_id");
        $result = MKL_PC_Preset_Image_Generator::generate_image($config_id, null);
        if (!is_wp_error($result) && $result) {
            // Ensure the preset carries the featured image for UI
            $ok_thumb = set_post_thumbnail($config_id, $result);
            if (!$ok_thumb || (int) get_post_thumbnail_id($config_id) !== (int) $result) {
                update_post_meta($config_id, '_thumbnail_id', (int) $result);
            }
            // Normalize metadata, mime type, and GUID
            $path = get_attached_file($result);
            $relative = get_post_meta($result, '_wp_attached_file', true);
            $uploads = wp_upload_dir();
            $expected_url = $relative ? trailingslashit($uploads['baseurl']) . ltrim($relative, '/') : '';
            $attach = get_post($result);
            if ($attach && empty($attach->post_mime_type) && $path) {
                $ft = wp_check_filetype($path);
                if (!empty($ft['type'])) {
                    wp_update_post([
                        'ID' => $result,
                        'post_mime_type' => $ft['type'],
                    ]);
                }
            }
            if ($attach && $expected_url) {
                $needs_guid_fix = stripos((string)$attach->guid, 'file%20already%20exists') !== false || stripos((string)$attach->guid, 'file already exists') !== false;
                if ($needs_guid_fix) {
                    wp_update_post([
                        'ID' => $result,
                        'guid' => $expected_url,
                    ]);
                }
            }
            // Regenerate sizes metadata if missing
            $meta = wp_get_attachment_metadata($result);
            if ((!is_array($meta) || empty($meta['sizes'])) && $path && file_exists($path)) {
                if (!function_exists('wp_generate_attachment_metadata') && file_exists(ABSPATH . 'wp-admin/includes/image.php')) {
                    require_once ABSPATH . 'wp-admin/includes/image.php';
                }
                if (function_exists('wp_generate_attachment_metadata')) {
                    $new_meta = wp_generate_attachment_metadata($result, $path);
                    if ($new_meta) {
                        wp_update_attachment_metadata($result, $new_meta);
                    }
                }
            }
            $url = wp_get_attachment_image_url($result, 'thumbnail');
            if (!$url) {
                $url = wp_get_attachment_image_url($result, 'full');
            }
            if (!$url) {
                $url = wp_get_attachment_url($result);
            }
            if ($url) {
                error_log("Compat: generated/resolved attachment #$result -> $url");
                wp_send_json_success(['thumbnail' => $url]);
            }
        }

        // Fall through to upstream handler if we couldn't resolve
        if (is_wp_error($result)) {
            error_log("Compat: generation failed for #$config_id -> " . $result->get_error_message());
        } else {
            error_log("Compat: generation returned empty for #$config_id");
        }
        return;
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
        // Apply constraints from POST if provided
        $constraints = [];
        if (!empty($_POST['constraints'])) {
            $raw = $_POST['constraints'];
            if (is_string($raw)) {
                $decoded = json_decode(stripslashes($raw), true);
                if (is_array($decoded)) $constraints = $decoded;
            } elseif (is_array($raw)) {
                $constraints = $raw;
            }
            $clean = [];
            foreach ($constraints as $lid => $vals) {
                $lid = intval($lid);
                if ($lid <= 0) continue;
                if (is_array($vals)) {
                    $arr = array_values(array_unique(array_map('intval', $vals)));
                } else {
                    $arr = [intval($vals)];
                }
                $arr = array_filter($arr, function ($v) {
                    return $v >= 0;
                });
                if (!empty($arr)) $clean[$lid] = $arr;
            }
            if (!empty($clean)) {
                $smart_generator->set_constraints($clean);
            }
        }

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

    /**
     * Generate a unique preset name by appending an incrementing suffix when needed.
     *
     * @param int $product_id
     * @param string $base_title
     * @return string
     */
    private function make_unique_preset_name($product_id, $base_title)
    {
        $base_title = trim($base_title);
        if ($base_title === '') {
            return $base_title;
        }

        $candidate = $base_title;
        $suffix = 2;
        while ($this->find_existing_preset_by_title($product_id, $candidate)) {
            $candidate = sprintf('%s (%d)', $base_title, $suffix);
            $suffix++;
            if ($suffix > 5000) {
                break; // bailout guard
            }
        }
        return $candidate;
    }

    private function evaluate_core_layers(array $combination, array $core_layers_required)
    {
        $core_selections = array_fill_keys($core_layers_required, false);
        $seen_layers = [];

        foreach ($combination as $choice) {
            if (isset($core_selections[$choice['layer_name']])) {
                if ($choice['choice_id'] !== null && $choice['choice_name'] !== 'None') {
                    $core_selections[$choice['layer_name']] = true;
                }
            }
            if (isset($choice['layer_name'])) {
                $seen_layers[$choice['layer_name']] = true;
            }
        }

        foreach ($core_selections as $layer_name => $is_selected) {
            if (!isset($seen_layers[$layer_name])) {
                unset($core_selections[$layer_name]);
            }
        }

        $missing = array_keys(array_filter($core_selections, function ($selected) {
            return ! $selected;
        }));

        return [
            'valid' => empty($missing),
            'missing' => $missing,
        ];
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
     * Retrieve a normalised batch size based on project filters.
     *
     * @param int $requested
     * @param int $product_id
     * @return int
     */
    private function normalize_batch_size($requested, $product_id)
    {
        $default = (int) apply_filters('mkl_pc_preset_generator_batch_size', 50, $product_id);
        $max = (int) apply_filters('mkl_pc_preset_generator_max_batch_size', 250, $product_id);

        $batch_size = (int) $requested;
        if ($batch_size < 1) {
            $batch_size = $default;
        }

        if ($max > 0) {
            $batch_size = min($batch_size, $max);
        }

        return max(1, $batch_size);
    }

    /**
     * Option key helper for run state storage.
     */
    private function get_run_state_option_key($product_id)
    {
        return 'mkl_pc_bulk_state_' . $product_id;
    }

    /**
     * Option key helper for run locking.
     */
    private function get_run_lock_option_key($product_id)
    {
        return 'mkl_pc_bulk_lock_' . $product_id;
    }

    /**
     * Acquire an advisory lock for a product run.
     *
     * @param int $product_id
     * @param int $timeout Seconds to wait before giving up.
     * @return string|false Lock token on success, false when busy.
     */
    private function acquire_run_lock($product_id, $timeout = 5)
    {
        $lock_key = $this->get_run_lock_option_key($product_id);
        $token = uniqid('lock_', true);
        $attempt_until = time() + max(1, (int) $timeout);
        $lock_ttl = 10; // seconds

        do {
            $existing = get_option($lock_key);
            if (is_array($existing) && isset($existing['token'], $existing['expires'])) {
                if ((int) $existing['expires'] < time()) {
                    delete_option($lock_key);
                    $existing = false;
                }
            } elseif (!empty($existing)) {
                // Legacy value, remove it.
                delete_option($lock_key);
                $existing = false;
            }

            if (false === $existing) {
                $stored = [
                    'token' => $token,
                    'expires' => time() + $lock_ttl,
                ];
                if (add_option($lock_key, $stored, '', 'no')) {
                    return $token;
                }
            }

            usleep(150000); // 150ms backoff
        } while (time() < $attempt_until);

        return false;
    }

    /**
     * Release a previously acquired lock.
     *
     * @param int    $product_id
     * @param string $token
     */
    private function release_run_lock($product_id, $token)
    {
        if (!$token) {
            return;
        }

        $lock_key = $this->get_run_lock_option_key($product_id);
        $existing = get_option($lock_key);
        if (is_array($existing) && isset($existing['token']) && $existing['token'] === $token) {
            delete_option($lock_key);
        }
    }

    /**
     * Load run state for a product.
     *
     * @param int $product_id
     * @return array|null
     */
    private function get_run_state($product_id)
    {
        $state = get_option($this->get_run_state_option_key($product_id), null);
        return is_array($state) ? $state : null;
    }

    /**
     * Persist run state.
     *
     * @param int   $product_id
     * @param array $state
     * @return void
     */
    private function save_run_state($product_id, array $state)
    {
        update_option($this->get_run_state_option_key($product_id), $state, false);
    }

    /**
     * Remove stored run state and associated lock (if any).
     *
     * @param int $product_id
     * @return void
     */
    private function clear_run_state($product_id)
    {
        delete_option($this->get_run_state_option_key($product_id));
        delete_option($this->get_run_lock_option_key($product_id));
        delete_option('mkl_pc_bulk_offset_' . $product_id);
    }

    /**
     * Determine whether the stored state should be reset.
     *
     * @param array|null $state
     * @return bool
     */
    private function should_reset_run_state($state)
    {
        if (!is_array($state) || empty($state['run_id'])) {
            return true;
        }

        if (!empty($state['is_complete']) || !empty($state['cancelled'])) {
            return true;
        }

        $updated_at = isset($state['updated_at']) ? (int) $state['updated_at'] : 0;
        $ttl = isset($state['reservation_ttl']) ? (int) $state['reservation_ttl'] : 120;
        $idle_limit = max($ttl * 4, 600);

        if ($updated_at > 0 && (time() - $updated_at) > $idle_limit) {
            return true;
        }

        return false;
    }

    /**
     * Initialise a fresh run state.
     *
     * @param int      $product_id
     * @param int|null $requested_chunk_size
     * @return array
     */
    private function create_run_state($product_id, $requested_chunk_size = null)
    {
        $chunk_size = $this->normalize_batch_size(
            $requested_chunk_size !== null ? (int) $requested_chunk_size : 0,
            $product_id
        );

        $reservation_ttl = (int) apply_filters(
            'mkl_pc_preset_generator_reservation_ttl',
            120,
            $product_id
        );

        $state = [
            'version' => 1,
            'run_id' => wp_generate_uuid4(),
            'product_id' => $product_id,
            'chunk_size' => $chunk_size,
            'next_offset' => 0,
            'attempted_total' => 0,
            'saved_total' => 0,
            'started_at' => time(),
            'updated_at' => time(),
            'reservations' => [],
            'pending_offsets' => [],
            'reservation_ttl' => max(30, $reservation_ttl),
            'total_batches' => 0,
            'completed_chunks' => 0,
            'skipped_total' => 0,
            'is_complete' => false,
            'is_exhausted' => false,
            'variations' => [
                'axes' => [],
                'include_base' => true,
                'limit' => 0,
                'axis_names' => [],
            ],
        ];

        return $state;
    }

    /**
     * Prepare run payload for frontend consumption.
     *
     * @param array $state
     * @return array
     */
    private function prepare_run_payload(array $state)
    {
        return [
            'run_id' => isset($state['run_id']) ? $state['run_id'] : '',
            'chunk_size' => isset($state['chunk_size']) ? (int) $state['chunk_size'] : 0,
            'next_offset' => isset($state['next_offset']) ? (int) $state['next_offset'] : 0,
            'attempted_total' => isset($state['attempted_total']) ? (int) $state['attempted_total'] : 0,
            'saved_total' => isset($state['saved_total']) ? (int) $state['saved_total'] : 0,
            'pending' => isset($state['pending_offsets']) ? count((array) $state['pending_offsets']) : 0,
            'reservations' => isset($state['reservations']) ? count((array) $state['reservations']) : 0,
            'started_at' => isset($state['started_at']) ? (int) $state['started_at'] : 0,
            'updated_at' => isset($state['updated_at']) ? (int) $state['updated_at'] : 0,
            'is_complete' => !empty($state['is_complete']),
            'is_exhausted' => !empty($state['is_exhausted']),
            'reservation_ttl' => isset($state['reservation_ttl']) ? (int) $state['reservation_ttl'] : 0,
            'skipped_total' => isset($state['skipped_total']) ? (int) $state['skipped_total'] : 0,
            'variations' => isset($state['variations']) ? $state['variations'] : [
                'axes' => [],
                'include_base' => true,
                'limit' => 0,
                'axis_names' => [],
            ],
        ];
    }

    /**
     * Cleanup expired reservations and recycle their offsets.
     *
     * @param array $state
     * @return void
     */
    private function cleanup_expired_reservations(array &$state)
    {
        if (empty($state['reservations']) || !is_array($state['reservations'])) {
            $state['reservations'] = [];
            return;
        }

        $ttl = isset($state['reservation_ttl']) ? (int) $state['reservation_ttl'] : 120;
        $now = time();
        $pending = isset($state['pending_offsets']) && is_array($state['pending_offsets'])
            ? $state['pending_offsets']
            : [];

        foreach ($state['reservations'] as $id => $reservation) {
            $started_at = isset($reservation['started_at']) ? (int) $reservation['started_at'] : 0;
            if ($started_at > 0 && ($now - $started_at) > $ttl) {
                $offset = isset($reservation['offset']) ? (int) $reservation['offset'] : 0;
                $limit = isset($reservation['limit']) ? (int) $reservation['limit'] : 0;

                if ($offset >= 0) {
                    $pending[] = $offset;
                }

                unset($state['reservations'][$id]);

                error_log(sprintf(
                    'Bulk Generator: released expired reservation %s (offset %d, limit %d)',
                    $id,
                    $offset,
                    $limit
                ));
            }
        }

        if (!empty($pending)) {
            $pending = array_values(array_unique(array_map('intval', $pending)));
            sort($pending, SORT_NUMERIC);
            $state['pending_offsets'] = $pending;
        } else {
            $state['pending_offsets'] = [];
        }
    }

    /**
     * Reserve the next offset segment for processing.
     *
     * @param array  $state
     * @param int    $chunk_size
     * @param string $run_id
     * @return array|null
     */
    private function claim_next_reservation(array &$state, $chunk_size, $run_id)
    {
        $chunk_size = max(1, (int) $chunk_size);
        $reservation_id = uniqid('res_', true);

        if (!isset($state['pending_offsets']) || !is_array($state['pending_offsets'])) {
            $state['pending_offsets'] = [];
        }

        $offset = null;
        if (!empty($state['pending_offsets'])) {
            sort($state['pending_offsets'], SORT_NUMERIC);
            $offset = array_shift($state['pending_offsets']);
        }

        if ($offset === null) {
            $offset = isset($state['next_offset']) ? (int) $state['next_offset'] : 0;
            $state['next_offset'] = $offset + $chunk_size;
        }

        if (!isset($state['reservations']) || !is_array($state['reservations'])) {
            $state['reservations'] = [];
        }

        $state['reservations'][$reservation_id] = [
            'offset' => $offset,
            'limit' => $chunk_size,
            'run_id' => $run_id,
            'started_at' => time(),
        ];

        return [
            'id' => $reservation_id,
            'offset' => $offset,
            'limit' => $chunk_size,
        ];
    }

    /**
     * Finalise a reservation after successful processing.
     *
     * @param array  $state
     * @param string $reservation_id
     * @param int    $offset
     * @param int    $produced
     * @param int    $limit
     * @param array  $meta
     * @return void
     */
    private function finalize_reservation(array &$state, $reservation_id, $offset, $produced, $limit, array $meta = [])
    {
        if (isset($state['reservations'][$reservation_id])) {
            unset($state['reservations'][$reservation_id]);
        }

        $state['attempted_total'] = isset($state['attempted_total'])
            ? (int) $state['attempted_total'] + max(0, (int) $produced)
            : max(0, (int) $produced);

        $state['total_batches'] = isset($state['total_batches'])
            ? (int) $state['total_batches'] + 1
            : 1;

        $state['completed_chunks'] = isset($state['completed_chunks'])
            ? (int) $state['completed_chunks'] + 1
            : 1;

        if (isset($meta['skipped'])) {
            $state['skipped_total'] = isset($state['skipped_total'])
                ? (int) $state['skipped_total'] + max(0, (int) $meta['skipped'])
                : max(0, (int) $meta['skipped']);
        }

        if (isset($meta['saved'])) {
            $state['saved_total'] = isset($state['saved_total'])
                ? (int) $state['saved_total'] + max(0, (int) $meta['saved'])
                : max(0, (int) $meta['saved']);
        }

        $state['updated_at'] = time();

        if ((int) $produced < (int) $limit) {
            $state['is_exhausted'] = true;
        }

        if (
            empty($state['reservations']) &&
            empty($state['pending_offsets']) &&
            (!empty($state['is_exhausted']) || (int) $produced === 0)
        ) {
            $state['is_complete'] = true;
        }
    }

    /**
     * Release a reservation and recycle the offset.
     *
     * @param array  $state
     * @param string $reservation_id
     * @return void
     */
    private function release_reservation(array &$state, $reservation_id)
    {
        if (!isset($state['reservations'][$reservation_id])) {
            return;
        }

        $reservation = $state['reservations'][$reservation_id];
        unset($state['reservations'][$reservation_id]);

        if (!isset($state['pending_offsets']) || !is_array($state['pending_offsets'])) {
            $state['pending_offsets'] = [];
        }

        $offset = isset($reservation['offset']) ? (int) $reservation['offset'] : null;
        if ($offset !== null) {
            $state['pending_offsets'][] = $offset;
            $state['pending_offsets'] = array_values(array_unique(array_map('intval', $state['pending_offsets'])));
            sort($state['pending_offsets'], SORT_NUMERIC);
        }

        $state['updated_at'] = time();
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
    private function normalize_configuration_layers($configuration, $product_id = 0)
    {
        if (!is_array($configuration) || empty($configuration)) {
            return $configuration;
        }

        // Build a map of layer_id => image_order from core data to mirror UI ordering
        $layer_image_orders = [];
        if ($product_id) {
            try {
                $db = \MKL\PC\Plugin::instance()->db;
                $layers = $db->get('layers', $product_id);
                if (is_array($layers)) {
                    foreach ($layers as $layer) {
                        if (isset($layer['_id'])) {
                            $lid = (int) $layer['_id'];
                            $layer_image_orders[$lid] = isset($layer['image_order'])
                                ? (int) $layer['image_order']
                                : (isset($layer['order']) ? (int) $layer['order'] : 0);
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Non-fatal; fall back to incoming image_order values
            }
        }

        $indexed = [];
        foreach ($configuration as $index => $layer) {
            // If we have the authoritative image_order from the layer, overwrite
            $layer_id = $this->get_layer_property($layer, 'layer_id');
            if ($layer_id !== null && isset($layer_image_orders[(int)$layer_id])) {
                if (is_array($layer)) {
                    $layer['image_order'] = $layer_image_orders[(int)$layer_id];
                } elseif (is_object($layer)) {
                    $layer->image_order = $layer_image_orders[(int)$layer_id];
                }
            }

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
