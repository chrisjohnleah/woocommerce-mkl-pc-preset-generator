<?php

/**
 * Layer Variation Expander
 *
 * Generates targeted presets by iterating selected configurator layers whilst
 * keeping the remaining configuration fixed to the administrator's current
 * selection.
 *
 * @package MKL_PC_Preset_Generator
 */

if (! defined('ABSPATH')) {
    exit;
}

class MKL_PC_Layer_Variation_Expander
{

    /**
     * Product identifier.
     *
     * @var int
     */
    private $product_id;

    /**
     * Combination generator helper.
     *
     * @var MKL_PC_Preset_Combination_Generator
     */
    private $combination_generator;

    /**
     * Conditional validator.
     *
     * @var MKL_PC_Preset_Conditional_Validator
     */
    private $validator;

    /**
     * Configuration builder.
     *
     * @var MKL_PC_Configuration_Builder
     */
    private $config_builder;

    /**
     * Preset saver (for naming and duplicate checks).
     *
     * @var MKL_PC_Preset_Saver
     */
    private $preset_saver;

    /**
     * Cache of user-facing layers keyed by layer ID.
     *
     * @var array<int, array>
     */
    private $layer_index = [];

    /**
     * Cached map of existing configuration hashes.
     *
     * @var array<string, bool>|null
     */
    private $existing_config_hashes = null;

    /**
     * Cached map of existing preset titles.
     *
     * @var array<string, bool>|null
     */
    private $existing_titles = null;

    /**
     * Maximum number of variations to produce per request.
     *
     * @var int
     */
    private $default_limit = 200;

    /**
     * Constructor.
     *
     * @param int $product_id Product identifier.
     */
    public function __construct($product_id)
    {
        $this->product_id = (int) $product_id;

        $this->combination_generator = new MKL_PC_Preset_Combination_Generator($this->product_id);
        $this->validator = new MKL_PC_Preset_Conditional_Validator($this->product_id);
        $this->config_builder = new MKL_PC_Configuration_Builder($this->product_id);
        $this->preset_saver = new MKL_PC_Preset_Saver($this->product_id);

        $layers = $this->combination_generator->get_user_layers();

        foreach ($layers as $layer) {
            if (! isset($layer['_id'])) {
                continue;
            }

            $layer_id = (int) $layer['_id'];
            $layer['choices'] = $this->combination_generator->get_layer_choices($layer_id);
            $this->layer_index[$layer_id] = $layer;
        }

        $limit = (int) apply_filters('mkl_pc_preset_generator_variation_limit', $this->default_limit, $this->product_id);

        if ($limit > 0) {
            $this->default_limit = $limit;
        }
    }

    /**
     * Expand the current configuration across the requested layer axes.
     *
     * @param array|string $base_configuration Complete configurator state (array or JSON string).
     * @param array        $axis_layers        List of layer identifiers to iterate.
     * @param array        $args               Optional arguments: include_base, limit, skip_existing.
     *
     * @return array<string, mixed>
     */
    public function expand($base_configuration, array $axis_layers, array $args = [])
    {
        $configuration = $this->normalise_configuration($base_configuration);

        $axis_layer_ids = $this->normalise_axis_layers($axis_layers);

        if (empty($axis_layer_ids)) {
            return [
                'variations' => [],
                'total_candidates' => 0,
                'limit_reached' => false,
                'skipped' => [
                    'base' => 0,
                    'duplicate' => 0,
                    'invalid' => 0,
                ],
            ];
        }

        $base_map = $this->build_base_selection_map($configuration);

        $axes = $this->build_axis_metadata($axis_layer_ids);

        if (empty($axes)) {
            return [
                'variations' => [],
                'total_candidates' => 0,
                'limit_reached' => false,
                'skipped' => [
                    'base' => 0,
                    'duplicate' => 0,
                    'invalid' => 0,
                ],
            ];
        }

        $options = wp_parse_args(
            $args,
            [
                'include_base' => true,
                'limit' => $this->default_limit,
                'skip_existing' => true,
            ]
        );

        $options['include_base'] = isset($options['include_base']) ? (bool) $options['include_base'] : true;
        $options['skip_existing'] = isset($options['skip_existing']) ? (bool) $options['skip_existing'] : true;

        $limit = (int) $options['limit'];

        if ($limit < 1 || $limit > $this->default_limit) {
            $limit = $this->default_limit;
        }

        $state = [
            'variations' => [],
            'total_candidates' => 0,
            'limit_reached' => false,
            'skipped' => [
                'base' => 0,
                'duplicate' => 0,
                'invalid' => 0,
            ],
        ];

        $this->walk_axes($axes, 0, [], $base_map, $state, $options, $limit);

        return $state;
    }

    /**
     * Expand variations using a base combination array.
     *
     * @param array $combination Array of layer choices as produced by the smart generator.
     * @param array $axis_layers Layer identifiers to iterate.
     * @param array $args        Optional arguments for expansion.
     *
     * @return array<string, mixed>
     */
    public function expand_from_combination(array $combination, array $axis_layers, array $args = [])
    {
        if (empty($combination)) {
            return [
                'variations' => [],
                'total_candidates' => 0,
                'limit_reached' => false,
                'skipped' => [
                    'base' => 0,
                    'duplicate' => 0,
                    'invalid' => 0,
                ],
            ];
        }

        $configuration = $this->config_builder->build_complete_configuration($combination);

        return $this->expand($configuration, $axis_layers, $args);
    }

    /**
     * Check whether a combination or preset title already exists.
     *
     * @param array  $combination Combination payload.
     * @param string $preset_name Optional preset title for fallback checks.
     *
     * @return bool
     */
    public function combination_is_already_saved(array $combination, $preset_name = '')
    {
        $this->ensure_existing_cache();

        $hash = $this->build_config_hash($combination);

        if ($hash && isset($this->existing_config_hashes[$hash])) {
            return true;
        }

        if ($hash && $this->preset_saver->preset_configuration_exists($combination)) {
            $this->existing_config_hashes[$hash] = true;
            if ($preset_name !== '') {
                $this->existing_titles[$this->normalise_title_key($preset_name)] = true;
            }
            return true;
        }

        if ($preset_name !== '') {
            $title_key = $this->normalise_title_key($preset_name);
            if (isset($this->existing_titles[$title_key])) {
                return true;
            }

            $existing = get_posts([
                'post_type' => 'mkl_pc_configuration',
                'post_status' => 'preset',
                'post_parent' => $this->product_id,
                'title' => $preset_name,
                'posts_per_page' => 1,
                'fields' => 'ids',
            ]);

            if (! empty($existing)) {
                $this->existing_titles[$title_key] = true;
                return true;
            }
        }

        return false;
    }

    /**
     * Mark a combination/title as known so subsequent duplicate checks are instantaneous.
     *
     * @param array  $combination Combination payload.
     * @param string $preset_name Preset title.
     */
    public function register_combination(array $combination, $preset_name = '')
    {
        $this->ensure_existing_cache();

        $hash = $this->build_config_hash($combination);
        if ($hash) {
            $this->existing_config_hashes[$hash] = true;
        }

        if ($preset_name !== '') {
            $this->existing_titles[$this->normalise_title_key($preset_name)] = true;
        }
    }

    /**
     * Convert incoming configuration to a normalised array.
     *
     * @param array|string $configuration Configurator state.
     *
     * @return array
     */
    private function normalise_configuration($configuration)
    {
        if (is_array($configuration)) {
            return $configuration;
        }

        if (is_string($configuration) && $configuration !== '') {
            $decoded = json_decode(stripslashes($configuration), true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        throw new \InvalidArgumentException(__('Invalid configurator state supplied.', 'mkl-pc-preset-generator'));
    }

    /**
     * Sanitise axis layer identifiers.
     *
     * @param array $axis_layers Raw axis layer identifiers.
     *
     * @return array<int>
     */
    private function normalise_axis_layers(array $axis_layers)
    {
        $ids = array_map('intval', $axis_layers);
        $ids = array_filter($ids, function ($value) {
            return $value > 0;
        });

        return array_values(array_unique($ids));
    }

    /**
     * Map the base configuration to layer selections.
     *
     * @param array $configuration Configurator state.
     *
     * @return array<int, array>
     */
    private function build_base_selection_map(array $configuration)
    {
        $map = [];

        foreach ($configuration as $entry) {
            if (empty($entry['is_choice'])) {
                continue;
            }

            if (! isset($entry['layer_id'])) {
                continue;
            }

            $layer_id = (int) $entry['layer_id'];
            $choice_id = isset($entry['choice_id']) && $entry['choice_id'] !== ''
                ? (int) $entry['choice_id']
                : null;

            $layer_name = isset($entry['layer_name']) ? $entry['layer_name'] : '';
            $choice_name = '';

            if (isset($entry['name']) && $entry['name'] !== '') {
                $choice_name = $entry['name'];
            } elseif (isset($entry['choice_name']) && $entry['choice_name'] !== '') {
                $choice_name = $entry['choice_name'];
            }

            $map[$layer_id] = [
                'layer_id' => $layer_id,
                'layer_name' => $layer_name,
                'choice_id' => $choice_id,
                'choice_name' => $choice_name,
            ];
        }

        return $map;
    }

    /**
     * Build metadata for each axis layer, including filtered choices.
     *
     * @param array<int> $axis_layer_ids Layer identifiers to iterate.
     *
     * @return array<int, array>
     */
    private function build_axis_metadata(array $axis_layer_ids)
    {
        $axes = [];

        foreach ($axis_layer_ids as $layer_id) {
            if (! isset($this->layer_index[$layer_id])) {
                continue;
            }

            $layer = $this->layer_index[$layer_id];

            if (! isset($layer['choices']) || ! is_array($layer['choices'])) {
                continue;
            }

            $choices = [];

            foreach ($layer['choices'] as $choice) {
                if (! empty($choice['is_group'])) {
                    continue;
                }

                $choice_id = null;

                if (isset($choice['id'])) {
                    $choice_id = (int) $choice['id'];
                } elseif (isset($choice['_id'])) {
                    $choice_id = (int) $choice['_id'];
                }

                if ($choice_id === null) {
                    continue;
                }

                $choice_name = isset($choice['name']) && $choice['name'] !== ''
                    ? $choice['name']
                    : sprintf(__('Choice %d', 'mkl-pc-preset-generator'), $choice_id);

                $choices[] = [
                    'layer_id' => $layer_id,
                    'layer_name' => isset($layer['name']) ? $layer['name'] : '',
                    'choice_id' => $choice_id,
                    'choice_name' => $choice_name,
                ];
            }

            if (! empty($choices)) {
                $axes[] = [
                    'layer_id' => $layer_id,
                    'layer_name' => isset($layer['name']) ? $layer['name'] : '',
                    'choices' => $choices,
                ];
            }
        }

        return $axes;
    }

    /**
     * Recursively iterate the selected axes and collect valid variations.
     *
     * @param array<int, array> $axes          Axis metadata.
     * @param int               $index         Current axis index.
     * @param array<int, array> $selection_map Current axis selections keyed by layer ID.
     * @param array<int, array> $base_map      Base layer selections keyed by layer ID.
     * @param array<string,mixed> $state       Accumulator for results.
     * @param array             $options       Behaviour flags.
     * @param int               $limit         Maximum variations to collect.
     */
    private function walk_axes(array $axes, $index, array $selection_map, array $base_map, array &$state, array $options, $limit)
    {
        if ($state['limit_reached']) {
            return;
        }

        if ($index >= count($axes)) {
            $state['total_candidates']++;

            $combined = $base_map;

            foreach ($selection_map as $layer_id => $choice) {
                $combined[$layer_id] = $choice;
            }

            $is_base_selection = $this->is_base_selection($selection_map, $base_map);

            if (! $options['include_base'] && $is_base_selection) {
                $state['skipped']['base']++;
                return;
            }

            $combination = array_values($combined);
            $preset_name = $this->preset_saver->generate_preset_name($combination, []);

            if (! $this->validator->validate_combination($combination)) {
                $state['skipped']['invalid']++;
                return;
            }

            if ($options['skip_existing'] && $this->combination_is_already_saved($combination, $preset_name)) {
                $state['skipped']['duplicate']++;
                return;
            }

            $expanded_configuration = $this->config_builder->build_complete_configuration($combination);

            $state['variations'][] = [
                'base_combination' => $combination,
                'preset_name' => $preset_name,
                'expanded_configuration' => $expanded_configuration,
                'is_base' => $is_base_selection,
            ];

            $this->register_combination($combination, $preset_name);

            if (count($state['variations']) >= $limit) {
                $state['limit_reached'] = true;
            }

            return;
        }

        $axis = $axes[$index];

        foreach ($axis['choices'] as $choice) {
            $selection_map[$axis['layer_id']] = $choice;
            $this->walk_axes($axes, $index + 1, $selection_map, $base_map, $state, $options, $limit);

            if ($state['limit_reached']) {
                break;
            }
        }
    }

    /**
     * Determine whether the current axis selections match the base configuration.
     *
     * @param array<int, array> $selection_map Axis selections.
     * @param array<int, array> $base_map      Base configuration selections.
     *
     * @return bool
     */
    private function is_base_selection(array $selection_map, array $base_map)
    {
        if (empty($selection_map)) {
            return true;
        }

        foreach ($selection_map as $layer_id => $choice) {
            if (! isset($base_map[$layer_id])) {
                return false;
            }

            $base_choice = $base_map[$layer_id];

            $current_id = isset($choice['choice_id']) ? (int) $choice['choice_id'] : null;
            $base_id = isset($base_choice['choice_id']) ? (int) $base_choice['choice_id'] : null;

            if ($current_id !== $base_id) {
                return false;
            }
        }

        return true;
    }

    /**
     * Ensure duplicate lookup caches are populated.
     * Uses batched queries to avoid memory exhaustion with thousands of presets.
     */
    private function ensure_existing_cache()
    {
        if ($this->existing_config_hashes !== null && $this->existing_titles !== null) {
            return;
        }

        if ($this->existing_config_hashes === null || $this->existing_titles === null) {
            $this->existing_config_hashes = [];
            $this->existing_titles = [];

            global $wpdb;

            // Use batched queries to avoid loading thousands of rows into memory at once
            $batch_size = 1000;
            $offset = 0;
            
            do {
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT p.post_title, hash.meta_value AS config_hash
                         FROM {$wpdb->posts} p
                         INNER JOIN {$wpdb->postmeta} prod
                            ON prod.post_id = p.ID
                           AND prod.meta_key = '_product_id'
                         LEFT JOIN {$wpdb->postmeta} hash
                            ON hash.post_id = p.ID
                           AND hash.meta_key = '_config_hash'
                         WHERE prod.meta_value = %d
                           AND p.post_type = 'mkl_pc_configuration'
                           AND p.post_status = 'preset'
                         ORDER BY p.ID ASC
                         LIMIT %d OFFSET %d",
                        $this->product_id,
                        $batch_size,
                        $offset
                    ),
                    ARRAY_A
                );

                if (is_array($rows) && ! empty($rows)) {
                    foreach ($rows as $row) {
                        if (! empty($row['config_hash'])) {
                            $this->existing_config_hashes[$row['config_hash']] = true;
                        }

                        if (! empty($row['post_title'])) {
                            $this->existing_titles[$this->normalise_title_key($row['post_title'])] = true;
                        }
                    }
                    
                    // Free memory after processing each batch
                    unset($rows);
                    
                    $offset += $batch_size;
                } else {
                    break;
                }
            } while (true);
        }
    }

    /**
     * Build a configuration hash mirroring preset saver logic.
     *
     * @param array $combination Combination payload.
     *
     * @return string
     */
    private function build_config_hash(array $combination)
    {
        if (empty($combination)) {
            return '';
        }

        $sorted = $combination;

        usort($sorted, function ($a, $b) {
            $a_id = isset($a['layer_id']) ? (int) $a['layer_id'] : 0;
            $b_id = isset($b['layer_id']) ? (int) $b['layer_id'] : 0;

            return $a_id <=> $b_id;
        });

        $parts = [];

        foreach ($sorted as $choice) {
            if (! isset($choice['layer_id']) || ! isset($choice['choice_id'])) {
                continue;
            }

            if ($choice['choice_id'] === null) {
                continue;
            }

            $parts[] = (int) $choice['layer_id'] . ':' . (string) $choice['choice_id'];
        }

        if (empty($parts)) {
            return '';
        }

        return md5(implode('|', $parts));
    }

    /**
     * Normalise title to lowercase key for caching.
     *
     * @param string $title Preset title.
     *
     * @return string
     */
    private function normalise_title_key($title)
    {
        $key = wp_strip_all_tags($title);

        if (function_exists('mb_strtolower')) {
            $key = mb_strtolower($key, 'UTF-8');
        } else {
            $key = strtolower($key);
        }

        return $key;
    }
}

