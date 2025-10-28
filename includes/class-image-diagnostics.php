<?php

/**
 * Image Generation Diagnostics
 * 
 * Helper class to diagnose image generation issues
 */

if (! defined('ABSPATH')) {
    exit;
}

class MKL_PC_Preset_Image_Diagnostics
{
    /**
     * Analyze a configuration for image generation compatibility
     * 
     * @param array|string $configuration
     * @param int $preset_id
     * @return array Diagnostic information
     */
    public static function analyze_configuration($configuration, $preset_id = 0)
    {
        $report = [
            'preset_id' => $preset_id,
            'config_type' => gettype($configuration),
            'is_valid_json' => false,
            'layer_count' => 0,
            'visual_layers' => 0,
            'user_layers' => 0,
            'layers_with_images' => 0,
            'layers_without_images' => 0,
            'image_ids_found' => [],
            'issues' => [],
            'sample_layers' => [],
        ];

        // Parse configuration if it's a JSON string
        if (is_string($configuration)) {
            $decoded = json_decode($configuration, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $report['is_valid_json'] = true;
                $configuration = $decoded;
            } else {
                $report['issues'][] = 'Invalid JSON: ' . json_last_error_msg();
                return $report;
            }
        }

        if (!is_array($configuration)) {
            $report['issues'][] = 'Configuration is not an array after parsing';
            return $report;
        }

        $report['layer_count'] = count($configuration);

        // Analyze each layer
        foreach ($configuration as $index => $layer) {
            if (!is_array($layer) && !is_object($layer)) {
                $report['issues'][] = "Layer $index is not an array or object";
                continue;
            }

            $layer_array = is_object($layer) ? (array)$layer : $layer;
            
            $is_choice = isset($layer_array['is_choice']) ? $layer_array['is_choice'] : false;
            $layer_id = isset($layer_array['layer_id']) ? $layer_array['layer_id'] : 'unknown';
            $layer_name = isset($layer_array['layer_name']) ? $layer_array['layer_name'] : 'unnamed';
            $image = isset($layer_array['image']) ? $layer_array['image'] : null;

            if ($is_choice) {
                $report['user_layers']++;
            } else {
                $report['visual_layers']++;
            }

            // Check for image
            if ($image !== null && $image !== '' && $image !== 0) {
                $report['layers_with_images']++;
                if (is_numeric($image)) {
                    $report['image_ids_found'][] = (int)$image;
                }
            } else {
                $report['layers_without_images']++;
            }

            // Sample first 3 layers
            if ($index < 3) {
                $report['sample_layers'][] = [
                    'index' => $index,
                    'layer_id' => $layer_id,
                    'layer_name' => $layer_name,
                    'is_choice' => $is_choice,
                    'image' => $image,
                    'has_order' => isset($layer_array['order']),
                    'has_image_order' => isset($layer_array['image_order']),
                ];
            }
        }

        // Check if we have any visual layers
        if ($report['visual_layers'] === 0) {
            $report['issues'][] = 'No visual layers found - images might not generate';
        }

        // Check if visual layers have images
        if ($report['visual_layers'] > 0 && $report['layers_with_images'] === 0) {
            $report['issues'][] = 'Visual layers exist but none have image IDs';
        }

        // Validate image IDs exist in WordPress
        foreach ($report['image_ids_found'] as $image_id) {
            $attachment = get_post($image_id);
            if (!$attachment || $attachment->post_type !== 'attachment') {
                $report['issues'][] = "Image ID $image_id is not a valid attachment";
            }
        }

        return $report;
    }

    /**
     * Log diagnostic report
     * 
     * @param array $report
     */
    public static function log_report($report)
    {
        error_log("=== IMAGE GENERATION DIAGNOSTIC REPORT ===");
        error_log("Preset ID: " . $report['preset_id']);
        error_log("Config Type: " . $report['config_type']);
        error_log("Valid JSON: " . ($report['is_valid_json'] ? 'Yes' : 'No'));
        error_log("Total Layers: " . $report['layer_count']);
        error_log("User Layers: " . $report['user_layers']);
        error_log("Visual Layers: " . $report['visual_layers']);
        error_log("Layers with Images: " . $report['layers_with_images']);
        error_log("Layers without Images: " . $report['layers_without_images']);
        error_log("Image IDs Found: " . implode(', ', $report['image_ids_found']));
        
        if (!empty($report['issues'])) {
            error_log("ISSUES:");
            foreach ($report['issues'] as $issue) {
                error_log("  - " . $issue);
            }
        }
        
        if (!empty($report['sample_layers'])) {
            error_log("SAMPLE LAYERS:");
            foreach ($report['sample_layers'] as $layer) {
                error_log(sprintf(
                    "  [%d] %s (ID: %s, is_choice: %s, image: %s)",
                    $layer['index'],
                    $layer['layer_name'],
                    $layer['layer_id'],
                    $layer['is_choice'] ? 'yes' : 'no',
                    var_export($layer['image'], true)
                ));
            }
        }
        
        error_log("=== END DIAGNOSTIC REPORT ===");
    }
}
