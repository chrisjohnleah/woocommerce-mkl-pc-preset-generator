<?php

/**
 * Bulk worker
 *
 * Handles reservation processing for both UI-driven and CLI-driven runs.
 */

if (! defined('ABSPATH')) {
    exit;
}

class MKL_PC_Preset_Bulk_Worker
{
    private $product_id;
    private $options = [];
    private $smart_generator;
    private $saver;
    private $config_builder;
    private $core_layers_required = [];

    /**
     * @param int   $product_id
     * @param array $options
     */
    public function __construct($product_id, array $options = [])
    {
        $this->product_id = (int) $product_id;
        $this->options = wp_parse_args(
            $options,
            [
                'save_presets' => false,
                'expand_for_ui' => true,
                'skip_thumbnail' => false,
            ]
        );

        $this->smart_generator = new MKL_PC_Smart_Combination_Generator($this->product_id);
        $this->saver = new MKL_PC_Preset_Saver($this->product_id);
        $this->config_builder = new MKL_PC_Configuration_Builder($this->product_id);
        $this->core_layers_required = apply_filters(
            'mkl_pc_preset_generator_core_layers',
            ['Size', 'Colour', 'Worktop'],
            $this->product_id
        );
    }

    /**
     * Process a reservation batch.
     *
     * @param int   $offset
     * @param int   $limit
     * @param array $context
     * @return array
     */
    public function process($offset, $limit, array $context = [])
    {
        $start = microtime(true);
        $batch = $this->smart_generator->generate_batch($offset, $limit);

        $results = [
            'attempted' => 0,
            'valid' => 0,
            'prepared' => 0,
            'saved' => 0,
            'skipped' => 0,
            'duplicates' => 0,
            'valid_combinations' => [],
            'messages' => [],
            'errors' => [],
            'saved_ids' => [],
            'duration' => 0.0,
        ];

        foreach ($batch as $combination) {
            $results['attempted']++;

            $evaluation = $this->evaluate_core_layers($combination);
            if (! $evaluation['valid']) {
                $results['skipped']++;
                if (!empty($evaluation['missing'])) {
                    $results['messages'][] = sprintf(
                        'Skipped combination missing core layers (%s)',
                        implode(', ', $evaluation['missing'])
                    );
                }
                continue;
            }

            $results['valid']++;

            if (! empty($this->options['save_presets'])) {
                $saved = $this->attempt_save_preset($combination);
                if (is_wp_error($saved)) {
                    $results['errors'][] = $saved->get_error_message();
                    if ('duplicate' === $saved->get_error_code() || 'duplicate_config' === $saved->get_error_code()) {
                        $results['duplicates']++;
                        $results['skipped']++;
                    }
                    continue;
                }

                if ($saved > 0) {
                    $results['saved']++;
                    $results['saved_ids'][] = $saved;
                }

                if (! empty($this->options['expand_for_ui'])) {
                    $results['valid_combinations'][] = [
                        'base_combination' => $combination,
                        'preset_name' => $this->saver->generate_preset_name($combination, []),
                        'expanded_configuration' => $this->safe_build_configuration($combination),
                    ];
                    $results['prepared']++;
                }

                continue;
            }

            // Default UI mode: prepare combination for frontend expansion.
            $results['valid_combinations'][] = [
                'base_combination' => $combination,
                'preset_name' => $this->saver->generate_preset_name($combination, []),
                'expanded_configuration' => $this->safe_build_configuration($combination),
            ];
            $results['prepared']++;
        }

        $results['duration'] = microtime(true) - $start;

        return $results;
    }

    /**
     * Try to persist a preset combination server-side.
     *
     * @param array $combination
     * @return int|WP_Error
     */
    private function attempt_save_preset(array $combination)
    {
        $preset_id = $this->saver->save_preset($combination, [
            'skip_duplicates' => true,
            'skip_thumbnail' => !empty($this->options['skip_thumbnail']),
        ]);

        if (is_wp_error($preset_id)) {
            return $preset_id;
        }

        return (int) $preset_id;
    }

    /**
     * Safely build complete configuration, catching exceptions.
     *
     * @param array $combination
     * @return array
     */
    private function safe_build_configuration(array $combination)
    {
        try {
            return $this->config_builder->build_complete_configuration($combination);
        } catch (Exception $e) {
            error_log('Bulk Worker: Failed to build configuration - ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Verify required core layer selections are present.
     *
     * @param array $combination
     * @return array{valid:bool,missing:array}
     */
    private function evaluate_core_layers(array $combination)
    {
        if (empty($this->core_layers_required)) {
            return [
                'valid' => true,
                'missing' => [],
            ];
        }

        $core_selections = array_fill_keys($this->core_layers_required, false);
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
}
