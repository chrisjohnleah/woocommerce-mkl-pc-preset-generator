# Image Generation Issue - Investigation Summary

## What Was Done

I've investigated the preset image generation issue and implemented a comprehensive debugging and error handling solution.

## Changes Made

### New Files Created

1. **`includes/class-image-diagnostics.php`**
   - Analyzes configuration structure
   - Validates image attachment IDs
   - Identifies missing visual layers
   - Reports configuration issues

2. **`includes/class-image-generator.php`**
   - Wrapper for image generation with error handling
   - Validates system requirements (GD/Imagick)
   - Checks file permissions
   - Loads and validates content
   - Returns detailed error messages

3. **`DEBUGGING-IMAGE-GENERATION.md`**
   - Step-by-step debugging guide
   - Common issues and solutions
   - Log interpretation guide
   - Advanced debugging techniques

### Modified Files

1. **`includes/class-configuration-builder.php`**
   - Added logging for visual layer processing
   - Tracks image ID retrieval
   - Reports configuration building steps

2. **`includes/class-preset-saver.php`**
   - Uses new image generator wrapper
   - Better error handling
   - Simplified code flow

3. **`includes/class-admin-ui.php`**
   - Uses new image generator wrapper
   - Runs diagnostics before generation
   - Better error reporting

4. **`mkl-pc-preset-bulk-generator.php`**
   - Loads new diagnostic and generator classes

## How to Use

### 1. Enable Debug Logging

Edit `wp-config.php`:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

### 2. Run Preset Generation

Navigate to:
```
http://localhost:10003/product/heavy-duty-workbench/?pc-presets-admin=12570
```

Click "Generate All Presets"

### 3. Check Logs

View the debug log:
```bash
tail -f wp-content/debug.log | grep "Image Generator\|Config Builder\|Diagnostic"
```

### 4. Interpret Results

The logs will show one of these scenarios:

**Scenario A: No Visual Layers**
```
Config Builder: Built configuration with 3 user layers and 0 visual layers
ISSUES: No visual layers found - images might not generate
```
→ Product needs visual layers configured

**Scenario B: Visual Layers Without Images**
```
Config Builder: Built configuration with 3 user layers and 5 visual layers
Config Builder: Choice 123 has no images array
Layers with Images: 0
```
→ Visual layer choices need images assigned

**Scenario C: System Requirements**
```
Image Generator: Requirements check failed: Neither GD nor Imagick extension is available
```
→ Install PHP GD or Imagick extension

**Scenario D: Invalid Attachments**
```
ISSUES: Image ID 123 is not a valid attachment
```
→ Referenced images were deleted, need to be re-assigned

**Scenario E: MKL PC Internal Error**
```
Image Generator: save_image returned false/null
```
→ Check MKL PC plugin configuration and logs

## Next Steps

1. **Run the test** - Generate presets and collect logs
2. **Identify the issue** - Use the debugging guide to interpret logs
3. **Apply the fix** - Follow the solutions in DEBUGGING-IMAGE-GENERATION.md
4. **Verify** - Re-run generation to confirm images are created

## Technical Details

### Architecture Flow

1. **Frontend (JavaScript)**:
   - Applies user selections through configurator UI
   - Calls `PC.fe.save_data.save(false)` to get complete configuration
   - Sends to `mkl_pc_save_expanded_preset` AJAX endpoint

2. **Backend (PHP)**:
   - Receives complete configuration with visual layers
   - Saves preset via `Mkl_PC_Preset_Configuration::save()`
   - Calls `MKL_PC_Preset_Image_Generator::generate_image()`
   - Generator validates requirements, loads content, runs diagnostics
   - Calls MKL PC's `save_image()` method
   - Returns attachment ID or detailed error

### Why This Approach

Instead of trying to fix unknown issues blindly, we:
1. Added comprehensive diagnostics to identify the root cause
2. Validated all requirements before attempting generation
3. Provided clear error messages for each failure point
4. Created a debugging guide for common issues

This allows you to:
- Quickly identify what's wrong
- Apply the specific fix needed
- Verify the solution worked
- Understand the system requirements

## Important Notes

- Image generation doesn't fail the preset save - presets are still created
- The diagnostic output is very detailed - use grep to filter relevant logs
- Test with a small number of presets first (use "Estimate" button)
- Each error message in the generator corresponds to a solution in the guide

## Support

If you still can't resolve the issue after following the debugging guide:
1. Share the diagnostic report from the logs
2. Note any specific error messages
3. Confirm whether manual preset creation (without bulk generator) works and generates images
4. Check if the MKL Product Configurator plugin has any known issues or requirements

The logs will tell you exactly what's happening, making it much easier to get help if needed.
