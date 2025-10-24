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
    private $all_layers = [];
    private $all_content = [];
    private $layer_map = [];
    private $choice_map = [];
    private $conditions = [];
    private $layer_hidden_map = [];

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

        $selections_by_layer = [];
        $selection_names = [];
        $choices_by_layer = [];

        foreach ($user_choices as $choice) {
            $layer_id = isset($choice['layer_id']) ? (int) $choice['layer_id'] : 0;
            $choice_id = isset($choice['choice_id']) ? $choice['choice_id'] : null;
            $choice_name = null;

            if (! $layer_id) {
                continue;
            }

            if (! isset($choices_by_layer[$layer_id])) {
                $choices_by_layer[$layer_id] = [];
            }

            if (isset($choice['choice_name']) && '' !== $choice['choice_name']) {
                $choice_name = $choice['choice_name'];
            } elseif (isset($choice['name']) && '' !== $choice['name']) {
                $choice_name = $choice['name'];
            }

            $resolved_choice = $this->resolve_choice_record_for_selection($layer_id, $choice_id, $choice_name);
            $resolved_choice_id = $resolved_choice ? $this->normalize_choice_id($resolved_choice) : null;

            if (! isset($selections_by_layer[$layer_id])) {
                $selections_by_layer[$layer_id] = [];
                $selection_names[$layer_id] = [];
            }

            if ($resolved_choice_id !== null) {
                $selections_by_layer[$layer_id][] = $resolved_choice_id;
                $selection_names[$layer_id][$resolved_choice_id] = isset($resolved_choice['name'])
                    ? $resolved_choice['name']
                    : ($choice_name !== null ? $choice_name : null);
            }

            $choice['resolved_choice_id'] = $resolved_choice_id;
            $choice['resolved_choice'] = $resolved_choice;

            $choices_by_layer[$layer_id][] = $choice;
        }

        $state = $this->compute_visibility_state($selections_by_layer);

        // Build complete configuration by processing all layers
        $complete_config = [];

        foreach ($this->all_layers as $layer) {
            $layer_id = isset($layer['_id']) ? (int) $layer['_id'] : 0;
            if (! $layer_id) {
                continue;
            }

            $layer_state = isset($state['layers'][$layer_id])
                ? $state['layers'][$layer_id]
                : ['visible' => $this->default_layer_visible($layer)];

            if (empty($layer_state['visible'])) {
                continue;
            }

            $layer_type = isset($layer['type']) ? $layer['type'] : 'simple';
            $layer_name = isset($layer['name']) ? $layer['name'] : '';
            $layer_order = isset($layer['order']) ? intval($layer['order']) : 0;
            $layer_image_order = isset($layer['image_order']) ? intval($layer['image_order']) : $layer_order;

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
                continue;
            }

            if (! empty($layer['not_a_choice'])) {
                // Visual/display layers (not user-selectable)
                $active_choice = $this->resolve_visual_choice(
                    $layer_id,
                    $layer,
                    $layer_state,
                    $state['choices'],
                    $selections_by_layer,
                    $selection_names
                );

                if ($active_choice) {
                    $choice_id = $this->normalize_choice_id($active_choice);
                    $image_id = $choice_id !== null ? $this->get_choice_image_id($active_choice) : 0;
                    $visual_order = isset($active_choice['order']) ? intval($active_choice['order']) : $layer_order;
                    $visual_image_order = isset($active_choice['image_order']) ? intval($active_choice['image_order']) : $layer_image_order;
                    $complete_config[] = [
                        'is_choice' => false,
                        'layer_id' => $layer_id,
                        'choice_id' => $choice_id,
                        'angle_id' => 1,
                        'layer_name' => $layer_name,
                        'image' => $image_id,
                        'order' => $visual_order,
                        'image_order' => $visual_image_order,
                        'name' => isset($active_choice['name']) ? $active_choice['name'] : '',
                    ];
                }
                continue;
            }

            // User-selectable layers (Colour, Size, Worktop, etc.)
            if (empty($choices_by_layer[$layer_id])) {
                if (! $this->should_auto_select_layer($layer_id, $layer_state)) {
                    continue;
                }

                $auto_choice = $this->resolve_visual_choice(
                    $layer_id,
                    $layer,
                    $layer_state,
                    $state['choices'],
                    $selections_by_layer,
                    $selection_names
                );

                if (! $auto_choice) {
                    continue;
                }

                $auto_choice_id = $this->normalize_choice_id($auto_choice);
                $auto_image_id = $auto_choice_id !== null ? $this->get_choice_image_id($auto_choice) : 0;
                $visual_order = isset($auto_choice['order']) ? intval($auto_choice['order']) : $layer_order;
                $visual_image_order = isset($auto_choice['image_order']) ? intval($auto_choice['image_order']) : $layer_image_order;

                $complete_config[] = [
                    'is_choice' => false,
                    'layer_id' => $layer_id,
                    'choice_id' => $auto_choice_id,
                    'angle_id' => 1,
                    'layer_name' => $layer_name,
                    'image' => $auto_image_id,
                    'order' => $visual_order,
                    'image_order' => $visual_image_order,
                    'name' => isset($auto_choice['name']) ? $auto_choice['name'] : '',
                ];

                continue;
            }

            foreach ($choices_by_layer[$layer_id] as $selected_choice) {
                $choice_id = isset($selected_choice['resolved_choice_id'])
                    ? $selected_choice['resolved_choice_id']
                    : (isset($selected_choice['choice_id']) ? $selected_choice['choice_id'] : null);

                $choice_name = isset($selected_choice['choice_name']) && '' !== $selected_choice['choice_name']
                    ? $selected_choice['choice_name']
                    : (isset($selected_choice['name']) ? $selected_choice['name'] : null);

                $choice_data = isset($selected_choice['resolved_choice']) && is_array($selected_choice['resolved_choice'])
                    ? $selected_choice['resolved_choice']
                    : null;

                $choice_data = $this->resolve_choice_record_for_selection(
                    $layer_id,
                    $choice_id,
                    $choice_name,
                    $layer_state,
                    $state['choices']
                );

                if (! $choice_data) {
                    continue;
                }

                $choice_id = $this->normalize_choice_id($choice_data);
                if ($choice_id === null) {
                    continue;
                }

                if (! $this->is_choice_visible($state['choices'], $layer_id, $choice_id)) {
                    continue;
                }

                if (! isset($selection_names[$layer_id])) {
                    $selection_names[$layer_id] = [];
                }
                $final_choice_name = isset($choice_data['name']) ? $choice_data['name'] : ($choice_name !== null ? $choice_name : '');
                $selection_names[$layer_id][$choice_id] = $final_choice_name;

                if (! isset($selections_by_layer[$layer_id]) || ! in_array($choice_id, $selections_by_layer[$layer_id], true)) {
                    $selections_by_layer[$layer_id][] = $choice_id;
                }

                $choice_order = isset($choice_data['order']) ? intval($choice_data['order']) : $layer_order;
                $choice_image_order = isset($choice_data['image_order']) ? intval($choice_data['image_order']) : $layer_image_order;

                $entry = [
                    'is_choice' => true,
                    'layer_id' => $layer_id,
                    'choice_id' => $choice_id,
                    'angle_id' => 1,
                    'layer_name' => $layer_name,
                    'image' => '', // Configurator stores empty string for user layers
                    'order' => $choice_order,
                    'image_order' => $choice_image_order,
                    'name' => $final_choice_name,
                ];

                if (! empty($choice_data['sku'])) {
                    $entry['sku'] = $choice_data['sku'];
                } elseif (! empty($selected_choice['sku'])) {
                    $entry['sku'] = $selected_choice['sku'];
                }

                $complete_config[] = $entry;
            }
        }

        return $this->normalize_configuration_order($complete_config);
    }

    /**
     * Load all layers and content for the product
     */
    private function load_product_data()
    {
        $layers = $this->db->get('layers', $this->product_id);
        $this->all_layers = is_array($layers) ? $layers : [];
        $this->layer_map = [];

        foreach ($this->all_layers as $layer) {
            if (isset($layer['_id'])) {
                $this->layer_map[(int) $layer['_id']] = $layer;
            }
        }

        // Get content ID (for simple products, it's the product ID itself)
        $content_id = $this->db->get_product_id_for_content($this->product_id, 0);
        $content_rows = $this->db->get('content', $content_id);

        // Create a map of layer content for easy lookup
        $this->all_content = [];
        $this->choice_map = [];

        if (is_array($content_rows)) {
            foreach ($content_rows as $layer_content) {
                if (! isset($layer_content['layerId'])) {
                    continue;
                }

                $layer_id = (int) $layer_content['layerId'];
                $this->all_content[$layer_id] = $layer_content;

                if (! empty($layer_content['choices']) && is_array($layer_content['choices'])) {
                    foreach ($layer_content['choices'] as $choice) {
                        $choice_id = $this->normalize_choice_id($choice);
                        if ($choice_id === null) {
                            continue;
                        }

                        if (! isset($this->choice_map[$layer_id])) {
                            $this->choice_map[$layer_id] = [];
                        }

                        $this->choice_map[$layer_id][$choice_id] = $choice;
                    }
                }
            }
        }

        $conditions = $this->db->get('conditions', $this->product_id);
        $this->conditions = is_array($conditions) ? $conditions : [];
        $this->layer_hidden_map = [];
        foreach ($this->layer_map as $layer_id => $layer_data) {
            $this->layer_hidden_map[$layer_id] = $this->compute_layer_hidden_flag($layer_id);
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
     * Determine default layer visibility.
     */
    private function default_layer_visible($layer)
    {
        if (is_array($layer) && array_key_exists('cshow', $layer)) {
            return $layer['cshow'] !== false;
        }

        return true;
    }

    /**
     * Determine default choice visibility.
     */
    private function default_choice_visible($choice)
    {
        if (is_array($choice) && array_key_exists('cshow', $choice)) {
            return $choice['cshow'] !== false;
        }

        return true;
    }

    /**
     * Build visibility state for layers/choices using conditional logic.
     *
     * @param array $selection_map layer_id => [choice_id, ...]
     * @return array
     */
    private function compute_visibility_state(array $selection_map)
    {
        $layer_state = [];
        foreach ($this->layer_map as $layer_id => $layer_data) {
            $layer_state[$layer_id] = [
                'visible' => $this->default_layer_visible($layer_data),
                'sync_source' => null,
            ];
        }

        $choice_state = [];
        foreach ($this->choice_map as $layer_id => $choices) {
            foreach ($choices as $choice_id => $choice) {
                if (! isset($choice_state[$layer_id])) {
                    $choice_state[$layer_id] = [];
                }

                $choice_state[$layer_id][$choice_id] = [
                    'visible' => $this->default_choice_visible($choice),
                ];
            }
        }

        foreach ($this->conditions as $condition) {
            if (empty($condition['enabled'])) {
                continue;
            }

            $status = $this->evaluate_condition_status($condition, $selection_map);
            if ('met' !== $status) {
                continue;
            }

            if (empty($condition['actions']) || ! is_array($condition['actions'])) {
                continue;
            }

            $source_layer = $this->resolve_condition_source_layer($condition);

            foreach ($condition['actions'] as $action) {
                $type = isset($action['type']) ? $action['type'] : '';
                $action_name = isset($action['action']) ? $action['action'] : '';
                $layer_id = isset($action['layerId']) ? (int) $action['layerId'] : 0;
                $choice_id = isset($action['choiceId']) ? $action['choiceId'] : null;

                if ('layer' === $type && $layer_id) {
                    if ('show' === $action_name) {
                        $layer_state[$layer_id]['visible'] = true;
                    } elseif ('hide' === $action_name) {
                        $layer_state[$layer_id]['visible'] = false;
                    } elseif ('sync' === $action_name && $source_layer && $source_layer !== $layer_id) {
                        $layer_state[$layer_id]['sync_source'] = $source_layer;
                    } elseif ('reset' === $action_name) {
                        unset($layer_state[$layer_id]['forced_choice']);
                    }
                } elseif ('choice' === $type && $layer_id && $choice_id !== null) {
                    if (! isset($choice_state[$layer_id])) {
                        $choice_state[$layer_id] = [];
                    }
                    if (! isset($choice_state[$layer_id][$choice_id])) {
                        $choice_state[$layer_id][$choice_id] = [
                            'visible' => $this->default_choice_visible(null),
                        ];
                    }

                    if ('show' === $action_name) {
                        $choice_state[$layer_id][$choice_id]['visible'] = true;
                    } elseif ('hide' === $action_name) {
                        $choice_state[$layer_id][$choice_id]['visible'] = false;
                    } elseif ('select' === $action_name) {
                        $layer_state[$layer_id]['forced_choice'] = $choice_id;
                    } elseif ('reset' === $action_name) {
                        unset($layer_state[$layer_id]['forced_choice']);
                    }
                }
            }
        }

        return [
            'layers' => $layer_state,
            'choices' => $choice_state,
        ];
    }

    /**
     * Determine whether a choice is considered visible.
     */
    private function is_choice_visible(array $choice_state, $layer_id, $choice_id)
    {
        if (isset($choice_state[$layer_id]) && array_key_exists($choice_id, $choice_state[$layer_id])) {
            return ! empty($choice_state[$layer_id][$choice_id]['visible']);
        }

        return true;
    }

    /**
     * Locate choice information for a given layer.
     */
    private function get_choice_from_layer($layer_id, $choice_id)
    {
        if (isset($this->choice_map[$layer_id][ $choice_id ])) {
            return $this->choice_map[$layer_id][ $choice_id ];
        }

        return null;
    }

    /**
     * Resolve the choice record that best matches a requested selection.
     *
     * @param int        $layer_id
     * @param int|string $choice_id
     * @param string|null $choice_name
     * @param array|null $layer_state
     * @param array      $choice_state
     *
     * @return array|null
     */
    private function resolve_choice_record_for_selection($layer_id, $choice_id, $choice_name, $layer_state = null, array $choice_state = [])
    {
        $choices = isset($this->choice_map[$layer_id]) ? $this->choice_map[$layer_id] : [];
        if (empty($choices)) {
            return null;
        }

        // Direct match by choice ID first.
        if ($choice_id !== null && isset($choices[$choice_id])) {
            if ($layer_state === null || $this->is_choice_visible($choice_state, $layer_id, $choice_id)) {
                return $choices[$choice_id];
            }
        }

        // Attempt to match by normalised label.
        $normalized_target = $this->normalize_choice_label($choice_name);
        if ($normalized_target !== null) {
            foreach ($choices as $candidate) {
                $candidate_id = $this->normalize_choice_id($candidate);
                if ($candidate_id === null) {
                    continue;
                }

                $candidate_label = isset($candidate['name'])
                    ? $this->normalize_choice_label($candidate['name'])
                    : null;

                if ($candidate_label !== null && $candidate_label === $normalized_target) {
                    if ($layer_state === null || $this->is_choice_visible($choice_state, $layer_id, $candidate_id)) {
                        return $candidate;
                    }
                }
            }
        }

        // Honour forced selection if present in the layer state.
        if (is_array($layer_state) && isset($layer_state['forced_choice'])) {
            $forced_id = $layer_state['forced_choice'];
            if ($forced_id !== null && isset($choices[$forced_id])) {
                if ($this->is_choice_visible($choice_state, $layer_id, $forced_id)) {
                    return $choices[$forced_id];
                }
            }
        }

        // Fallback to the first visible choice ordered by the configurator order.
        $ordered_choices = array_values($choices);
        usort($ordered_choices, function ($a, $b) {
            $a_order = isset($a['order']) ? intval($a['order']) : 0;
            $b_order = isset($b['order']) ? intval($b['order']) : 0;
            return $a_order <=> $b_order;
        });

        foreach ($ordered_choices as $candidate) {
            $candidate_id = $this->normalize_choice_id($candidate);
            if ($candidate_id === null) {
                continue;
            }

            if ($layer_state !== null && ! $this->is_choice_visible($choice_state, $layer_id, $candidate_id)) {
                continue;
            }

            return $candidate;
        }

        return $ordered_choices ? $ordered_choices[0] : null;
    }

    /**
     * Normalise a choice label for comparison.
     *
     * @param string|null $label
     * @return string|null
     */
    private function normalize_choice_label($label)
    {
        if ($label === null) {
            return null;
        }

        $label = trim($label);
        if ($label === '') {
            return null;
        }

        $normalized = strtolower(preg_replace('/\s+/', ' ', $label));

        $none_synonyms = [
            'none',
            'no',
            'not applicable',
            'n/a',
            'not required',
            'none selected',
            'without',
            'none (default)',
        ];

        if (in_array($normalized, $none_synonyms, true)) {
            return 'none';
        }

        return $normalized;
    }

    /**
     * Determine if a layer should receive an automatic choice selection.
     */
    private function should_auto_select_layer($layer_id, array $layer_state)
    {
        if (empty($layer_state['visible'])) {
            return false;
        }

        if (! empty($layer_state['forced_choice']) || ! empty($layer_state['sync_source'])) {
            return true;
        }

        if (! empty($this->layer_hidden_map[$layer_id])) {
            return true;
        }

        $parent_id = $this->resolve_layer_parent($layer_id);
        if ($parent_id && ! empty($this->choice_map[$parent_id])) {
            return true;
        }

        return false;
    }

    /**
     * Resolve the active choice for a visual layer.
     */
    private function resolve_visual_choice(
        $layer_id,
        $layer,
        array $layer_state,
        array $choice_state,
        array $selection_map,
        array $selection_names
    ) {
        $choices = $this->get_layer_choices($layer_id);
        if (empty($choices)) {
            return null;
        }

        // Forced choice from actions takes priority.
        if (isset($layer_state['forced_choice'])) {
            $forced = $layer_state['forced_choice'];
            if ($forced !== null && $this->is_choice_visible($choice_state, $layer_id, $forced)) {
                $match = $this->get_choice_from_layer($layer_id, $forced);
                if ($match) {
                    return $match;
                }
            }
        }

        // Sync with another layer if requested.
        if (! empty($layer_state['sync_source'])) {
            $source_layer_id = $layer_state['sync_source'];
            if (isset($selection_map[$source_layer_id])) {
                foreach ($selection_map[$source_layer_id] as $source_choice_id) {
                    if ($source_choice_id === null) {
                        continue;
                    }

                    $source_name = isset($selection_names[$source_layer_id][$source_choice_id])
                        ? $selection_names[$source_layer_id][$source_choice_id]
                        : null;

                    $candidate = $this->match_choice($layer_id, $source_choice_id, $source_name, $choice_state);
                    if ($candidate) {
                        return $candidate;
                    }
                }
            }
        }

        // Fallback to first visible choice.
        foreach ($choices as $choice) {
            $choice_id = $this->normalize_choice_id($choice);
            if ($choice_id === null) {
                continue;
            }
            if ($this->is_choice_visible($choice_state, $layer_id, $choice_id)) {
                return $choice;
            }
        }

        return $choices[0];
    }

    /**
     * Attempt to match a visual choice by ID or name.
     */
    private function match_choice($layer_id, $source_choice_id, $source_choice_name, array $choice_state)
    {
        if (isset($this->choice_map[$layer_id][$source_choice_id])) {
            if ($this->is_choice_visible($choice_state, $layer_id, $source_choice_id)) {
                return $this->choice_map[$layer_id][$source_choice_id];
            }
        }

        if ($source_choice_name) {
            foreach ($this->choice_map[$layer_id] ?? [] as $candidate) {
                if (! isset($candidate['name'])) {
                    continue;
                }
                if (strcasecmp($candidate['name'], $source_choice_name) === 0) {
                    $candidate_id = $this->normalize_choice_id($candidate);
                    if ($candidate_id !== null && $this->is_choice_visible($choice_state, $layer_id, $candidate_id)) {
                        return $candidate;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Evaluate a condition status based on current selection map.
     */
    private function evaluate_condition_status($condition, array $selection_map)
    {
        $relationship = (isset($condition['relationship']) && 'OR' === $condition['relationship']) ? 'OR' : 'AND';
        $rules = isset($condition['rules']) ? $condition['rules'] : [];

        if (empty($rules)) {
            return 'met';
        }

        $has_unknown = false;

        foreach ($rules as $rule) {
            $status = $this->evaluate_rule_status($rule, $selection_map);

            if ('OR' === $relationship) {
                if ('true' === $status) {
                    return 'met';
                }

                if ('unknown' === $status) {
                    $has_unknown = true;
                }
            } else {
                if ('false' === $status) {
                    return 'unmet';
                }

                if ('unknown' === $status) {
                    $has_unknown = true;
                }
            }
        }

        if ('OR' === $relationship) {
            return $has_unknown ? 'unknown' : 'unmet';
        }

        return $has_unknown ? 'unknown' : 'met';
    }

    /**
     * Evaluate a single rule.
     */
    private function evaluate_rule_status($rule, array $selection_map)
    {
        $layer_id = isset($rule['actioner']['layerId']) ? (int) $rule['actioner']['layerId'] : null;
        $choice_id = isset($rule['actioner']['choiceId']) ? $rule['actioner']['choiceId'] : null;
        $action = isset($rule['action']) ? $rule['action'] : '';

        if (! $layer_id || $choice_id === null) {
            return 'unknown';
        }

        $layer_has_selection = isset($selection_map[$layer_id]) && ! empty($selection_map[$layer_id]);

        if (-1 === $choice_id) {
            if ('selected' === $action) {
                return $layer_has_selection ? 'true' : 'unknown';
            }

            if ('not_selected' === $action) {
                return $layer_has_selection ? 'false' : 'unknown';
            }
        }

        if (-2 === $choice_id) {
            if ('selected' === $action) {
                return $layer_has_selection ? 'false' : 'unknown';
            }

            if ('not_selected' === $action) {
                return $layer_has_selection ? 'true' : 'unknown';
            }
        }

        if (! $layer_has_selection) {
            return 'unknown';
        }

        $choice_is_selected = in_array($choice_id, $selection_map[$layer_id], true);

        switch ($action) {
            case 'selected':
                return $choice_is_selected ? 'true' : 'false';
            case 'not_selected':
                return $choice_is_selected ? 'false' : 'true';
            default:
                return 'unknown';
        }
    }

    /**
     * Guess the source layer for sync actions within a condition.
     */
    private function resolve_condition_source_layer($condition)
    {
        if (empty($condition['rules']) || ! is_array($condition['rules'])) {
            return null;
        }

        foreach ($condition['rules'] as $rule) {
            if (! isset($rule['actioner']['layerId'])) {
                continue;
            }

            $layer_id = (int) $rule['actioner']['layerId'];
            if ($layer_id > 0) {
                return $layer_id;
            }
        }

        return null;
    }

    /**
     * Normalize choice identifiers.
     */
    private function normalize_choice_id($choice)
    {
        if (is_array($choice)) {
            if (isset($choice['_id'])) {
                return is_numeric($choice['_id']) ? (int) $choice['_id'] : $choice['_id'];
            }

            if (isset($choice['id'])) {
                return is_numeric($choice['id']) ? (int) $choice['id'] : $choice['id'];
            }
        }

        return null;
    }

    /**
     * Retrieve raw choice list for a layer.
     *
     * @param int $layer_id
     * @return array
     */
    private function get_layer_choices($layer_id)
    {
        if (isset($this->all_content[$layer_id]['choices']) && is_array($this->all_content[$layer_id]['choices'])) {
            return $this->all_content[$layer_id]['choices'];
        }

        return [];
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
                if (isset($image_data['angleId']) && (int) $image_data['angleId'] === 1) {
                    $image_id = $this->extract_image_id($image_data);
                    if ($image_id) {
                        return $image_id;
                    }
                }
            }

            // If no specific angle, try first image record
            $fallback = $this->extract_image_id($choice['images'][0]);
            if ($fallback) {
                return $fallback;
            }
        }

        // Some datasets expose a direct 'image' property rather than an images array
        if (isset($choice['image'])) {
            return $this->normalize_raw_image_value($choice['image']);
        }

        // Last resort: look for common field names used by extensions
        foreach (['image_id', 'featured_image', 'thumbnail_id'] as $key) {
            if (isset($choice[$key])) {
                $image_id = $this->normalize_raw_image_value($choice[$key]);
                if ($image_id) {
                    return $image_id;
                }
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MKL_PC Builder: missing image for choice ' . (isset($choice['_id']) ? $choice['_id'] : 'unknown') . ' layer ' . (isset($choice['layerId']) ? $choice['layerId'] : 'n/a') . ' data: ' . wp_json_encode($choice));
        }

        return 0;
    }

    /**
     * Extract attachment ID from a choice image data structure.
     *
     * @param array $image_data
     * @return int
     */
    private function extract_image_id($image_data)
    {
        if (!is_array($image_data)) {
            return 0;
        }

        if (isset($image_data['image'])) {
            return $this->normalize_raw_image_value($image_data['image']);
        }

        if (isset($image_data['id'])) {
            return intval($image_data['id']);
        }

        return 0;
    }

    /**
     * Normalise raw image references into attachment IDs.
     *
     * @param mixed $value
     * @return int
     */
    private function normalize_raw_image_value($value)
    {
        if (is_numeric($value)) {
            return intval($value);
        }

        if (is_array($value)) {
            if (isset($value['id']) && is_numeric($value['id'])) {
                return intval($value['id']);
            }
            if (isset($value[0]) && is_numeric($value[0])) {
                return intval($value[0]);
            }
        }

        // Sometimes a string reference like "image-123" is stored; extract the digits.
        if (is_string($value)) {
            if (preg_match('/(\d+)/', $value, $matches)) {
                return intval($matches[1]);
            }
        }

        return 0;
    }

    /**
     * Determine whether a layer or any ancestor is hidden in the UI.
     *
     * @param int $layer_id
     * @return bool
     */
    private function compute_layer_hidden_flag($layer_id)
    {
        $visited = [];
        $current = $layer_id;

        while ($current && ! isset($visited[$current])) {
            $visited[$current] = true;
            $layer = isset($this->layer_map[$current]) ? $this->layer_map[$current] : null;
            if (! $layer) {
                break;
            }

            if (! empty($layer['hide_in_configurator']) || ! empty($layer['hide_in_summary']) || ! empty($layer['hide_in_cart'])) {
                return true;
            }

            if (! empty($layer['not_a_choice'])) {
                return true;
            }

            $parent = isset($layer['parent']) ? (int) $layer['parent'] : 0;
            if (! $parent) {
                break;
            }

            $current = $parent;
        }

        return false;
    }

    /**
     * Resolve a layer's parent layer ID.
     *
     * @param int $layer_id
     * @return int
     */
    private function resolve_layer_parent($layer_id)
    {
        if (! isset($this->layer_map[$layer_id])) {
            return 0;
        }

        $parent = isset($this->layer_map[$layer_id]['parent'])
            ? (int) $this->layer_map[$layer_id]['parent']
            : 0;

        return $parent > 0 ? $parent : 0;
    }

    /**
     * Ensure configuration items follow image_order/order sequence so rendering matches admin layer stack
     *
     * @param array $configuration
     * @return array
     *
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
