<?php

/**
 * Image Generator
 * 
 * Handles image generation for presets with proper error handling and fallbacks
 */

if (! defined('ABSPATH')) {
    exit;
}

class MKL_PC_Preset_Image_Generator
{
    /**
     * Generate image for a preset with comprehensive error handling
     * 
     * @param int $preset_id The preset post ID
     * @param array|string $configuration Optional configuration data
     * @return int|WP_Error Image attachment ID on success, WP_Error on failure
     */
    public static function generate_image($preset_id, $configuration = null)
    {
        if (!$preset_id) {
            return new WP_Error('invalid_preset_id', 'Invalid preset ID provided');
        }

        error_log("Image Generator: Starting image generation for preset #$preset_id");

        // Check if image generation is even possible
        $requirements_check = self::check_requirements();
        if (is_wp_error($requirements_check)) {
            error_log("Image Generator: Requirements check failed: " . $requirements_check->get_error_message());
            return $requirements_check;
        }

        // Load the preset
        try {
            $preset = new Mkl_PC_Preset_Configuration($preset_id);
        } catch (Exception $e) {
            error_log("Image Generator: Failed to load preset: " . $e->getMessage());
            return new WP_Error('preset_load_failed', $e->getMessage());
        }

        // Get configuration content
        $content = null;
        
        if ($configuration !== null) {
            $content = $configuration;
        } elseif (!empty($preset->content)) {
            $content = $preset->content;
        } else {
            // Try loading from post_content
            $post = get_post($preset_id);
            if ($post && !empty($post->post_content)) {
                $content = $post->post_content;
                error_log("Image Generator: Loaded content from post_content");
            }
        }

        if (empty($content)) {
            error_log("Image Generator: No content available for image generation");
            return new WP_Error('no_content', 'No configuration content available');
        }

        // Run diagnostics
        $diagnostic = MKL_PC_Preset_Image_Diagnostics::analyze_configuration($content, $preset_id);
        MKL_PC_Preset_Image_Diagnostics::log_report($diagnostic);

        // Check for critical issues
        if (!empty($diagnostic['issues'])) {
            $has_critical = false;
            foreach ($diagnostic['issues'] as $issue) {
                if (strpos($issue, 'not a valid attachment') !== false) {
                    $has_critical = true;
                    break;
                }
            }
            if ($has_critical) {
                error_log("Image Generator: Critical configuration issues detected");
                return new WP_Error('config_issues', implode('; ', $diagnostic['issues']));
            }
        }

        // Attempt image generation
        error_log("Image Generator: Calling save_image() method");
        
        try {
            $image_id = $preset->save_image($content, $preset_id);
            
            if (is_wp_error($image_id)) {
                error_log("Image Generator: save_image returned WP_Error: " . $image_id->get_error_message());
                return $image_id;
            }
            
            if (!$image_id) {
                error_log("Image Generator: save_image returned false/null");
                return new WP_Error('generation_failed', 'Image generation returned false');
            }
            
            // Verify the image was actually created
            $attachment = get_post($image_id);
            if (!$attachment || $attachment->post_type !== 'attachment') {
                error_log("Image Generator: Generated ID $image_id is not a valid attachment");
                return new WP_Error('invalid_attachment', 'Generated image ID is not valid');
            }
            
            error_log("Image Generator: Successfully generated image #$image_id");
            return $image_id;
            
        } catch (Exception $e) {
            error_log("Image Generator: Exception during generation: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return new WP_Error('generation_exception', $e->getMessage());
        }
    }

    /**
     * Check if image generation requirements are met
     * 
     * @return true|WP_Error
     */
    private static function check_requirements()
    {
        // Check if GD or Imagick is available
        if (!extension_loaded('gd') && !extension_loaded('imagick')) {
            return new WP_Error('no_image_library', 'Neither GD nor Imagick extension is available');
        }

        // Check if uploads directory is writable
        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['error'])) {
            return new WP_Error('upload_dir_error', $upload_dir['error']);
        }

        if (!wp_is_writable($upload_dir['path'])) {
            return new WP_Error('upload_not_writable', 'Upload directory is not writable');
        }

        // Check if MKL PC classes are available
        if (!class_exists('Mkl_PC_Preset_Configuration')) {
            return new WP_Error('mkl_pc_missing', 'MKL PC Preset Configuration class not found');
        }

        return true;
    }

    /**
     * Get detailed status of image generation capabilities
     * 
     * @return array Status information
     */
    public static function get_status()
    {
        $status = [
            'gd_available' => extension_loaded('gd'),
            'imagick_available' => extension_loaded('imagick'),
            'mkl_pc_available' => class_exists('Mkl_PC_Preset_Configuration'),
            'upload_dir_writable' => false,
            'upload_dir_path' => '',
            'requirements_met' => false,
        ];

        $upload_dir = wp_upload_dir();
        if (empty($upload_dir['error'])) {
            $status['upload_dir_writable'] = wp_is_writable($upload_dir['path']);
            $status['upload_dir_path'] = $upload_dir['path'];
        }

        $requirements = self::check_requirements();
        $status['requirements_met'] = !is_wp_error($requirements);
        
        if (is_wp_error($requirements)) {
            $status['requirements_error'] = $requirements->get_error_message();
        }

        return $status;
    }
}
