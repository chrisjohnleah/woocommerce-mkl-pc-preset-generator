<?php

/**
 * Conditional Validator
 * 
 * Validates combinations against conditional logic rules
 */

if (! defined('ABSPATH')) {
    exit;
}

class MKL_PC_Preset_Conditional_Validator
{

    private $product_id;
    private $conditions;
    private $db;
    private $layers = [];
    private $layer_map = [];
    private $choice_map = [];

    /**
     * Constructor
     */
    public function __construct($product_id)
    {
        $this->product_id = $product_id;
        $this->db = \MKL\PC\Plugin::instance()->db;
        $this->load_conditions();
        $this->load_structure();
    }

    /**
     * Load conditional logic rules for the product
     */
    private function load_conditions()
    {
        // Use the Product Configurator's DB method for consistency
        $this->conditions = $this->db->get('conditions', $this->product_id);

        if (! is_array($this->conditions)) {
            $this->conditions = [];
        }

        error_log("Loaded " . count($this->conditions) . " conditions for product " . $this->product_id);
    }

    /**
     * Load layers and choices to evaluate default visibility and mapping.
     */
    private function load_structure()
    {
        $layers = $this->db->get('layers', $this->product_id);
        $this->layers = is_array($layers) ? $layers : [];
        $this->layer_map = [];
        foreach ($this->layers as $layer) {
            if (isset($layer['_id'])) {
                $this->layer_map[(int) $layer['_id']] = $layer;
            }
        }

        $content_rows = $this->db->get('content', $this->product_id);
        $this->choice_map = [];
        if (is_array($content_rows)) {
            foreach ($content_rows as $row) {
                if (! isset($row['layerId'])) {
                    continue;
                }
                $lid = (int) $row['layerId'];
                if (! empty($row['choices']) && is_array($row['choices'])) {
                    foreach ($row['choices'] as $choice) {
                        // Normalise id
                        $cid = null;
                        if (isset($choice['_id'])) {
                            $cid = is_numeric($choice['_id']) ? (int) $choice['_id'] : $choice['_id'];
                        } elseif (isset($choice['id'])) {
                            $cid = is_numeric($choice['id']) ? (int) $choice['id'] : $choice['id'];
                        }
                        if ($cid === null) {
                            continue;
                        }
                        if (! isset($this->choice_map[$lid])) {
                            $this->choice_map[$lid] = [];
                        }
                        $this->choice_map[$lid][$cid] = $choice;
                    }
                }
            }
        }
    }

    /**
     * Validate a combination against all conditional rules
     * 
     * For preset generation, we only care if USER-SELECTED choices would be hidden.
     * Visual layers and display logic don't make a combination "invalid".
     * 
     * @param array $combination Array of selected choices
     * @return bool True if valid, false otherwise
     */
    public function validate_combination($combination)
    {
        // Get all user-facing layer IDs from the combination
        $user_layer_ids = [];
        foreach ($combination as $choice) {
            if ($choice['choice_id'] !== null) {
                $user_layer_ids[] = $choice['layer_id'];
            }
        }

        // If no conditions, all combinations are valid
        if (empty($this->conditions)) {
            error_log("WARNING: No conditions loaded for product " . $this->product_id . " - accepting all combinations!");
            return true;
        }

        // Create a lookup map for quick access
        $selection_map = [];
        foreach ($combination as $choice) {
            if ($choice['choice_id'] !== null) {
                $layer_id = $choice['layer_id'];
                if (! isset($selection_map[$layer_id])) {
                    $selection_map[$layer_id] = [];
                }
                $selection_map[$layer_id][] = $choice['choice_id'];
            }
        }

        // Build visibility state based on defaults (cshow) and conditions
        $state = $this->compute_visibility_state($selection_map);

        // 1) Every selected layer must remain visible
        foreach ($selection_map as $lid => $choices) {
            if (! empty($choices)) {
                if (isset($state['layers'][$lid]['visible']) && ! $state['layers'][$lid]['visible']) {
                    return false;
                }
            }
        }

        // 2) Every selected choice must remain visible AND enabled
        foreach ($selection_map as $lid => $choices) {
            foreach ($choices as $cid) {
                if ($cid === null) continue;
                if (isset($state['choices'][$lid][$cid]['visible']) && ! $state['choices'][$lid][$cid]['visible']) {
                    return false;
                }
                if (isset($state['choices'][$lid][$cid]['enabled']) && $state['choices'][$lid][$cid]['enabled'] === false) {
                    return false;
                }
            }
        }

        // 2b) If a layer is visible and required, ensure it has at least one selected choice
        foreach ($this->layer_map as $lid => $layer) {
            if (! empty($state['layers'][$lid]['visible'])) {
                $is_required = ! empty($layer['required']);
                if ($is_required) {
                    $sel = isset($selection_map[$lid]) ? array_filter($selection_map[$lid], function($v){ return $v !== null; }) : [];
                    if (empty($sel)) {
                        return false;
                    }
                }
            }
        }

        // 3) Honour forced selections (actions -> select)
        foreach ($state['layers'] as $lid => $lstate) {
            if (isset($lstate['forced_choice'])) {
                $forced = $lstate['forced_choice'];
                $selected = isset($selection_map[$lid]) ? $selection_map[$lid] : [];
                if (! in_array($forced, $selected, true)) {
                    return false;
                }
            }
        }

        // 4) Existing logic: ensure actions don’t hide user selections
        foreach ($this->conditions as $condition) {
            if (! isset($condition['enabled']) || ! $condition['enabled']) continue;
            if (! isset($condition['actions']) || ! is_array($condition['actions'])) continue;
            $status = $this->evaluate_condition_status($condition, $selection_map);
            if ('met' !== $status) continue;
            if (! $this->validate_actions($condition['actions'], $selection_map, $user_layer_ids)) {
                $is_valid = false;
                // Allow custom code to override
                return (bool) apply_filters('mkl_pc_preset_generator_validate_combination', $is_valid, $combination, $this->product_id);
            }
        }

        // 5) Ensure this selection yields at least one visual image when built
        $builder = new MKL_PC_Configuration_Builder($this->product_id);
        $config = $builder->build_complete_configuration($combination);
        $has_image = false;
        if (is_array($config)) {
            foreach ($config as $layer) {
                if ((is_array($layer) && empty($layer['is_choice'])) || (is_object($layer) && isset($layer->is_choice) && ! $layer->is_choice)) {
                    $img = is_array($layer) ? (isset($layer['image']) ? $layer['image'] : 0) : (isset($layer->image) ? $layer->image : 0);
                    if (!empty($img)) { $has_image = true; break; }
                }
            }
        }
        if (! $has_image) {
            return false;
        }

        $is_valid = true;
        return (bool) apply_filters('mkl_pc_preset_generator_validate_combination', $is_valid, $combination, $this->product_id);
    }

    public function validate_partial_combination($combination)
    {
        if (empty($this->conditions)) {
            return true;
        }

        $selection_map = [];
        foreach ($combination as $choice) {
            if (null === $choice['choice_id']) {
                continue;
            }

            $layer_id = $choice['layer_id'];
            if (! isset($selection_map[$layer_id])) {
                $selection_map[$layer_id] = [];
            }

            $selection_map[$layer_id][] = $choice['choice_id'];
        }

        $selected_layer_ids = array_keys($selection_map);

        // Build partial visibility state
        $state = $this->compute_visibility_state($selection_map);

        // Reject immediately if a chosen item becomes hidden
        foreach ($selection_map as $lid => $choices) {
            if (! empty($choices)) {
                if (isset($state['layers'][$lid]['visible']) && ! $state['layers'][$lid]['visible']) {
                    return false;
                }
            }
            foreach ($choices as $cid) {
                if ($cid === null) continue;
                if (isset($state['choices'][$lid][$cid]['visible']) && ! $state['choices'][$lid][$cid]['visible']) {
                    return false;
                }
            }
        }

        // Honour forced selections in partial state too
        foreach ($state['layers'] as $lid => $lstate) {
            if (isset($lstate['forced_choice']) && isset($selection_map[$lid])) {
                $forced = $lstate['forced_choice'];
                if (! in_array($forced, $selection_map[$lid], true)) {
                    $is_valid = false;
                    return (bool) apply_filters('mkl_pc_preset_generator_validate_combination', $is_valid, $selection, $this->product_id);
                }
            }
        }

        $is_valid = true;
        return (bool) apply_filters('mkl_pc_preset_generator_validate_combination', $is_valid, $selection, $this->product_id);
    }

    /**
     * Build visibility/selection state similar to the builder.
     *
     * @param array $selection_map layer_id => [choice_id,...]
     * @return array{layers: array, choices: array}
     */
    private function compute_visibility_state(array $selection_map)
    {
        $layer_state = [];
        foreach ($this->layer_map as $lid => $layer) {
            $visible = true;
            if (is_array($layer) && array_key_exists('cshow', $layer)) {
                $visible = ($layer['cshow'] !== false);
            }
            $layer_state[$lid] = [
                'visible' => $visible,
            ];
        }

        $choice_state = [];
        foreach ($this->choice_map as $lid => $choices) {
            foreach ($choices as $cid => $choice) {
                if (! isset($choice_state[$lid])) $choice_state[$lid] = [];
                $cvis = true;
                if (is_array($choice) && array_key_exists('cshow', $choice)) {
                    $cvis = ($choice['cshow'] !== false);
                }
                $choice_state[$lid][$cid] = [ 'visible' => $cvis, 'enabled' => true ];
            }
        }

        foreach ($this->conditions as $condition) {
            if (empty($condition['enabled'])) continue;
            $status = $this->evaluate_condition_status($condition, $selection_map);
            if ('met' !== $status) continue;
            if (empty($condition['actions']) || ! is_array($condition['actions'])) continue;

            $source_layer = $this->resolve_condition_source_layer($condition);

            foreach ($condition['actions'] as $action) {
                $type = isset($action['type']) ? $action['type'] : '';
                $name = isset($action['action']) ? $action['action'] : '';
                $lid  = isset($action['layerId']) ? (int) $action['layerId'] : 0;
                $cid  = isset($action['choiceId']) ? $action['choiceId'] : null;

                if ($type === 'layer' && $lid) {
                    if ($name === 'show') {
                        $layer_state[$lid]['visible'] = true;
                    } elseif ($name === 'hide') {
                        $layer_state[$lid]['visible'] = false;
                    } elseif ($name === 'sync' && $source_layer && $source_layer !== $lid) {
                        $layer_state[$lid]['sync_source'] = $source_layer;
                    } elseif ($name === 'reset') {
                        unset($layer_state[$lid]['forced_choice']);
                    }
                } elseif ($type === 'choice' && $lid && $cid !== null) {
                    if (! isset($choice_state[$lid])) $choice_state[$lid] = [];
                    if (! isset($choice_state[$lid][$cid])) $choice_state[$lid][$cid] = ['visible' => true, 'enabled' => true];

                    if ($name === 'show') {
                        $choice_state[$lid][$cid]['visible'] = true;
                    } elseif ($name === 'hide') {
                        $choice_state[$lid][$cid]['visible'] = false;
                    } elseif ($name === 'enable') {
                        $choice_state[$lid][$cid]['enabled'] = true;
                    } elseif ($name === 'disable') {
                        $choice_state[$lid][$cid]['enabled'] = false;
                    } elseif ($name === 'select') {
                        $layer_state[$lid]['forced_choice'] = $cid;
                    } elseif ($name === 'reset') {
                        unset($layer_state[$lid]['forced_choice']);
                    }
                }
            }
        }

        $state = [ 'layers' => $layer_state, 'choices' => $choice_state ];
        return apply_filters('mkl_pc_preset_generator_visibility_state', $state, $selection_map, $this->product_id);
    }

    /**
     * Public accessor to visibility/forced/enabled state for a given selection.
     * Accepts the same combination structure used by the generators.
     *
     * @param array $combination Array of ['layer_id'=>int,'choice_id'=>int|null,...]
     * @return array{layers: array, choices: array}
     */
    public function get_visibility_state_for_combination($combination)
    {
        $selection_map = [];
        if (is_array($combination)) {
            foreach ($combination as $choice) {
                if (!is_array($choice)) continue;
                if (!array_key_exists('layer_id', $choice)) continue;
                $lid = (int) $choice['layer_id'];
                $cid = array_key_exists('choice_id', $choice) ? $choice['choice_id'] : null;
                if (!isset($selection_map[$lid])) $selection_map[$lid] = [];
                $selection_map[$lid][] = $cid;
            }
        }
        return $this->compute_visibility_state($selection_map);
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
            if (! isset($rule['actioner']['layerId'])) continue;
            $lid = (int) $rule['actioner']['layerId'];
            if ($lid > 0) return $lid;
        }
        return null;
    }

    private function evaluate_condition_status($condition, $selection_map)
    {
        $relationship = (isset($condition['relationship']) && 'OR' === $condition['relationship']) ? 'OR' : 'AND';
        $rules        = isset($condition['rules']) ? $condition['rules'] : [];

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

    private function evaluate_rule_status($rule, $selection_map)
    {
        $layer_id  = isset($rule['actioner']['layerId']) ? $rule['actioner']['layerId'] : null;
        $choice_id = isset($rule['actioner']['choiceId']) ? $rule['actioner']['choiceId'] : null;
        $action    = isset($rule['action']) ? $rule['action'] : '';

        if (! $layer_id || null === $choice_id) {
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

            if ('visible' === $action) {
                $layer = isset($this->layer_map[$layer_id]) ? $this->layer_map[$layer_id] : [];
                $visible = true;
                if (is_array($layer) && array_key_exists('cshow', $layer)) {
                    $visible = ($layer['cshow'] !== false);
                }
                return $visible ? 'true' : 'false';
            }
            if ('hidden' === $action) {
                $layer = isset($this->layer_map[$layer_id]) ? $this->layer_map[$layer_id] : [];
                $visible = true;
                if (is_array($layer) && array_key_exists('cshow', $layer)) {
                    $visible = ($layer['cshow'] !== false);
                }
                return $visible ? 'false' : 'true';
            }
        }

        if (-2 === $choice_id) {
            if ($layer_has_selection) {
                return 'false';
            }

            // For visibility checks assume defaults
            if ('visible' === $action) {
                $layer = isset($this->layer_map[$layer_id]) ? $this->layer_map[$layer_id] : [];
                $visible = true;
                if (is_array($layer) && array_key_exists('cshow', $layer)) {
                    $visible = ($layer['cshow'] !== false);
                }
                return $visible ? 'true' : 'false';
            }
            if ('hidden' === $action) {
                $layer = isset($this->layer_map[$layer_id]) ? $this->layer_map[$layer_id] : [];
                $visible = true;
                if (is_array($layer) && array_key_exists('cshow', $layer)) {
                    $visible = ($layer['cshow'] !== false);
                }
                return $visible ? 'false' : 'true';
            }

            return 'unknown';
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
            case 'visible':
                $choice = isset($this->choice_map[$layer_id][$choice_id]) ? $this->choice_map[$layer_id][$choice_id] : [];
                $cvis = true;
                if (is_array($choice) && array_key_exists('cshow', $choice)) {
                    $cvis = ($choice['cshow'] !== false);
                }
                return $cvis ? 'true' : 'false';
            case 'hidden':
                $choice = isset($this->choice_map[$layer_id][$choice_id]) ? $this->choice_map[$layer_id][$choice_id] : [];
                $cvis = true;
                if (is_array($choice) && array_key_exists('cshow', $choice)) {
                    $cvis = ($choice['cshow'] !== false);
                }
                return $cvis ? 'false' : 'true';
            default:
                return 'unknown';
        }
    }

    /**
     * Check if a single rule matches the current selection
     * 
     * @param array $rule Conditional rule
     * @param array $selection_map Current selection state
     * @return bool
     */
    private function check_rule($rule, $selection_map)
    {
        $layer_id = isset($rule['actioner']['layerId']) ? $rule['actioner']['layerId'] : null;
        $choice_id = isset($rule['actioner']['choiceId']) ? $rule['actioner']['choiceId'] : null;
        $action = isset($rule['action']) ? $rule['action'] : '';

        if (! $layer_id || $choice_id === null) {
            return false;
        }

        // Check if layer has any selection
        $layer_has_selection = isset($selection_map[$layer_id]) && ! empty($selection_map[$layer_id]);

        // Special case: -1 means "anything is selected"
        if ($choice_id == -1) {
            if ($action === 'selected') {
                return $layer_has_selection;
            }
            if ($action === 'not_selected') {
                return ! $layer_has_selection;
            }
        }

        // Special case: -2 means "nothing is selected"
        if ($choice_id == -2) {
            return ! $layer_has_selection;
        }

        // Check if specific choice is selected
        $choice_is_selected = $layer_has_selection && in_array($choice_id, $selection_map[$layer_id]);

        switch ($action) {
            case 'selected':
                return $choice_is_selected;
            case 'not_selected':
                return ! $choice_is_selected;
            default:
                return false;
        }
    }

    /**
     * Validate that actions don't invalidate the combination
     * 
     * Only reject if actions would hide/disable USER-SELECTED choices.
     * Hiding visual/display layers is fine - those are auto-managed.
     * 
     * @param array $actions Conditional actions
     * @param array $selection_map Current selection state
     * @param array $user_layer_ids IDs of layers the user actually selected from
     * @return bool
     */
    private function validate_actions($actions, $selection_map, $user_layer_ids)
    {
        foreach ($actions as $action) {
            $type = isset($action['type']) ? $action['type'] : '';
            $action_name = isset($action['action']) ? $action['action'] : '';
            $layer_id = isset($action['layerId']) ? $action['layerId'] : null;
            $choice_id = isset($action['choiceId']) ? $action['choiceId'] : null;

            // ONLY care about actions affecting USER-SELECTED layers
            if (!in_array($layer_id, $user_layer_ids)) {
                // This action affects a visual/hidden layer, not a user choice - ignore it
                continue;
            }

            // Check if action would make a USER-SELECTED choice hidden/disabled
            if ($type === 'choice' && $choice_id && isset($selection_map[$layer_id])) {
                // If action hides or disables a selected choice, combination is invalid
                if (($action_name === 'hide' || $action_name === 'disable') && in_array($choice_id, $selection_map[$layer_id])) {
                    return false;
                }
            }

            // Check if action would hide a USER-SELECTED layer
            if ($type === 'layer' && $action_name === 'hide' && $layer_id) {
                if (isset($selection_map[$layer_id]) && ! empty($selection_map[$layer_id])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Get condition statistics
     */
    public function get_condition_stats()
    {
        return [
            'total_conditions' => count($this->conditions),
            'enabled_conditions' => count(array_filter($this->conditions, function ($c) {
                return isset($c['enabled']) && $c['enabled'];
            })),
        ];
    }
}
