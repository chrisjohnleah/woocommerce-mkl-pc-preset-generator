# Debugging Image Generation Issue

## Problem
Images (thumbnails) are not being generated for presets created by the bulk preset generator.

## Solution Implemented

We've added comprehensive logging and diagnostics to identify why image generation is failing. The code now provides detailed error messages and validates all requirements before attempting to generate images.

## How to Test

1. **Enable WordPress Debug Logging**
   
   Add these lines to your `wp-config.php`:
   ```php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   define('WP_DEBUG_DISPLAY', false);
   ```

2. **Navigate to the Preset Admin Page**
   
   Go to: `http://localhost:10003/product/heavy-duty-workbench/?pc-presets-admin=12570`

3. **Run Preset Generation**
   
   - Click "Estimate Valid Presets" to see how many will be generated
   - Click "Generate All Presets" to start generation
   - Watch the progress in the UI

4. **Check the Error Logs**
   
   Look in `wp-content/debug.log` for detailed information:
   
   ```bash
   tail -f wp-content/debug.log | grep "Image Generator\|Config Builder\|Diagnostic"
   ```

## What to Look For in Logs

### 1. Configuration Diagnostics

You'll see reports like:
```
=== IMAGE GENERATION DIAGNOSTIC REPORT ===
Preset ID: 12345
Total Layers: 8
User Layers: 3
Visual Layers: 5
Layers with Images: 5
Image IDs Found: 123, 456, 789
```

**Issues to check:**
- `Visual Layers: 0` - No visual layers found (images won't generate)
- `Layers with Images: 0` - Visual layers exist but have no image IDs
- `ISSUES: Image ID XXX is not a valid attachment` - Referenced images don't exist

### 2. Image Generator Status

Check for:
```
Image Generator: Starting image generation for preset #12345
Image Generator: Requirements check failed: [error message]
```

**Common issues:**
- `Neither GD nor Imagick extension is available` - Install PHP GD or Imagick
- `Upload directory is not writable` - Fix file permissions
- `No configuration content available` - Configuration wasn't saved properly

### 3. Configuration Builder Output

Look for:
```
Config Builder: Building configuration from 3 user choices
Config Builder: Added visual layer 'Visual - Frame' with image ID 123
Config Builder: Built configuration with 3 user layers and 5 visual layers
```

**Issues to check:**
- Low or zero visual layers added
- Image IDs showing as 0
- "No image found for choice" messages

## Common Issues and Fixes

### Issue 1: No Visual Layers in Configuration

**Symptom**: Log shows `Visual Layers: 0`

**Cause**: Product doesn't have visual/display layers configured

**Fix**: 
1. Edit the product in WooCommerce
2. Go to Product Configurator settings
3. Add visual layers (layers with `not_a_choice = true`)
4. Assign images to choices in those layers

### Issue 2: Visual Layers Have No Images

**Symptom**: `Visual Layers: 5` but `Layers with Images: 0`

**Cause**: Choices in visual layers don't have images assigned

**Fix**:
1. Edit each visual layer
2. For each choice, upload and assign an image
3. Ensure images are set for the default angle (angle_id = 1)

### Issue 3: Invalid Image Attachment IDs

**Symptom**: `Image ID 123 is not a valid attachment`

**Cause**: Referenced images were deleted or don't exist

**Fix**:
1. Check Media Library for the image
2. Re-upload if needed
3. Re-assign to the choice in Product Configurator

### Issue 4: GD/Imagick Extension Missing

**Symptom**: `Neither GD nor Imagick extension is available`

**Cause**: PHP doesn't have image processing libraries

**Fix**:
```bash
# For GD (Ubuntu/Debian)
sudo apt-get install php-gd
sudo service apache2 restart

# For Imagick
sudo apt-get install php-imagick
sudo service apache2 restart
```

### Issue 5: Upload Directory Not Writable

**Symptom**: `Upload directory is not writable`

**Cause**: File permission issues

**Fix**:
```bash
# Check current permissions
ls -la wp-content/uploads/

# Fix permissions (adjust user/group as needed)
sudo chown -R www-data:www-data wp-content/uploads/
sudo chmod -R 755 wp-content/uploads/
```

### Issue 6: MKL PC save_image() Returns False

**Symptom**: `save_image returned false/null`

**Possible causes:**
1. Configuration format doesn't match what MKL PC expects
2. Product doesn't have proper configurator settings
3. Missing required data in configuration
4. Internal MKL PC error

**Fix**:
1. Check that product has Product Configurator enabled
2. Verify all layers have proper settings
3. Test saving a preset manually through the frontend
4. Check MKL PC plugin logs for more details

## Advanced Debugging

### Check Image Generation Requirements

Add this to your theme's `functions.php` temporarily:

```php
add_action('admin_init', function() {
    if (isset($_GET['check_image_gen'])) {
        $status = MKL_PC_Preset_Image_Generator::get_status();
        echo '<pre>';
        print_r($status);
        echo '</pre>';
        die;
    }
});
```

Then visit: `http://localhost:10003/wp-admin/?check_image_gen=1`

### Manual Image Generation Test

Try generating an image for a specific preset:

```php
add_action('admin_init', function() {
    if (isset($_GET['test_image_gen']) && isset($_GET['preset_id'])) {
        $preset_id = intval($_GET['preset_id']);
        $result = MKL_PC_Preset_Image_Generator::generate_image($preset_id);
        
        if (is_wp_error($result)) {
            echo 'Error: ' . $result->get_error_message();
        } else {
            echo 'Success! Image ID: ' . $result;
        }
        die;
    }
});
```

Visit: `http://localhost:10003/wp-admin/?test_image_gen=1&preset_id=12345`

## Next Steps

1. Enable debug logging
2. Run preset generation
3. Review the debug log for specific errors
4. Apply the appropriate fix from above
5. Re-run generation to verify the fix

## Support

If the issue persists after trying these fixes:
1. Collect the full debug log output
2. Note which specific error messages appear
3. Check the MKL Product Configurator plugin documentation
4. Verify that manual preset creation (without the bulk generator) works and generates images
