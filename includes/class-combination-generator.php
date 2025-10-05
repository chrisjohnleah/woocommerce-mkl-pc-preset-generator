<?php

/**
 * Combination Generator
 * 
 * Generates all possible combinations of layer choices for a product
 */

if (! defined('ABSPATH')) {
    exit;
}

class MKL_PC_Preset_Combination_Generator
{

    private $product_id;
    private $db;

    /**
     * Constructor
     */
    public function __construct($product_id)
    {
        $this->product_id = $product_id;
        $this->db = \MKL\PC\Plugin::instance()->db;
    }

    /**
     * Get all layers for the product
     */
    public function get_layers()
    {
        $layers = $this->db->get('layers', $this->product_id);
        return is_array($layers) ? $layers : [];
    }

    /**
     * Get all choices for a specific layer
     */
    public function get_layer_choices($layer_id)
    {
        $content = $this->db->get('content', $this->product_id);

        if (! is_array($content)) {
            return [];
        }

        // Find the content for this specific layer
        foreach ($content as $layer_content) {
            if (isset($layer_content['layerId']) && $layer_content['layerId'] == $layer_id) {
                return isset($layer_content['choices']) ? $layer_content['choices'] : [];
            }
        }

        return [];
    }

    /**
     * Get user-facing layers (not visual/hidden layers)
     * 
     * Filters out non-interactive layers that shouldn't be part of preset combinations.
     * Can be customised per product using the filter hook.
     */
    public function get_user_layers()
    {
        $all_layers = $this->get_layers();

        $user_layers = array_filter($all_layers, function ($layer) {
            // Skip hidden layers
            if (isset($layer['hide_in_configurator']) && $layer['hide_in_configurator']) {
                return false;
            }

            // Skip visual layers (those starting with "Visual -")
            // This is a common naming convention for conditional display layers
            if (isset($layer['name']) && stripos($layer['name'], 'Visual -') === 0) {
                return false;
            }

            // Skip group layers (they contain other layers)
            if (isset($layer['type']) && $layer['type'] === 'group') {
                return false;
            }

            // Skip form layers (would need special handling for text inputs)
            if (isset($layer['type']) && $layer['type'] === 'form') {
                return false;
            }

            /**
             * Filter to allow custom layer inclusion logic per product
             * 
             * @param bool   $include     Whether to include this layer (default: true)
             * @param array  $layer       The layer data
             * @param int    $product_id  The product ID
             */
            return apply_filters('mkl_pc_preset_generator_include_layer', true, $layer, $this->product_id);
        });

        /**
         * Filter the final list of user-facing layers
         * 
         * @param array $user_layers  Array of layers to include in generation
         * @param int   $product_id   The product ID
         */
        return apply_filters('mkl_pc_preset_generator_user_layers', $user_layers, $this->product_id);
    }

    /**
     * Generate combinations using conditional logic-aware recursive approach
     * 
     * @param int $batch_offset Start offset for batch processing
     * @param int $batch_size Number of combinations per batch
     * @return array Array of combinations in this batch
     */
    public function generate_combinations_batch($batch_offset = 0, $batch_size = 100)
    {
        $layers = $this->get_user_layers();

        if (empty($layers)) {
            return [];
        }

        // Sort layers by order to ensure consistent generation
        usort($layers, function ($a, $b) {
            return ($a['order'] ?? 0) - ($b['order'] ?? 0);
        });

        // Prepare layer choices
        $layer_choices_map = [];
        foreach ($layers as $layer) {
            $choices = $this->get_layer_choices($layer['_id']);

            if (empty($choices)) {
                continue;
            }

            $layer_choices = [];
            $is_required = isset($layer['required']) && !empty($layer['required']);

            // Core layers should ALWAYS have a real selection, not "None"
            // This prevents generating useless presets like "None + None + None..."
            $core_layers = ['Size', 'Colour', 'Worktop'];
            $is_core_layer = in_array($layer['name'], $core_layers);

            // Add "no selection" option for non-required, non-core layers
            if (! $is_required && ! $is_core_layer && $layer['type'] === 'simple') {
                $layer_choices[] = [
                    'layer_id' => $layer['_id'],
                    'choice_id' => null,
                    'layer_name' => $layer['name'],
                    'choice_name' => 'None',
                ];
            }

            foreach ($choices as $choice) {
                // Skip group choices
                if (isset($choice['is_group']) && $choice['is_group']) {
                    continue;
                }

                // Choices can use either 'id' or '_id' depending on context
                $choice_id = isset($choice['id']) ? $choice['id'] : (isset($choice['_id']) ? $choice['_id'] : null);

                $layer_choices[] = [
                    'layer_id' => $layer['_id'],
                    'choice_id' => $choice_id,
                    'layer_name' => $layer['name'],
                    'choice_name' => isset($choice['name']) ? $choice['name'] : '',
                ];
            }

            if (! empty($layer_choices)) {
                $layer_choices_map[$layer['_id']] = $layer_choices;
            }
        }

        // Generate combinations for this batch only
        $combinations = $this->cartesian_product_batch($layer_choices_map, $batch_offset, $batch_size);

        return $combinations;
    }

    /**
     * Legacy method for backward compatibility
     */
    public function generate_combinations($limit = 0)
    {
        return $this->generate_combinations_batch(0, $limit > 0 ? $limit : 100);
    }

    /**
     * Generate cartesian product batch using indexed access
     * Can jump directly to any offset without generating all previous combinations
     * 
     * @param array $arrays Associative array of layer_id => choices
     * @param int $offset Starting index
     * @param int $size Number of combinations to generate
     * @return array
     */
    private function cartesian_product_batch($arrays, $offset = 0, $size = 100)
    {
        if (empty($arrays)) {
            return [];
        }

        // Get dimension sizes (how many choices per layer)
        $dimensions = [];
        $layer_ids = [];
        foreach ($arrays as $layer_id => $choices) {
            $layer_ids[] = $layer_id;
            $dimensions[] = count($choices);
        }

        $batch = [];

        // Generate combinations from offset to offset + size
        for ($i = $offset; $i < $offset + $size; $i++) {
            $combination = $this->index_to_combination($i, $arrays, $dimensions, $layer_ids);

            if ($combination === null) {
                // We've exceeded the total number of combinations
                break;
            }

            $batch[] = $combination;
        }

        return $batch;
    }

    /**
     * Convert a linear index to a specific combination
     * Uses mixed-radix number system
     */
    private function index_to_combination($index, $arrays, $dimensions, $layer_ids)
    {
        $combination = [];
        $remaining = $index;

        // Work backwards through dimensions
        for ($d = count($dimensions) - 1; $d >= 0; $d--) {
            $dim_size = $dimensions[$d];
            $choice_index = $remaining % $dim_size;
            $remaining = floor($remaining / $dim_size);

            $layer_id = $layer_ids[$d];
            $choices_array = array_values($arrays[$layer_id]);

            if (!isset($choices_array[$choice_index])) {
                // Invalid index
                return null;
            }

            // Prepend to maintain correct order
            array_unshift($combination, $choices_array[$choice_index]);
        }

        // If there's remaining, the index is out of bounds
        if ($remaining > 0) {
            return null;
        }

        return $combination;
    }

    /**
     * Legacy cartesian product method
     */
    private function cartesian_product($arrays, $limit = 0)
    {
        return $this->cartesian_product_batch($arrays, 0, $limit > 0 ? $limit : PHP_INT_MAX);
    }

    /**
     * Calculate total combinations without generating them
     */
    public function calculate_total_combinations()
    {
        $layers = $this->get_user_layers();
        $total = 1;

        foreach ($layers as $layer) {
            $choices = $this->get_layer_choices($layer['_id']);
            $choice_count = count(array_filter($choices, function ($c) {
                return !isset($c['is_group']) || !$c['is_group'];
            }));

            // Add 1 for "no selection" if not required
            $is_required = isset($layer['required']) && !empty($layer['required']);
            if (!$is_required && $layer['type'] === 'simple') {
                $choice_count++;
            }

            if ($choice_count > 0) {
                $total *= $choice_count;
            }
        }

        return $total;
    }

    /**
     * Estimate total number of combinations
     * This generates and validates all combinations to get an accurate count
     */
    public function estimate_total_combinations()
    {
        // Generate all theoretical combinations
        $all_combinations = $this->generate_combinations();

        // The actual valid count will be determined when we validate with conditional logic
        // For now, return the raw count - the batch processor will filter invalid ones
        $raw_count = count($all_combinations);

        error_log('Raw combination count (before conditional validation): ' . $raw_count);

        return $raw_count;
    }

    /**
     * Get theoretical maximum combinations (for debugging)
     */
    public function get_theoretical_max()
    {
        $layers = $this->get_user_layers();
        $total = 1;

        foreach ($layers as $layer) {
            $choices = $this->get_layer_choices($layer['_id']);
            $choice_count = 0;

            foreach ($choices as $choice) {
                if (! isset($choice['is_group']) || ! $choice['is_group']) {
                    $choice_count++;
                }
            }

            // Add 1 for "no selection" if not required
            $is_required = isset($layer['required']) && $layer['required'];
            if (! $is_required && $layer['type'] === 'simple') {
                $choice_count++;
            }

            if ($choice_count > 0) {
                $total *= $choice_count;
            }
        }

        return $total;
    }
}
