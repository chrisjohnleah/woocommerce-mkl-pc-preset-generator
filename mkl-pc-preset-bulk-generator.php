<?php

/**
 * Plugin Name:       Product Configurator - Bulk Preset Generator
 * Plugin URI:        http://wc-product-configurator.com/
 * Description:       Automatically generate all valid configuration presets based on conditional logic rules
 * Version:           1.1.4
 * Author:            Happy Webs Limited
 * Author URI:        https://happywebs.co.uk
 * Text Domain:       mkl-pc-preset-generator
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if (! defined('ABSPATH')) {
    die;
}

define('MKL_PC_PRESET_GENERATOR_VERSION', '1.1.4');
define('MKL_PC_PRESET_GENERATOR_PATH', plugin_dir_path(__FILE__));
define('MKL_PC_PRESET_GENERATOR_URL', plugin_dir_url(__FILE__));

/**
 * Main plugin class
 */
class MKL_PC_Preset_Bulk_Generator
{

    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        add_action('plugins_loaded', [$this, 'init']);
    }

    /**
     * Initialise plugin
     */
    public function init()
    {
        // Check dependencies
        if (! $this->check_dependencies()) {
            add_action('admin_notices', [$this, 'dependency_notice']);
            return;
        }

        // Prevent MKL PC from loading all presets into memory on preset admin page
        // The preset admin loads ALL presets into JavaScript via wp_localize_script
        // which causes 512MB+ memory exhaustion with thousands of presets
        // We intercept the WP_Query before it executes and return empty array
        add_action('mkl_pc_scripts_product_page_after', [$this, 'prevent_preset_loading'], 1);

        // Load includes
        require_once MKL_PC_PRESET_GENERATOR_PATH . 'includes/class-combination-generator.php';
        require_once MKL_PC_PRESET_GENERATOR_PATH . 'includes/class-smart-combination-generator.php';
        require_once MKL_PC_PRESET_GENERATOR_PATH . 'includes/class-conditional-validator.php';
        require_once MKL_PC_PRESET_GENERATOR_PATH . 'includes/class-configuration-builder.php';
        require_once MKL_PC_PRESET_GENERATOR_PATH . 'includes/class-preset-saver.php';
        require_once MKL_PC_PRESET_GENERATOR_PATH . 'includes/class-layer-variation-expander.php';
        require_once MKL_PC_PRESET_GENERATOR_PATH . 'includes/class-image-diagnostics.php';
        require_once MKL_PC_PRESET_GENERATOR_PATH . 'includes/class-image-generator.php';
        require_once MKL_PC_PRESET_GENERATOR_PATH . 'includes/class-admin-ui.php';

        // Initialise components
        MKL_PC_Preset_Generator_Admin_UI::instance();
    }

    /**
     * Prevent MKL PC from loading all presets into JavaScript
     * This causes memory issues with thousands of presets
     */
    public function prevent_preset_loading()
    {
        if (! isset($_REQUEST['pc-presets-admin'])) {
            return;
        }

        // Intercept the get_posts query that loads all presets
        // and return empty array to prevent memory exhaustion
        add_filter('posts_pre_query', function ($posts, $query) {
            // Only intercept if this is a preset configuration query
            if (isset($query->query_vars['post_type']) && 
                $query->query_vars['post_type'] === 'mkl_pc_configuration' &&
                isset($query->query_vars['post_status']) &&
                (is_array($query->query_vars['post_status']) && in_array('preset', $query->query_vars['post_status'], true) ||
                 $query->query_vars['post_status'] === 'preset')) {
                
                // Check if this is being called from the MKL PC preset admin script enqueue
                $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
                foreach ($backtrace as $trace) {
                    if (isset($trace['function']) && $trace['function'] === 'add_scripts' &&
                        isset($trace['class']) && $trace['class'] === 'MKL_PC_Presets_Admin') {
                        // Return empty array to prevent loading all presets
                        return [];
                    }
                }
            }
            
            return $posts;
        }, 10, 2);
    }

    /**
     * Check required dependencies are active
     */
    private function check_dependencies()
    {
        // Check if the product configurator is present
        $required_functions = [
            'mkl_pc',  // Product Configurator helper function
        ];

        foreach ($required_functions as $func) {
            if (! function_exists($func)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Show dependency notice
     */
    public function dependency_notice()
    {
?>
        <div class="notice notice-error">
            <p>
                <strong><?php esc_html_e('Product Configurator - Bulk Preset Generator', 'mkl-pc-preset-generator'); ?></strong>
                <?php esc_html_e('requires Product Configurator, Save Your Design, and Conditional Logic plugins to be active.', 'mkl-pc-preset-generator'); ?>
            </p>
        </div>
<?php
    }
}

// Initialise plugin
MKL_PC_Preset_Bulk_Generator::instance();
