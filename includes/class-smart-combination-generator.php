<?php

/**
 * Smart Combination Generator
 *
 * Generates ONLY valid combinations by using conditional logic to guide generation.
 * This mimics the frontend configurator by pruning impossible branches as soon
 * as they violate a rule, instead of brute-forcing every theoretical combination.
 */

if (! defined('ABSPATH')) {
    exit;
}

class MKL_PC_Smart_Combination_Generator
{
    private $product_id;
    private $layers = [];
    private $validator;
    private $db;
    private $core_layer_names = [];
    private $content_map = [];
    private $conditions = [];
    private $count_time_limit = null;
    private $count_max_results = null;
    private $count_started_at = 0.0;
    private $count_terminated = false;
    private $dfs_visited = 0;

    public function __construct($product_id)
    {
        $this->product_id = $product_id;
        $this->db = \MKL\PC\Plugin::instance()->db;
        $this->validator = new MKL_PC_Preset_Conditional_Validator($product_id);
        $this->core_layer_names = apply_filters(
            'mkl_pc_preset_generator_core_layers',
            ['Size', 'Colour', 'Worktop'],
            $product_id
        );

        $this->load_layers();
        $this->load_conditions();
    }

    /**
     * Prepare layer metadata and available choices for CSP traversal.
     */
    private function load_layers()
    {
        $combination_generator = new MKL_PC_Preset_Combination_Generator($this->product_id);
        $user_layers = $combination_generator->get_user_layers();
        $this->layers = [];

        if (empty($user_layers) || ! is_array($user_layers)) {
            error_log("Smart Generator: No user-facing layers found for product {$this->product_id}");
            return;
        }

        $content_rows = $this->db->get('content', $this->product_id);

        if (is_array($content_rows)) {
            foreach ($content_rows as $row) {
                if (isset($row['layerId'])) {
                    $this->content_map[$row['layerId']] = $row;
                }
            }
        }

        foreach ($user_layers as $layer) {
            $layer_id = isset($layer['_id']) ? $layer['_id'] : (isset($layer['id']) ? $layer['id'] : null);
            if (! $layer_id) {
                continue;
            }

            $layer_type = isset($layer['type']) ? $layer['type'] : 'simple';
            $layer_name = isset($layer['name']) ? $layer['name'] : '';
            $is_required = ! empty($layer['required']);
            $is_core_layer = in_array($layer_name, $this->core_layer_names, true);

            $choices_entry = isset($this->content_map[$layer_id]) ? $this->content_map[$layer_id] : [];
            $available_choices = isset($choices_entry['choices']) && is_array($choices_entry['choices'])
                ? $choices_entry['choices']
                : [];

            $valid_choices = [];

            // Allow "no selection" for optional simple layers
            if (! $is_required && ! $is_core_layer && $layer_type === 'simple') {
                $valid_choices[] = [
                    'id' => null,
                    'name' => __('None', 'mkl-pc-preset-generator'),
                ];
            }

            foreach ($available_choices as $choice) {
                if (! empty($choice['is_group'])) {
                    continue;
                }

                $choice_id = isset($choice['id'])
                    ? $choice['id']
                    : (isset($choice['_id']) ? $choice['_id'] : null);

                if ($choice_id === null) {
                    continue;
                }

                $choice_name = isset($choice['name']) ? $choice['name'] : '';

                // Core layers must never use the "None" placeholder
                if ($is_core_layer && in_array($choice_name, ['None', 'No'], true)) {
                    continue;
                }

                $valid_choices[] = [
                    'id' => $choice_id,
                    'name' => $choice_name,
                ];
            }

            if (empty($valid_choices)) {
                continue;
            }

            $this->layers[] = [
                'id' => $layer_id,
                'name' => $layer_name,
                'type' => $layer_type,
                'is_core' => $is_core_layer,
                'is_required' => $is_required,
                'choices' => $valid_choices,
            ];
        }

        error_log(sprintf('Smart Generator: Prepared %d layers for product %d', count($this->layers), $this->product_id));
        foreach (array_slice($this->layers, 0, 3) as $layer) {
            error_log(sprintf('  Layer %s (%d choices)', $layer['name'], count($layer['choices'])));
        }
    }

    /**
     * Load conditional rule set for logging/debugging purposes.
     */
    private function load_conditions()
    {
        $this->conditions = $this->db->get('conditions', $this->product_id);

        if (! is_array($this->conditions)) {
            $this->conditions = [];
        }

        error_log("Smart Generator: Loaded " . count($this->conditions) . " conditions");
    }

    /**
     * Generate every valid combination (memory heavy - mainly for debugging).
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function generate_valid_combinations()
    {
        $valid_combinations = [];
        $valid_counter = 0;
        $collected = 0;
        $start_time = microtime(true);

        error_log('=== SMART GENERATION START ===');
        error_log('Using constraint-based approach to generate ONLY valid combinations');

        $this->explore(0, [], $valid_combinations, 0, null, $valid_counter, $collected);

        $elapsed = round(microtime(true) - $start_time, 2);
        error_log(sprintf('Smart Generator: Generated %d valid combinations in %ss', count($valid_combinations), $elapsed));
        error_log('=== SMART GENERATION END ===');

        return $valid_combinations;
    }

    /**
     * Count valid combinations without keeping them in memory.
     */
    public function count_valid_combinations($max_results = null, $time_limit = null)
    {
        $collector = null;
        $valid_counter = 0;
        $collected = 0;

        $this->count_max_results = $max_results !== null ? max(0, (int) $max_results) : null;
        $this->count_time_limit = $time_limit !== null ? max(0, (float) $time_limit) : null;
        $this->count_started_at = microtime(true);
        $this->count_terminated = false;

        $this->explore(0, [], $collector, 0, null, $valid_counter, $collected);

        $elapsed = microtime(true) - $this->count_started_at;

        $result = [
            'count' => $valid_counter,
            'complete' => ! $this->count_terminated,
            'elapsed' => $elapsed,
        ];

        $this->count_max_results = null;
        $this->count_time_limit = null;
        $this->count_started_at = 0.0;

        if ($max_results === null && $time_limit === null) {
            return $result['count'];
        }

        return $result;
    }

    /**
     * Generate a batch of valid combinations starting at the given offset.
     *
     * @param int $offset Zero-based index of the first valid combination to return.
     * @param int $limit  Maximum number of combinations to return.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function generate_batch($offset, $limit)
    {
        $offset = max(0, intval($offset));
        $limit = max(0, intval($limit));

        if ($limit === 0) {
            return [];
        }

        $collector = [];
        $valid_counter = 0;
        $collected = 0;

        $this->explore(0, [], $collector, $offset, $limit, $valid_counter, $collected);

        return $collector;
    }

    /**
     * Fast path: find the first valid, image-producing combination by exploring
     * core layers first and pruning with conditional rules.
     *
     * @param int $time_limit_sec Max time in seconds to search
     * @return array|null Combination array or null if none found within limit
     */
    public function find_first_valid_combination($time_limit_sec = 5)
    {
        if (empty($this->layers)) {
            return null;
        }

        // Order layers: core first, then by increasing number of choices
        $layers = $this->ordered_layers_for_fast_search();
        $deadline = microtime(true) + max(0, (float)$time_limit_sec);
        $this->dfs_visited = 0;

        $result = $this->dfs_first_valid($layers, 0, [], $deadline);
        return $result ? $result : null;
    }

    private function ordered_layers_for_fast_search()
    {
        $layers = $this->layers;
        usort($layers, function ($a, $b) {
            // Put core first
            $coreA = !empty($a['is_core']) ? 1 : 0;
            $coreB = !empty($b['is_core']) ? 1 : 0;
            if ($coreA !== $coreB) {
                return $coreB - $coreA; // core (1) before non-core (0)
            }
            // Fewer choices first to prune quicker
            $ca = isset($a['choices']) && is_array($a['choices']) ? count($a['choices']) : 0;
            $cb = isset($b['choices']) && is_array($b['choices']) ? count($b['choices']) : 0;
            if ($ca !== $cb) {
                return $ca - $cb;
            }
            // Stable fallback by order/name
            $oa = isset($a['order']) ? (int)$a['order'] : 0;
            $ob = isset($b['order']) ? (int)$b['order'] : 0;
            if ($oa !== $ob) return $oa - $ob;
            $na = isset($a['name']) ? $a['name'] : '';
            $nb = isset($b['name']) ? $b['name'] : '';
            return strcmp($na, $nb);
        });
        return $layers;
    }

    private function dfs_first_valid($layers, $index, $current_selection, $deadline)
    {
        if (microtime(true) > $deadline) {
            return null;
        }

        $total = count($layers);
        if ($index >= $total) {
            // Full assignment; ensure it's valid by conditional rules
            if ($this->validator->validate_combination($current_selection)) {
                return $current_selection;
            }
            return null;
        }

        $layer = $layers[$index];
        $layer_id = $layer['id'];
        $layer_name = isset($layer['name']) ? $layer['name'] : '';
        $is_core = !empty($layer['is_core']);
        $required = $is_core || !empty($layer['is_required']);
        $choices = isset($layer['choices']) && is_array($layer['choices']) ? $layer['choices'] : [];

        // Compute current visibility/enabled state to detect forced/sync constraints
        $state = $this->validator->get_visibility_state_for_combination($current_selection);
        $forced_id = isset($state['layers'][$layer_id]['forced_choice']) ? $state['layers'][$layer_id]['forced_choice'] : null;

        // Build candidate set
        $candidate_choices = [];
        if ($forced_id !== null) {
            // Respect forced selection
            $candidate_choices[] = [ 'id' => $forced_id, 'name' => null ];
        } else {
            // For non-core optional layers, prefer None to mirror frontend (do nothing) path
            if (!$required) {
                $candidate_choices[] = [ 'id' => null, 'name' => 'None' ];
            }
            foreach ($choices as $c) {
                $candidate_choices[] = $c;
            }
        }

        foreach ($candidate_choices as $choice) {
            $next = $current_selection;
            $next[] = [
                'layer_id' => $layer_id,
                'layer_name' => $layer_name,
                'choice_id' => isset($choice['id']) ? $choice['id'] : null,
                'choice_name' => isset($choice['name']) ? $choice['name'] : null,
            ];

            // Skip disallowed choices (not visible or disabled) using current state
            $cid = isset($choice['id']) ? $choice['id'] : null;
            if ($cid !== null) {
                $allowed = true;
                if (isset($state['choices'][$layer_id][$cid])) {
                    $cst = $state['choices'][$layer_id][$cid];
                    if (isset($cst['visible']) && !$cst['visible']) $allowed = false;
                    if (isset($cst['enabled']) && $cst['enabled'] === false) $allowed = false;
                }
                if (!$allowed) {
                    continue;
                }
            }

            if (! $this->validator->validate_partial_combination($next)) {
                continue;
            }

            // Progress signal for WP-CLI runs
            $this->dfs_visited++;
            if (defined('MKL_PC_PG_PROGRESS') && MKL_PC_PG_PROGRESS && defined('WP_CLI') && WP_CLI) {
                if (($this->dfs_visited % 100) === 0) {
                    echo 'DFS:depth=' . $index . '/' . $total . ' visited=' . $this->dfs_visited . "\n";
                }
            }

            $sol = $this->dfs_first_valid($layers, $index + 1, $next, $deadline);
            if ($sol) return $sol;
        }

        return null;
    }

    /**
     * Generate a random combination (may be invalid) by sampling each layer's choices.
     *
     * @return array{combination: array<int, array<string,mixed>>, valid: bool}|
     *         null When no layers are available.
     */
    public function sample_random_combination()
    {
        if (empty($this->layers)) {
            return null;
        }

        $selection = [];

        foreach ($this->layers as $layer) {
            if (empty($layer['choices'])) {
                continue;
            }

            $choices = $layer['choices'];

            if (!empty($layer['is_core']) || !empty($layer['is_required'])) {
                $non_null = array_values(array_filter($choices, function ($choice) {
                    return null !== $choice['id'];
                }));

                if (!empty($non_null)) {
                    $choices = $non_null;
                }
            }

            $choice = $choices[array_rand($choices)];

            $selection[] = [
                'layer_id' => $layer['id'],
                'layer_name' => $layer['name'],
                'choice_id' => $choice['id'],
                'choice_name' => $choice['name'],
            ];
        }

        $is_valid = $this->validator->validate_combination($selection);

        return [
            'combination' => $selection,
            'valid' => $is_valid,
        ];
    }

    /**
     * Depth-first traversal with backtracking. Stops early when we collected enough results.
     *
     * @param int        $layer_index
     * @param array      $current_selection
     * @param array|null &$collector
     * @param int        $offset
     * @param int|null   $limit
     * @param int        &$valid_counter
     * @param int        &$collected
     *
     * @return bool True when traversal can stop early.
     */
    private function explore($layer_index, $current_selection, &$collector, $offset, $limit, &$valid_counter, &$collected)
    {
        if ($this->count_time_limit !== null) {
            if ((microtime(true) - $this->count_started_at) >= $this->count_time_limit) {
                $this->count_terminated = true;
                return true;
            }
        }

        if ($this->count_max_results !== null && $limit === null && $this->count_max_results > 0) {
            if ($valid_counter >= $this->count_max_results) {
                $this->count_terminated = true;
                return true;
            }
        }

        $total_layers = count($this->layers);

        if ($layer_index >= $total_layers) {
            if ($this->validator->validate_combination($current_selection)) {
                if ($valid_counter >= $offset) {
                    if ($limit === null || $collected < $limit) {
                        if (is_array($collector)) {
                            $collector[] = $current_selection;
                        }

                        if ($limit !== null) {
                            $collected++;
                        }
                    }
                }

                $valid_counter++;

                if ($this->count_max_results !== null && $limit === null && $valid_counter >= $this->count_max_results) {
                    $this->count_terminated = true;
                    return true;
                }

                if ($this->count_time_limit !== null) {
                    if ((microtime(true) - $this->count_started_at) >= $this->count_time_limit) {
                        $this->count_terminated = true;
                        return true;
                    }
                }

                if ($limit !== null && $collected >= $limit) {
                    return true;
                }
            }

            return false;
        }

        $layer = $this->layers[$layer_index];

        foreach ($layer['choices'] as $choice) {
            $selection = $current_selection;
            $selection[] = [
                'layer_id' => $layer['id'],
                'layer_name' => $layer['name'],
                'choice_id' => $choice['id'],
                'choice_name' => $choice['name'],
            ];

            if (! $this->validator->validate_partial_combination($selection)) {
                continue;
            }

            $should_stop = $this->explore(
                $layer_index + 1,
                $selection,
                $collector,
                $offset,
                $limit,
                $valid_counter,
                $collected
            );

            if ($should_stop) {
                return true;
            }
        }

        return false;
    }
}
