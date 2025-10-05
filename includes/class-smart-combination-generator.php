<?php

/**
 * Smart Combination Generator
 * 
 * Generates ONLY valid combinations by using conditional logic to guide generation.
 * This is much faster than brute-force + filter approach.
 * 
 * Uses Constraint Satisfaction Problem (CSP) approach:
 * - Builds combinations incrementally
 * - Checks constraints at each step
 * - Prunes invalid branches early
 */

if (! defined('ABSPATH')) {
    exit;
}

class MKL_PC_Smart_Combination_Generator
{
    private $product_id;
    private $layers;
    private $conditions;
    private $validator;
    private $db;

    public function __construct($product_id)
    {
        $this->product_id = $product_id;
        $this->db = \MKL\PC\Plugin::instance()->db;
        $this->validator = new MKL_PC_Preset_Conditional_Validator($product_id);
        $this->load_layers();
        $this->load_conditions();
    }

    /**
     * Load user-facing layers
     */
    private function load_layers()
    {
        $all_layers = $this->db->get('layers', $this->product_id);
        $this->layers = [];

        if (! is_array($all_layers)) {
            return;
        }

        foreach ($all_layers as $layer) {
            $type = isset($layer['type']) ? $layer['type'] : 'user';

            // Skip non-user layers
            if (in_array($type, ['visual', 'group', 'form'])) {
                continue;
            }

            // Get layer choices
            $choices = $this->db->get_indexed('content', 'layerId', $this->product_id);
            $layer_choices = isset($choices[$layer['id']]) ? $choices[$layer['id']]['choices'] : [];

            // Skip layers with no choices
            if (empty($layer_choices)) {
                continue;
            }

            // Filter out "None" options for core layers
            $layer_name = isset($layer['name']) ? $layer['name'] : '';
            $is_core_layer = in_array($layer_name, ['Size', 'Colour', 'Worktop']);

            $valid_choices = [];
            foreach ($layer_choices as $choice) {
                $choice_name = isset($choice['name']) ? $choice['name'] : '';

                // Skip "None" for core layers
                if ($is_core_layer && $choice_name === 'None') {
                    continue;
                }

                $valid_choices[] = $choice;
            }

            if (empty($valid_choices)) {
                continue;
            }

            $this->layers[] = [
                'id' => $layer['id'],
                'name' => $layer_name,
                'choices' => $valid_choices,
                'is_core' => $is_core_layer
            ];
        }

        error_log("Smart Generator: Loaded " . count($this->layers) . " layers for constraint-based generation");
        
        // Debug: Show first 3 layers
        foreach (array_slice($this->layers, 0, 3) as $layer) {
            error_log("  Layer: " . $layer['name'] . " (" . count($layer['choices']) . " choices)");
        }
    }

    /**
     * Load conditional logic rules
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
     * Generate all valid combinations using CSP approach
     * 
     * @return array Array of valid combinations
     */
    public function generate_valid_combinations()
    {
        $valid_combinations = [];
        $start_time = microtime(true);

        error_log("=== SMART GENERATION START ===");
        error_log("Using constraint-based approach to generate ONLY valid combinations");

        // Start recursive generation
        $this->generate_recursive(0, [], $valid_combinations);

        $elapsed = round(microtime(true) - $start_time, 2);
        error_log("Smart Generator: Generated " . count($valid_combinations) . " valid combinations in {$elapsed}s");
        error_log("=== SMART GENERATION END ===");

        return $valid_combinations;
    }

    /**
     * Recursively generate combinations with constraint checking
     * 
     * @param int $layer_index Current layer index
     * @param array $current_selection Current partial combination
     * @param array &$valid_combinations Output array for valid combinations
     */
    private function generate_recursive($layer_index, $current_selection, &$valid_combinations)
    {
        // Base case: we've made selections for all layers
        if ($layer_index >= count($this->layers)) {
            // Final validation check
            if ($this->validator->validate_combination($current_selection)) {
                $valid_combinations[] = $current_selection;
                
                // Debug: Log first valid combo
                static $first_valid_logged = false;
                if (!$first_valid_logged) {
                    error_log("First valid combination found!");
                    $first_valid_logged = true;
                }
            }
            return;
        }

        $current_layer = $this->layers[$layer_index];
        
        // Debug: Log when exploring first layer
        static $first_layer_logged = false;
        if ($layer_index == 0 && !$first_layer_logged) {
            error_log("Exploring layer 0: " . $current_layer['name'] . " with " . count($current_layer['choices']) . " choices");
            $first_layer_logged = true;
        }

        // For each possible choice in this layer
        foreach ($current_layer['choices'] as $choice) {
            // Build the selection with this choice
            $selection = array_merge($current_selection, [[
                'layer_id' => $current_layer['id'],
                'layer_name' => $current_layer['name'],
                'choice_id' => isset($choice['id']) ? $choice['id'] : (isset($choice['_id']) ? $choice['_id'] : null),
                'choice_name' => isset($choice['name']) ? $choice['name'] : ''
            ]]);

            // Early pruning using partial validation
            $is_valid_partial = $this->validator->validate_partial_combination($selection);
            
            // Debug first few rejections
            static $rejection_count = 0;
            if (!$is_valid_partial && $rejection_count < 5) {
                error_log("PRUNED at layer $layer_index: " . json_encode($selection));
                $rejection_count++;
            }
            
            if ($is_valid_partial) {
                $this->generate_recursive($layer_index + 1, $selection, $valid_combinations);
            }
        }
    }

    /**
     * Check if a partial combination is valid
     * This allows early pruning of invalid branches
     * 
     * @param array $partial_selection Partial combination
     * @return bool True if partial selection doesn't violate any constraints
     */
    private function is_partial_valid($partial_selection)
    {
        // Quick validation - does this partial selection violate any conditions?
        // We use the same validator but on a partial combination
        return $this->validator->validate_combination($partial_selection);
    }

    /**
     * Get count of valid combinations (for estimation)
     * 
     * @return int
     */
    public function count_valid_combinations()
    {
        $combinations = $this->generate_valid_combinations();
        return count($combinations);
    }

    /**
     * Generate combinations in batches for memory efficiency
     * 
     * @param int $offset Starting offset
     * @param int $limit Batch size
     * @return array Batch of combinations
     */
    public function generate_batch($offset, $limit)
    {
        // For smart generation, we generate all then slice
        // (Could be optimized further with generator pattern)
        static $all_combinations = null;

        if ($all_combinations === null) {
            $all_combinations = $this->generate_valid_combinations();
        }

        return array_slice($all_combinations, $offset, $limit);
    }
}
