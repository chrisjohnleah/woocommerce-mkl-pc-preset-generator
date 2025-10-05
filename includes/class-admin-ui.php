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
        add_action('wp_ajax_mkl_pc_generate_presets_batch', [$this, 'ajax_generate_batch']);
        add_action('wp_ajax_mkl_pc_delete_all_presets', [$this, 'ajax_delete_all']);
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
                margin-top: 15px;
                padding: 10px;
                background: white;
                border-radius: 4px;
                font-size: 13px;
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
        </style>

        <script type="text/html" id="tmpl-mkl-pc-bulk-generator-ui">
            <div class="mkl-pc-bulk-generator">
                <h3><?php esc_html_e('Bulk Preset Generator', 'mkl-pc-preset-generator'); ?></h3>
                <p><?php esc_html_e('Automatically generate all valid configuration combinations based on conditional logic rules.', 'mkl-pc-preset-generator'); ?></p>

                <div class="mkl-pc-bulk-generator-actions">
                    <button type="button" class="mkl-pc-estimate-btn primary" data-product-id="<?php echo esc_attr($product_id); ?>">
                        <?php esc_html_e('Estimate Combinations', 'mkl-pc-preset-generator'); ?>
                    </button>
                    <button type="button" class="mkl-pc-generate-btn primary" data-product-id="<?php echo esc_attr($product_id); ?>" disabled>
                        <?php esc_html_e('Generate All Presets', 'mkl-pc-preset-generator'); ?>
                    </button>
                    <button type="button" class="mkl-pc-delete-all-btn danger" data-product-id="<?php echo esc_attr($product_id); ?>">
                        <?php esc_html_e('Delete All Presets', 'mkl-pc-preset-generator'); ?>
                    </button>
                </div>

                <div class="mkl-pc-bulk-generator-info">
                    <div class="mkl-pc-bulk-stats">
                        <div class="mkl-pc-bulk-stat">
                            <div class="mkl-pc-bulk-stat-value" data-stat="estimated">-</div>
                            <div class="mkl-pc-bulk-stat-label"><?php esc_html_e('Estimated Total', 'mkl-pc-preset-generator'); ?></div>
                        </div>
                        <div class="mkl-pc-bulk-stat">
                            <div class="mkl-pc-bulk-stat-value" data-stat="existing">-</div>
                            <div class="mkl-pc-bulk-stat-label"><?php esc_html_e('Existing Presets', 'mkl-pc-preset-generator'); ?></div>
                        </div>
                        <div class="mkl-pc-bulk-stat">
                            <div class="mkl-pc-bulk-stat-value" data-stat="generated">0</div>
                            <div class="mkl-pc-bulk-stat-label"><?php esc_html_e('Generated', 'mkl-pc-preset-generator'); ?></div>
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

        wp_enqueue_script(
            'mkl-pc-bulk-generator',
            MKL_PC_PRESET_GENERATOR_URL . 'assets/js/bulk-generator.js',
            ['jquery', 'backbone', 'wp-hooks'],
            MKL_PC_PRESET_GENERATOR_VERSION,
            true
        );

        error_log('MKL PC Bulk Generator: Script URL: ' . MKL_PC_PRESET_GENERATOR_URL . 'assets/js/bulk-generator.js');

        wp_localize_script('mkl-pc-bulk-generator', 'MKL_PC_BulkGenerator', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mkl_pc_bulk_generator'),
            'strings' => [
                'estimating' => __('Estimating combinations...', 'mkl-pc-preset-generator'),
                'generating' => __('Generating presets...', 'mkl-pc-preset-generator'),
                'complete' => __('Generation complete!', 'mkl-pc-preset-generator'),
                'error' => __('An error occurred', 'mkl-pc-preset-generator'),
                'confirm_delete' => __('Are you sure you want to delete all presets for this product? This cannot be undone.', 'mkl-pc-preset-generator'),
            ],
        ]);

        error_log('MKL PC Bulk Generator: Scripts enqueued and localized');
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

            // Use SAMPLING: Check first 1M combinations for accurate estimate
            $validator = new MKL_PC_Preset_Conditional_Validator($product_id);
            
            $sample_size = 1000000;
            $valid_count = 0;
            $checked = 0;
            $batch_size = 100;
            $offset = 0;

            $start_time = microtime(true);

            while ($checked < $sample_size) {
                $combinations = $generator->generate_combinations_batch($offset, $batch_size);

                if (empty($combinations)) {
                    break; // No more combinations
                }

                foreach ($combinations as $combination) {
                    $checked++;
                    if ($validator->validate_combination($combination)) {
                        $valid_count++;
                    }

                    if ($checked >= $sample_size) {
                        break;
                    }
                }

                $offset += $batch_size;
            }

            $elapsed = round(microtime(true) - $start_time, 2);
            $pass_rate = $checked > 0 ? round(($valid_count / $checked) * 100, 2) : 0;

            error_log("Sampling: $valid_count valid out of $checked checked ({$pass_rate}% pass rate) in {$elapsed}s");

            $existing = $saver->get_preset_count();

            $message = "Sample: $valid_count valid in " . number_format($checked) . " checked ({$pass_rate}% pass rate). Estimated total: " . number_format($valid_count * 10) . "+";

            wp_send_json_success([
                'valid_count' => $valid_count,
                'total_checked' => $valid_count, // Smart gen only produces valid ones
                'existing' => $existing,
                'layers' => $layer_info,
                'total_layers' => count($layers),
                'message' => $message,
            ]);
        } catch (Exception $e) {
            error_log('MKL PC Bulk Generator Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Generate batch of presets
     */
    public function ajax_generate_batch()
    {
        try {
            check_ajax_referer('mkl_pc_bulk_generator', 'nonce');

            if (! current_user_can('manage_woocommerce')) {
                wp_send_json_error(['message' => __('Permission denied', 'mkl-pc-preset-generator')]);
            }

            $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
            $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
            $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 50;
            $total_generated = isset($_POST['total_generated']) ? intval($_POST['total_generated']) : 0;

            if (! $product_id) {
                wp_send_json_error(['message' => __('Invalid product ID', 'mkl-pc-preset-generator')]);
            }

            // SAFETY LIMIT: Stop after 500 valid presets for testing
            // TODO: Remove this limit once code is proven to work correctly
            $SAFETY_LIMIT = 500;

            if ($total_generated >= $SAFETY_LIMIT) {
                error_log("SAFETY LIMIT REACHED: $total_generated presets generated. Stopping.");
                wp_send_json_success([
                    'saved' => 0,
                    'skipped' => 0,
                    'offset' => $offset,
                    'total' => $offset,
                    'is_complete' => true,
                    'progress' => 100,
                    'total_generated' => $total_generated,
                    'safety_limit' => $SAFETY_LIMIT,
                    'message' => "Safety limit reached: $total_generated valid presets created. Remove the limit in code to generate more.",
                ]);
                return;  // IMPORTANT: Exit immediately!
            }

            // Increase time limit for this request
            set_time_limit(300);

            $generator = new MKL_PC_Preset_Combination_Generator($product_id);
            $validator = new MKL_PC_Preset_Conditional_Validator($product_id);
            $saver = new MKL_PC_Preset_Saver($product_id);

            // Generate ONLY this batch of combinations (not all 1.9 trillion!)
            error_log("Generating batch starting at offset: $offset");
            $batch = $generator->generate_combinations_batch($offset, $batch_size);
            error_log("Generated " . count($batch) . " combinations for this batch");

            $saved = 0;
            $skipped = 0;
            $last_valid_combination = null;

            $combination_index = 0;
            foreach ($batch as $combination) {
                $combination_index++;

                // Require at least the CORE layers to have selections
                // These are essential for any valid product configuration
                $core_layers_required = ['Size', 'Colour', 'Worktop'];
                $core_selections = [];

                foreach ($combination as $choice) {
                    if (in_array($choice['layer_name'], $core_layers_required)) {
                        if ($choice['choice_id'] !== null && $choice['choice_name'] !== 'None') {
                            $core_selections[] = $choice['layer_name'];
                        }
                    }
                }

                // Skip if any core layer is missing or set to None
                if (count($core_selections) < count($core_layers_required)) {
                    $skipped++;
                    if ($combination_index <= 3) {
                        $missing = array_diff($core_layers_required, $core_selections);
                        error_log("Combination #$combination_index: SKIPPED - Missing core layers: " . implode(', ', $missing));
                    }
                    continue;
                }

                // Validate against conditional logic
                $is_valid = $validator->validate_combination($combination);

                if ($combination_index <= 5 || $is_valid) {
                    // Log first 5 combinations AND any valid ones for debugging
                    $combo_str = implode(' + ', array_map(function ($c) {
                        return $c['layer_name'] . ':' . $c['choice_name'];
                    }, $combination));

                    if ($is_valid) {
                        error_log("✓ VALID Combination #$combination_index: $combo_str");
                    } elseif ($combination_index <= 5) {
                        error_log("✗ Combination #$combination_index: INVALID");
                    }
                }

                if (! $is_valid) {
                    $skipped++;
                    continue;
                }

                // Save preset
                $result = $saver->save_preset($combination, [
                    'skip_duplicates' => true,
                ]);

                if (! is_wp_error($result)) {
                    $saved++;
                    $last_valid_combination = $combination;
                    if ($saved <= 3) {
                        error_log("Successfully saved preset #$saved with ID: $result");
                    }
                } else {
                    error_log("Failed to save preset: " . $result->get_error_message());
                    $skipped++;
                }
            }

            $new_offset = $offset + $batch_size;

            // Since we can't know the total upfront, we check if we got any results
            $is_complete = count($batch) < $batch_size;

            // Check if we've hit the safety limit
            $new_total_generated = $total_generated + $saved;
            if ($new_total_generated >= $SAFETY_LIMIT) {
                $is_complete = true;
                error_log("SAFETY LIMIT HIT: $new_total_generated valid presets. Stopping generation.");
            }

            error_log("Batch complete: Saved=$saved, Skipped=$skipped, NewOffset=$new_offset, TotalGenerated=$new_total_generated, IsComplete=" . ($is_complete ? 'YES' : 'NO'));

            wp_send_json_success([
                'saved' => $saved,
                'skipped' => $skipped,
                'offset' => $new_offset,
                'total' => $new_offset,
                'is_complete' => $is_complete,
                'progress' => min(100, round(($new_total_generated / $SAFETY_LIMIT) * 100)),
                'total_generated' => $new_total_generated,
                'safety_limit' => $SAFETY_LIMIT,
            ]);
        } catch (Exception $e) {
            error_log('MKL PC Bulk Generator Batch Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
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

        wp_send_json_success([
            'deleted' => $deleted,
        ]);
    }
}
