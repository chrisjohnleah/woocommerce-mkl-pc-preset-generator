<?php

/**
 * Plugin Name:       Product Configurator - Bulk Preset Generator
 * Plugin URI:        http://wc-product-configurator.com/
 * Description:       Automatically generate all valid configuration presets based on conditional logic rules
 * Version:           1.0.5
 * Author:            Digital Services Northwest
 * Author URI:        https://digitalservicesnorthwest.co.uk
 * Text Domain:       mkl-pc-preset-generator
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if (! defined('ABSPATH')) {
    die;
}

define('MKL_PC_PRESET_GENERATOR_VERSION', '1.0.5');
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

        // Load includes
        require_once MKL_PC_PRESET_GENERATOR_PATH . 'includes/class-combination-generator.php';
        require_once MKL_PC_PRESET_GENERATOR_PATH . 'includes/class-smart-combination-generator.php';
        require_once MKL_PC_PRESET_GENERATOR_PATH . 'includes/class-conditional-validator.php';
        require_once MKL_PC_PRESET_GENERATOR_PATH . 'includes/class-configuration-builder.php';
        require_once MKL_PC_PRESET_GENERATOR_PATH . 'includes/class-preset-saver.php';
        require_once MKL_PC_PRESET_GENERATOR_PATH . 'includes/class-admin-ui.php';

        // Initialise components
        MKL_PC_Preset_Generator_Admin_UI::instance();
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
