# Customisation Guide

## Product-Specific Layer Filtering

By default, the plugin includes all user-facing layers except:

- Hidden layers (`hide_in_configurator = true`)
- Visual layers (names starting with "Visual -")
- Group layers (`type = 'group'`)
- Form layers (`type = 'form'`)

### Customise Which Layers to Include

You can filter layers per product using WordPress hooks:

```php
// Exclude specific layers by name for a particular product
add_filter('mkl_pc_preset_generator_include_layer', function($include, $layer, $product_id) {
    // For product 12345, exclude "Premium Upgrades" layer
    if ($product_id == 12345 && isset($layer['name']) && $layer['name'] === 'Premium Upgrades') {
        return false;
    }
    
    return $include;
}, 10, 3);
```

```php
// Only include core layers for all products
add_filter('mkl_pc_preset_generator_include_layer', function($include, $layer, $product_id) {
    $core_layers = ['Size', 'Colour', 'Material', 'Finish'];
    
    $layer_name = isset($layer['name']) ? $layer['name'] : '';
    
    if (!in_array($layer_name, $core_layers)) {
        return false; // Exclude non-core layers
    }
    
    return $include;
}, 10, 3);
```

```php
// Modify the final layer list
add_filter('mkl_pc_preset_generator_user_layers', function($layers, $product_id) {
    // For product 12570, limit to first 5 layers to reduce combinations
    if ($product_id == 12570) {
        return array_slice($layers, 0, 5);
    }
    
    return $layers;
}, 10, 2);
```

### Customise Preset Naming

```php
// Customise how presets are named
add_filter('mkl_pc_preset_generator_preset_name', function($name, $combination, $product_id) {
    // Add product-specific prefix
    $product = wc_get_product($product_id);
    return $product->get_name() . ' - ' . $name;
}, 10, 3);
```

### Modify Conditional Validation

The conditional validator automatically loads rules from:
`get_post_meta($product_id, '_mkl_pc__conditions', true);`

This is completely dynamic and works with any product's conditional logic setup.

## Performance Tuning

### Limit Generation for Testing

```php
// Limit to first 100 combinations for testing
add_filter('mkl_pc_preset_generator_max_combinations', function($max, $product_id) {
    return 100; // Stop after 100 valid presets
}, 10, 2);
```

### Adjust Batch Size

Modify the batch size in the AJAX call (default: 50):

```javascript
// In your theme's functions.php or custom plugin
add_action('wp_footer', function() {
    if (!isset($_REQUEST['pc-presets-admin'])) return;
    ?>
    <script>
    jQuery(document).ready(function($) {
        // Override batch size
        $(document).on('click', '.mkl-pc-generate-btn', function(e) {
            // Modify batch_size in the AJAX data
            // See assets/js/bulk-generator.js line ~155
        });
    });
    </script>
    <?php
});
```

## Database Structure

The plugin works with standard Product Configurator meta keys:

- `_mkl_product_configurator_layers` - Layer definitions
- `_mkl_product_configurator_content` - Choice/option data
- `_mkl_product_configurator_conditions` - Conditional logic rules

All data is fetched dynamically using:

```php
$db = \MKL\PC\Plugin::instance()->db;
$layers = $db->get('layers', $product_id);
$content = $db->get('content', $product_id);
$conditions = get_post_meta($product_id, '_mkl_pc__conditions', true);
```

## Multi-Product Usage

The plugin automatically works on any configurable product:

1. Navigate to: `yoursite.com/product/any-product/?pc-presets-admin=PRODUCT_ID`
2. The Bulk Preset Generator panel appears
3. Click "Estimate Combinations" to analyse that product's layers
4. Click "Generate All Presets" to create presets for that specific product

Each product maintains its own:

- Layer configuration
- Conditional logic rules
- Preset list
- Generation statistics

## Extending the Plugin

### Add Custom Validation Rules

```php
add_filter('mkl_pc_preset_generator_validate_combination', function($is_valid, $combination, $product_id) {
    // Add custom business logic validation
    foreach ($combination as $choice) {
        if ($choice['layer_name'] === 'Size' && $choice['choice_name'] === '2000mm') {
            // Example: 2000mm size requires premium material
            $has_premium = false;
            foreach ($combination as $c) {
                if ($c['layer_name'] === 'Material' && strpos($c['choice_name'], 'Premium') !== false) {
                    $has_premium = true;
                    break;
                }
            }
            if (!$has_premium) {
                return false; // Invalid combination
            }
        }
    }
    
    return $is_valid;
}, 10, 3);
```

### Hook Into Generation Events

```php
// Before generation starts
add_action('mkl_pc_preset_generator_before_batch', function($product_id, $offset) {
    error_log("Starting batch for product $product_id at offset $offset");
}, 10, 2);

// After each preset is saved
add_action('mkl_pc_preset_generator_preset_saved', function($preset_id, $combination, $product_id) {
    // Do something with the newly created preset
    update_post_meta($preset_id, '_custom_meta', 'value');
}, 10, 3);
```

## Troubleshooting

### Too Many Combinations

If a product has too many layers/choices creating billions of combinations:

1. Use the filter hooks above to limit which layers are included
2. Focus on "core" configuration options (size, colour, material)
3. Let customers add accessories manually via the configurator

### Slow Generation

- Reduce `batch_size` from 50 to 20-30 for complex products
- Run during off-peak hours
- Increase PHP `max_execution_time` if needed

### Invalid Combinations Still Being Saved

- Check that conditional logic rules are properly configured in Product
  Configurator admin
- Verify rules are enabled (`enabled = 1`)
- Check rule actions (hide/show/disable/enable)
- Review the conditional validator logic in `class-conditional-validator.php`
