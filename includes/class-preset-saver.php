<?php

/**
 * Preset Saver
 * 
 * Saves valid combinations as presets
 */

if (! defined('ABSPATH')) {
    exit;
}

class MKL_PC_Preset_Saver
{

    private $product_id;
    private $config_builder;

    /**
     * Constructor
     */
    public function __construct($product_id)
    {
        $this->product_id = $product_id;
        $this->config_builder = new MKL_PC_Configuration_Builder($product_id);
    }

    /**
     * Save a combination as a preset
     * 
     * @param array $combination Array of selected choices
     * @param array $options Additional options (e.g., name_prefix)
     * @return int|WP_Error Preset ID on success, WP_Error on failure
     */
    public function save_preset($combination, $options = [])
    {
        // Generate preset name
        $preset_name = $this->generate_preset_name($combination, $options);

        // Check if preset with this name already exists
        if ($this->preset_exists($preset_name)) {
            // Optionally skip or append number
            if (isset($options['skip_duplicates']) && $options['skip_duplicates']) {
                return new WP_Error('duplicate', __('Preset with this configuration already exists', 'mkl-pc-preset-generator'));
            }

            // Append number to make unique
            $preset_name = $this->make_unique_name($preset_name);
        }

        // Convert combination to complete configurator format (including visual layers)
        $configuration_data = $this->config_builder->build_complete_configuration($combination);
        $configuration_data = $this->normalize_configuration_layers($configuration_data);

        error_log("Attempting to save preset: $preset_name with " . count($configuration_data) . " layers");

        // Check for duplicate configurations FIRST (before name check)
        if ($this->preset_configuration_exists($combination)) {
            error_log("Skipping - preset configuration already exists");
            return new WP_Error('duplicate_config', __('Preset with this exact configuration already exists', 'mkl-pc-preset-generator'));
        }

        error_log("Configuration data: " . json_encode($configuration_data));

        // Create preset configuration object
        try {
            $preset = new Mkl_PC_Preset_Configuration(0);
            // Enable async image generation for thumbnails
            $preset->save_image_async = true;
            $preset->should_save_image = true;
            error_log("Created preset object, async image saving enabled");
        } catch (Exception $e) {
            error_log("Error creating preset object: " . $e->getMessage());
            return new WP_Error('create_failed', $e->getMessage());
        }

        // WordPress wp_insert_post() expects post_content to be a STRING, not an array!
        // We need to JSON-encode the configuration data
        $content_string = is_array($configuration_data) ? wp_json_encode($configuration_data) : $configuration_data;

        error_log("Calling preset->save() with JSON string length: " . strlen($content_string));

        // Save preset using the standard save method
        try {
            $saved = $preset->save([
                'content' => $content_string,  // Must be JSON string for wp_insert_post!
                'product_id' => $this->product_id,
                'customer_id' => get_current_user_id(),
                'title' => $preset_name,
                'configuration_id' => 0,
            ]);
        } catch (Exception $e) {
            error_log("Exception during save: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return new WP_Error('save_exception', $e->getMessage());
        }

        error_log("Save returned: " . print_r($saved, true));

        if (! isset($saved['saved']) || ! $saved['saved']) {
            $error_msg = isset($saved['error']) ? $saved['error'] : 'Unknown error';
            error_log("Save failed: $error_msg");
            return new WP_Error('save_failed', __('Failed to save preset: ', 'mkl-pc-preset-generator') . $error_msg);
        }

        $preset_id = isset($saved['config_id']) ? intval($saved['config_id']) : (isset($saved['ID']) ? intval($saved['ID']) : 0);

        if (! $preset_id) {
            return new WP_Error('no_id', __('Preset ID not returned', 'mkl-pc-preset-generator'));
        }

        // Store configuration hash to prevent future duplicates
        $config_hash = $this->hash_combination($combination);
        update_post_meta($preset_id, '_config_hash', $config_hash);

        // Ensure post status is 'preset'
        wp_update_post([
            'ID' => $preset_id,
            'post_status' => 'preset',
        ]);

        // If async image saving was requested, manually trigger it now
        // (Frontend would normally do this via AJAX, but we're on the backend)
        if (isset($saved['save_image_async']) && $saved['save_image_async']) {
            error_log("Triggering image generation for preset #$preset_id");
            
            // Use our image generator wrapper for better error handling and diagnostics
            // Pass null to let generator use stored content or decode as needed
            $image_result = MKL_PC_Preset_Image_Generator::generate_image($preset_id, null);
            
            if (is_wp_error($image_result)) {
                error_log("Image generation failed: " . $image_result->get_error_message());
                // Don't fail the preset save if image generation fails
            } else {
                error_log("Image generation successful, attachment ID: $image_result");
            }
        }

        return $preset_id;
    }

    /**
     * Sort configuration layers so image merge respects admin stacking order
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
     * Determine ordering value for configuration layer
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
     * Safely fetch value from layer arrays/objects
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

    /**
     * Convert combination array to configurator configuration format
     * 
     * @param array $combination
     * @return array
     */
    private function combination_to_configuration($combination)
    {
        $configuration = [];

        foreach ($combination as $choice) {
            // Skip null selections (no choice made for this layer)
            if ($choice['choice_id'] === null) {
                continue;
            }

            $configuration[] = [
                'is_choice' => true,
                'layer_id' => $choice['layer_id'],
                'choice_id' => $choice['choice_id'],
                'angle_id' => 1, // Default angle
                'layer_name' => $choice['layer_name'],
                'name' => $choice['choice_name'],
                'image' => 0,
            ];
        }

        return $configuration;
    }

    /**
     * Generate a descriptive name for the preset
     * 
     * @param array $combination
     * @param array $options
     * @return string
     */
    public function generate_preset_name($combination, $options = [])
    {
        // Get product name for prefix
        $product = wc_get_product($this->product_id);
        $product_prefix = $product ? $product->get_name() . ' - ' : '';

        $prefix = isset($options['name_prefix']) ? $options['name_prefix'] : $product_prefix;
        $separator = isset($options['name_separator']) ? $options['name_separator'] : ' - ';

        // Build name from selected choices
        $name_parts = [];

        foreach ($combination as $choice) {
            if (
                $choice['choice_id'] !== null &&
                $choice['choice_name'] !== 'None' &&
                isset($choice['layer_name']) &&
                $choice['layer_name'] !== ''
            ) {
                $name_parts[] = $choice['layer_name'] . ': ' . $choice['choice_name'];
            }
        }

        $name = implode($separator, $name_parts);

        if ($prefix) {
            $name = $prefix . $name;
        }

        // Truncate if too long (WordPress post title limit)
        if (strlen($name) > 200) {
            $name = substr($name, 0, 197) . '...';
        }

        return $name;
    }

    /**
     * Check if preset with given name already exists for this product
     * 
     * @param string $name
     * @return bool
     */
    private function preset_exists($name)
    {
        $existing = get_posts([
            'post_type' => 'mkl_pc_configuration',
            'post_status' => 'preset',
            'post_parent' => $this->product_id,
            'title' => $name,
            'posts_per_page' => 1,
        ]);

        return ! empty($existing);
    }

    /**
     * Make preset name unique by appending number
     * 
     * @param string $name
     * @return string
     */
    private function make_unique_name($name)
    {
        $counter = 1;
        $original_name = $name;

        while ($this->preset_exists($name)) {
            $counter++;
            $name = $original_name . ' (' . $counter . ')';
        }

        return $name;
    }

    /**
     * Delete all presets for this product
     * 
     * @return int Number of presets deleted
     */
    public function delete_all_presets()
    {
        $presets = get_posts([
            'post_type' => 'mkl_pc_configuration',
            'post_status' => 'preset',
            'post_parent' => $this->product_id,
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        $deleted = 0;
        foreach ($presets as $preset_id) {
            if (wp_delete_post($preset_id, true)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Get count of existing presets for this product
     * 
     * @return int
     */
    public function get_preset_count()
    {
        $presets = get_posts([
            'post_type' => 'mkl_pc_configuration',
            'post_status' => 'preset',
            'post_parent' => $this->product_id,
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        return count($presets);
    }

    /**
     * Check if a preset with this exact configuration already exists
     * 
     * @param array $combination The combination to check
     * @return bool
     */
    public function preset_configuration_exists($combination)
    {
        // Create a unique hash of the combination for comparison
        $config_hash = $this->hash_combination($combination);

        // Check if any preset has this hash
        $args = [
            'post_type' => 'mkl_pc_configuration',
            'post_status' => 'preset',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_product_id',
                    'value' => $this->product_id,
                ],
                [
                    'key' => '_config_hash',
                    'value' => $config_hash,
                ]
            ],
            'posts_per_page' => 1,
            'fields' => 'ids'
        ];

        $query = new \WP_Query($args);
        return $query->have_posts();
    }

    /**
     * Create a unique hash from a combination
     * 
     * @param array $combination
     * @return string
     */
    private function hash_combination($combination)
    {
        // Sort by layer_id to ensure consistent hashing
        $sorted = $combination;
        usort($sorted, function ($a, $b) {
            return $a['layer_id'] - $b['layer_id'];
        });

        // Create string of layer_id:choice_id pairs
        $parts = [];
        foreach ($sorted as $choice) {
            if ($choice['choice_id'] !== null) {
                $parts[] = $choice['layer_id'] . ':' . $choice['choice_id'];
            }
        }

        return md5(implode('|', $parts));
    }
}
