<?php

/**
 * Configuration Builder
 * 
 * Builds complete preset configurations including visual layers,
 * mimicking the frontend's save_data.parse_choices() behavior.
 * 
 * @package MKL_PC_Preset_Generator
 */

if (! defined('ABSPATH')) {
    exit;
}

class MKL_PC_Configuration_Builder
{
    private $product_id;
    private $db;
    private $validator;
    private $all_layers;
    private $all_content;

    public function __construct($product_id)
    {
        $this->product_id = $product_id;
        $this->db = \MKL\PC\Plugin::instance()->db;
        $this->validator = new MKL_PC_Preset_Conditional_Validator($product_id);
    }

    /**
     * Build a complete configuration from user-selected choices
     * This mimics the frontend's PC.fe.save_data.save() behavior
     * 
     * @param array $user_choices Array of user selections: [['layer_id' => 15, 'choice_id' => 1], ...]
     * @return array Complete configuration including visual layers
     */
    public function build_complete_configuration($user_choices)
    {
        // Load all layers and content for this product
        $this->load_product_data();

        // Create a map of user selections for quick lookup
        $user_selection_map = [];
        foreach ($user_choices as $choice) {
            $user_selection_map[$choice['layer_id']] = $choice['choice_id'];
        }

        // Build complete configuration by processing all layers
        $complete_config = [];

        foreach ($this->all_layers as $layer) {
            $layer_id = $layer['_id'];
            $layer_type = isset($layer['type']) ? $layer['type'] : 'simple';
            $layer_name = isset($layer['name']) ? $layer['name'] : '';
            $layer_order = isset($layer['order']) ? intval($layer['order']) : 0;
            $layer_image_order = isset($layer['image_order']) ? intval($layer['image_order']) : $layer_order;

            // Skip if layer is hidden by conditional logic
            // We'll check this based on the user's selections
            if (!$this->is_layer_visible($layer, $user_selection_map)) {
                continue;
            }

            // Get the content for this layer
            $layer_content = $this->get_layer_content($layer_id);
            if (!$layer_content || !isset($layer_content['choices'])) {
                continue;
            }

            $choices = $layer_content['choices'];

            // Handle different layer types
            if ($layer_type === 'group') {
                // Group layers are added without a specific choice
                $complete_config[] = [
                    'is_choice' => false,
                    'layer_id' => $layer_id,
                    'choice_id' => 0,
                    'angle_id' => 1,
                    'layer_name' => $layer_name,
                    'image' => 0,
                    'order' => $layer_order,
                    'image_order' => $layer_image_order,
                    'name' => '',
                ];
            } elseif (isset($layer['not_a_choice']) && $layer['not_a_choice']) {
                // Visual/display layers (not user-selectable)
                // Find the active choice based on conditional logic
                $active_choice = $this->find_active_visual_choice($layer, $choices, $user_selection_map);

                if ($active_choice) {
                    $image_id = $this->get_choice_image_id($active_choice);
                    $visual_order = isset($active_choice['order']) ? intval($active_choice['order']) : $layer_order;
                    $visual_image_order = isset($active_choice['image_order']) ? intval($active_choice['image_order']) : $layer_image_order;
                    $complete_config[] = [
                        'is_choice' => false,
                        'layer_id' => $layer_id,
                        'choice_id' => $active_choice['_id'],
                        'angle_id' => 1,
                        'layer_name' => $layer_name,
                        'image' => $image_id,
                        'order' => $visual_order,
                        'image_order' => $visual_image_order,
                        'name' => isset($active_choice['name']) ? $active_choice['name'] : '',
                    ];
                }
            } else {
                // User-selectable layers (Colour, Size, Worktop, etc.)
                if (isset($user_selection_map[$layer_id])) {
                    $choice_id = $user_selection_map[$layer_id];

                    // Find the choice details
                    $selected_choice = null;
                    foreach ($choices as $choice) {
                        if ($choice['_id'] == $choice_id) {
                            $selected_choice = $choice;
                            break;
                        }
                    }

                    if ($selected_choice) {
                        $choice_order = isset($selected_choice['order']) ? intval($selected_choice['order']) : $layer_order;
                        $choice_image_order = isset($selected_choice['image_order']) ? intval($selected_choice['image_order']) : $layer_image_order;
                        $complete_config[] = [
                            'is_choice' => true,
                            'layer_id' => $layer_id,
                            'choice_id' => $choice_id,
                            'angle_id' => 1,
                            'layer_name' => $layer_name,
                            'image' => '', // User layers have empty string for image
                            'order' => $choice_order,
                            'image_order' => $choice_image_order,
                            'name' => isset($selected_choice['name']) ? $selected_choice['name'] : '',
                        ];

                        // Add SKU if present
                        if (isset($selected_choice['sku']) && $selected_choice['sku']) {
                            end($complete_config);
                            $last_key = key($complete_config);
                            $complete_config[$last_key]['sku'] = $selected_choice['sku'];
                        }
                    }
                }
            }
        }

        return $this->normalize_configuration_order($complete_config);
    }

    /**
     * Load all layers and content for the product
     */
    private function load_product_data()
    {
        $this->all_layers = $this->db->get('layers', $this->product_id);

        // Get content ID (for simple products, it's the product ID itself)
        $content_id = $this->db->get_product_id_for_content($this->product_id, 0);
        $content = $this->db->get('content', $content_id);

        // Create a map of layer content for easy lookup
        $this->all_content = [];
        if ($content && is_array($content)) {
            foreach ($content as $layer_content) {
                if (isset($layer_content['layerId'])) {
                    $this->all_content[$layer_content['layerId']] = $layer_content;
                }
            }
        }
    }

    /**
     * Get content for a specific layer
     */
    private function get_layer_content($layer_id)
    {
        return isset($this->all_content[$layer_id]) ? $this->all_content[$layer_id] : null;
    }

    /**
     * Check if a layer should be visible based on conditional logic
     */
    private function is_layer_visible($layer, $user_selection_map)
    {
        // For now, include all layers - conditional logic will be applied by validator
        // In a full implementation, we'd check 'cshow' property based on conditions
        return true;
    }

    /**
     * Find the active choice for a visual layer based on user selections
     * Visual layers are controlled by conditional logic
     */
    private function find_active_visual_choice($layer, $choices, $user_selection_map)
    {
        // For visual layers, conditional logic determines which choice is active
        // The choice name often matches the user's selection (e.g., "Red" colour -> "Red" frame)

        // First, try to find a choice that matches a user selection
        // This is a heuristic - the actual logic is in conditional rules
        foreach ($choices as $choice) {
            $choice_name = isset($choice['name']) ? $choice['name'] : '';

            // Check if this choice matches any user selection
            foreach ($user_selection_map as $user_layer_id => $user_choice_id) {
                $user_layer_content = $this->get_layer_content($user_layer_id);
                if (!$user_layer_content || !isset($user_layer_content['choices'])) {
                    continue;
                }

                foreach ($user_layer_content['choices'] as $user_choice) {
                    if ($user_choice['_id'] == $user_choice_id) {
                        $user_choice_name = isset($user_choice['name']) ? $user_choice['name'] : '';
                        // Match by name (e.g., "Red" colour matches "Red" frame)
                        if ($choice_name === $user_choice_name) {
                            return $choice;
                        }
                    }
                }
            }
        }

        // If no match found, return the first choice
        return isset($choices[0]) ? $choices[0] : null;
    }

    /**
     * Get the image attachment ID for a choice
     */
    private function get_choice_image_id($choice)
    {
        // Look for image in the choice's images array
        if (isset($choice['images']) && is_array($choice['images'])) {
            foreach ($choice['images'] as $image_data) {
                // Get the image for angle_id = 1
                if (isset($image_data['angleId']) && $image_data['angleId'] == 1) {
                    if (isset($image_data['image']['id'])) {
                        return intval($image_data['image']['id']);
                    }
                }
            }

            // If no specific angle, try first image
            if (isset($choice['images'][0]['image']['id'])) {
                return intval($choice['images'][0]['image']['id']);
            }
        }

        return 0;
    }

    /**
     * Ensure configuration items follow image_order/order sequence so rendering matches admin layer stack
     *
     * @param array $configuration
     * @return array
     */
    private function normalize_configuration_order($configuration)
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
     * Resolve comparable order value for config layer
     *
     * @param array|object $layer
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

        // Fall back to high number to keep unknown items at the end while preserving tie order
        return 100000;
    }

    /**
     * Safely access property on array or object
     *
     * @param array|object $layer
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

