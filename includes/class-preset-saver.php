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

        $skip_thumbnail = !empty($options['skip_thumbnail']);
        $author_id = $this->resolve_author_id($options);
        $previous_user_id = get_current_user_id();
        $switched_user = false;

        // Ensure thumbnails are supported for this post type in CLI/admin contexts.
        if (!post_type_supports('mkl_pc_configuration', 'thumbnail')) {
            add_post_type_support('mkl_pc_configuration', 'thumbnail');
        }
        if (!current_theme_supports('post-thumbnails')) {
            add_theme_support('post-thumbnails');
        }

        if ($author_id && $previous_user_id !== $author_id) {
            $author_user = get_user_by('id', $author_id);
            if ($author_user) {
                wp_set_current_user($author_id);
                $switched_user = true;
                error_log(sprintf('Switched current user to %d for preset operations', $author_id));
            }
        }

        error_log("Attempting to save preset: $preset_name with " . count($configuration_data) . " layers");

        // Check for duplicate configurations FIRST (before name check)
        if ($this->preset_configuration_exists($combination)) {
            error_log("Skipping - preset configuration already exists");
            if ($switched_user) {
                wp_set_current_user($previous_user_id);
                error_log('Restored previous user after duplicate configuration detected');
            }
            return new WP_Error('duplicate_config', __('Preset with this exact configuration already exists', 'mkl-pc-preset-generator'));
        }

        error_log("Configuration data: " . json_encode($configuration_data));

        // Create preset configuration object
        try {
            $preset = new Mkl_PC_Preset_Configuration(0);
            // Enable async image generation for thumbnails
            $preset->save_image_async = ! $skip_thumbnail;
            $preset->should_save_image = ! $skip_thumbnail;
            error_log(sprintf(
                'Created preset object, image saving %s',
                $skip_thumbnail ? 'disabled' : 'enabled'
            ));
        } catch (Exception $e) {
            error_log("Error creating preset object: " . $e->getMessage());
            if ($switched_user) {
                wp_set_current_user($previous_user_id);
                error_log('Restored previous user after preset object creation failure');
            }
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
                'customer_id' => $author_id ? $author_id : get_current_user_id(),
                'title' => $preset_name,
                'configuration_id' => 0,
            ]);
        } catch (Exception $e) {
            error_log("Exception during save: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            if ($switched_user) {
                wp_set_current_user($previous_user_id);
                error_log('Restored previous user after save exception');
            }
            return new WP_Error('save_exception', $e->getMessage());
        }

        error_log("Save returned: " . print_r($saved, true));

        if (! isset($saved['saved']) || ! $saved['saved']) {
            $error_msg = isset($saved['error']) ? $saved['error'] : 'Unknown error';
            error_log("Save failed: $error_msg");
            if ($switched_user) {
                wp_set_current_user($previous_user_id);
                error_log('Restored previous user after save failure');
            }
            return new WP_Error('save_failed', __('Failed to save preset: ', 'mkl-pc-preset-generator') . $error_msg);
        }

        if (is_array($preset->content)) {
            $non_zero_total = 0;
            foreach ($preset->content as $layer) {
                if (is_object($layer) && !empty($layer->image)) {
                    $non_zero_total++;
                } elseif (is_array($layer) && !empty($layer['image'])) {
                    $non_zero_total++;
                }
            }

            $sample_layers = array_slice($preset->content, 0, 5);
            $sample_debug = [];
            foreach ($sample_layers as $layer) {
                if (is_object($layer)) {
                    $sample_debug[] = [
                        'layer_id' => isset($layer->layer_id) ? $layer->layer_id : null,
                        'choice_id' => isset($layer->choice_id) ? $layer->choice_id : null,
                        'image' => isset($layer->image) ? $layer->image : null,
                    ];
                } elseif (is_array($layer)) {
                    $sample_debug[] = [
                        'layer_id' => isset($layer['layer_id']) ? $layer['layer_id'] : null,
                        'choice_id' => isset($layer['choice_id']) ? $layer['choice_id'] : null,
                        'image' => isset($layer['image']) ? $layer['image'] : null,
                    ];
                }
            }
            error_log('Preset content sample: ' . wp_json_encode($sample_debug));
            error_log('Preset content non-zero image count: ' . $non_zero_total);
        } else {
            error_log('Preset content not array; type=' . gettype($preset->content));
        }

        $preset_id = isset($saved['config_id']) ? intval($saved['config_id']) : (isset($saved['ID']) ? intval($saved['ID']) : 0);

        if (! $preset_id) {
            if ($switched_user) {
                wp_set_current_user($previous_user_id);
                error_log('Restored previous user after missing preset ID');
            }
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
        if (!$skip_thumbnail) {
            $layer_image_debug = [];
            $debug_filter = function ($path, $layer) use (&$layer_image_debug) {
                $layer_image_debug[] = [
                    'layer_id' => is_object($layer) && isset($layer->layer_id) ? $layer->layer_id : (is_array($layer) && isset($layer['layer_id']) ? $layer['layer_id'] : null),
                    'image' => is_object($layer) && isset($layer->image) ? $layer->image : (is_array($layer) && isset($layer['image']) ? $layer['image'] : null),
                    'path' => $path,
                ];
                return $path;
            };
            $non_empty_layers = [];
            add_filter('mkl-pc-serve-image-process-layer-image', $debug_filter, 10, 2);

            if (isset($saved['save_image_async']) && $saved['save_image_async']) {
                error_log("Triggering image generation for preset #$preset_id");
                try {
                    $image_id = $preset->save_image($preset->content, $preset_id);
                    if ($image_id && !is_wp_error($image_id)) {
                        error_log("Successfully generated image #$image_id for preset #$preset_id");
                        $set = set_post_thumbnail($preset_id, $image_id);
                        error_log("set_post_thumbnail({$preset_id}, {$image_id}) => " . var_export($set, true));
                        if (!$set) {
                            $updated = update_post_meta($preset_id, '_thumbnail_id', $image_id);
                            error_log("Fallback update_post_meta('_thumbnail_id') => " . var_export($updated, true));
                        }
                    } else {
                        error_log("Image generation returned: " . print_r($image_id, true));
                    }
                } catch (Exception $e) {
                    error_log("Image generation failed: " . $e->getMessage());
                    // Don't fail the whole preset save if image generation fails
                }
            } else {
                // Safety net: ensure we still attempt to create the thumbnail immediately
                try {
                    error_log("Triggering synchronous image generation for preset #$preset_id");
                    $image_id = $preset->save_image($preset->content, $preset_id);
                    if ($image_id && !is_wp_error($image_id)) {
                        error_log("Successfully generated image #$image_id for preset #$preset_id (sync fallback)");
                        // Ensure attachment is correctly registered (path + metadata)
                        $this->ensure_attachment_registered($preset, $image_id);
                        $set = set_post_thumbnail($preset_id, $image_id);
                        error_log("set_post_thumbnail({$preset_id}, {$image_id}) => " . var_export($set, true));
                        if (!$set) {
                            $updated = update_post_meta($preset_id, '_thumbnail_id', $image_id);
                            error_log("Fallback update_post_meta('_thumbnail_id') => " . var_export($updated, true));
                        }
                    } else {
                        error_log("Sync image generation returned: " . print_r($image_id, true));
                    }
                } catch (Exception $e) {
                    error_log("Sync image generation failed: " . $e->getMessage());
                }
            }

            remove_filter('mkl-pc-serve-image-process-layer-image', $debug_filter, 10);
            $non_empty = array_values(array_filter($layer_image_debug, function ($entry) {
                return !empty($entry['image']);
            }));
            error_log('Layer image debug (first 8): ' . wp_json_encode(array_slice($layer_image_debug, 0, 8)) . ' ... total=' . count($layer_image_debug));
            error_log('Layer image debug (non-empty first 8): ' . wp_json_encode(array_slice($non_empty, 0, 8)) . ' ... non-empty total=' . count($non_empty));
        }

        if ($switched_user) {
            clean_post_cache($preset_id);
            wp_set_current_user($previous_user_id);
            error_log('Restored previous user after preset operations');
        }

        $thumb_meta = get_post_meta($preset_id, '_thumbnail_id', true);
        error_log("Preset #{$preset_id} final _thumbnail_id meta value => " . var_export($thumb_meta, true));

        return $preset_id;
    }

    /**
     * Ensure the generated attachment points to a valid file and has metadata.
     * Some environments return a status string upstream which results in
     * attachments with an invalid '_wp_attached_file'. Fix it here without
     * touching upstream plugins.
     */
    private function ensure_attachment_registered($preset, $image_id)
    {
        // First, ensure the attachment has a valid mime type and, if needed, generate subsizes
        $abs_path = get_attached_file($image_id);
        if ($abs_path && file_exists($abs_path)) {
            $current_mime = get_post_mime_type($image_id);
            if (empty($current_mime)) {
                $ft = wp_check_filetype(basename($abs_path), null);
                $mime = !empty($ft['type']) ? $ft['type'] : 'image/png';
                wp_update_post([
                    'ID' => $image_id,
                    'post_mime_type' => $mime,
                ]);
            }

            if (! function_exists('wp_generate_attachment_metadata') && file_exists(ABSPATH . 'wp-admin/includes/image.php')) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
            }

            $meta = wp_get_attachment_metadata($image_id);
            if (!is_array($meta) || empty($meta['sizes'])) {
                // Regenerate metadata and subsizes
                $generated = wp_generate_attachment_metadata($image_id, $abs_path);
                if (is_array($generated)) {
                    wp_update_attachment_metadata($image_id, $generated);
                }
                if (function_exists('wp_update_image_subsizes')) {
                    wp_update_image_subsizes($image_id);
                }
            }
        }

        $attached = get_post_meta($image_id, '_wp_attached_file', true);
        if (! $attached || $attached === 'file already exists') {
            $uploads = wp_upload_dir();
            $file_name = method_exists($preset, 'get_configuration_image_name')
                ? $preset->get_configuration_image_name()
                : '';
            if (! $file_name) {
                return;
            }

            // Build absolute + relative paths
            $abs = trailingslashit($preset->upload_dir_path) . $file_name;
            $rel = ltrim(str_replace(trailingslashit($uploads['basedir']), '', $abs), '/');

            if (file_exists($abs)) {
                // Ensure mime type is set so core treats the attachment as an image
                $current_mime = get_post_mime_type($image_id);
                if (empty($current_mime)) {
                    $ft = wp_check_filetype(basename($abs), null);
                    $mime = !empty($ft['type']) ? $ft['type'] : 'image/png';
                    wp_update_post([
                        'ID' => $image_id,
                        'post_mime_type' => $mime,
                    ]);
                }

                update_post_meta($image_id, '_wp_attached_file', $rel);

                if (! function_exists('wp_generate_attachment_metadata') && file_exists(ABSPATH . 'wp-admin/includes/image.php')) {
                    require_once ABSPATH . 'wp-admin/includes/image.php';
                }

                $meta = wp_generate_attachment_metadata($image_id, $abs);
                // If core couldn't build metadata (no width/height), compute minimal metadata
                if (!is_array($meta) || empty($meta['width']) || empty($meta['height'])) {
                    $size = @getimagesize($abs);
                    if (is_array($size) && !empty($size[0]) && !empty($size[1])) {
                        $meta = array(
                            'width'  => intval($size[0]),
                            'height' => intval($size[1]),
                            'file'   => $rel,
                            'sizes'  => array(),
                        );
                        error_log("Computed minimal metadata for attachment #{$image_id}: {$meta['width']}x{$meta['height']}");
                    }
                }

                if (is_array($meta)) {
                    wp_update_attachment_metadata($image_id, $meta);
                    // Generate missing sub-sizes (thumbnail/medium/etc.) if supported
                    if (function_exists('wp_update_image_subsizes')) {
                        $subs = wp_update_image_subsizes($image_id);
                        if (is_array($subs)) {
                            error_log("Generated subsizes for attachment #{$image_id}: " . implode(',', array_keys($subs)));
                        }
                    }
                    error_log("Attachment #{$image_id} registered with file {$rel}");
                } else {
                    error_log("Failed to generate metadata for attachment #{$image_id} path {$abs}");
                }
            } else {
                error_log("Attachment file missing at {$abs} for #{$image_id}");
            }
        }
    }

    /**
     * Determine which user should own the preset when saving.
     *
     * @param array $options
     * @return int|null
     */
    private function resolve_author_id($options)
    {
        if (!empty($options['author_id'])) {
            return intval($options['author_id']);
        }

        $current = get_current_user_id();
        if ($current) {
            return $current;
        }

        $fallback = apply_filters(
            'mkl_pc_preset_generator_default_author',
            get_option('mkl_pc_preset_generator_default_author', 1),
            $this->product_id
        );

        if ($fallback) {
            $fallback_user = get_user_by('id', $fallback);
            if ($fallback_user) {
                return intval($fallback_user->ID);
            }
        }

        return null;
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
