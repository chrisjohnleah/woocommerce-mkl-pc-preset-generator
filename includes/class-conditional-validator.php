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

    /**
     * Constructor
     */
    public function __construct($product_id)
    {
        $this->product_id = $product_id;
        $this->db = \MKL\PC\Plugin::instance()->db;
        $this->load_conditions();
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

        // Check each condition
        foreach ($this->conditions as $condition) {
            // Skip disabled conditions
            if (! isset($condition['enabled']) || ! $condition['enabled']) {
                continue;
            }

            $matches = 0;
            $total_rules = isset($condition['rules']) ? count($condition['rules']) : 0;

            // Check all rules
            if (isset($condition['rules'])) {
                foreach ($condition['rules'] as $rule) {
                    if ($this->check_rule($rule, $selection_map)) {
                        $matches++;
                    }
                }
            }

            // Determine if condition is met
            $condition_met = false;
            if (isset($condition['relationship']) && $condition['relationship'] === 'OR') {
                $condition_met = $matches > 0;
            } else {
                // AND relationship (default)
                $condition_met = $matches === $total_rules;
            }

            // If condition is met, check if it would invalidate a USER-SELECTED layer
            if ($condition_met && isset($condition['actions'])) {
                if (! $this->validate_actions($condition['actions'], $selection_map, $user_layer_ids)) {
                    return false;
                }
            }
        }

        return true;
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

        foreach ($this->conditions as $condition) {
            if (! isset($condition['enabled']) || ! $condition['enabled']) {
                continue;
            }

            $status = $this->evaluate_condition_status($condition, $selection_map);

            if ('met' === $status && isset($condition['actions'])) {
                if (! $this->validate_actions($condition['actions'], $selection_map, $selected_layer_ids)) {
                    return false;
                }
            }
        }

        return true;
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
        }

        if (-2 === $choice_id) {
            if ($layer_has_selection) {
                return 'false';
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
