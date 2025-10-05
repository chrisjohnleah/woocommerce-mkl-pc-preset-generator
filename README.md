# Product Configurator - Bulk Preset Generator

Automatically generate all valid configuration presets for WooCommerce Product
Configurator products based on conditional logic rules.

## Description

This plugin extends the Product Configurator for WooCommerce by adding a bulk
preset generation feature. Instead of manually creating presets one by one, this
plugin can automatically generate all valid combinations of product options
while respecting your conditional logic rules.

## Features

- **Automatic Combination Generation**: Generates all possible combinations of
  product configuration options
- **Conditional Logic Validation**: Only creates presets for valid combinations
  based on your conditional logic rules
- **Batch Processing**: Processes large numbers of combinations in manageable
  batches to avoid timeouts
- **Progress Tracking**: Real-time progress bar showing generation status
- **Estimation Tool**: Estimate how many combinations will be generated before
  running
- **Bulk Delete**: Remove all presets for a product with one click
- **User-Friendly Interface**: Seamlessly integrated into the existing preset
  admin page

## Requirements

- WordPress 5.0 or higher
- WooCommerce 3.0 or higher
- Product Configurator for WooCommerce
- Product Configurator - Save Your Design extension
- Product Configurator - Conditional Logic extension

## Installation

1. Upload the plugin files to
   `/wp-content/plugins/mkl-pc-preset-bulk-generator/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to any configurable product's preset admin page
4. You'll see the new "Bulk Preset Generator" panel at the top

## Usage

### Generating Presets

1. Go to a configurable product in WooCommerce
2. Click the "Add configuration preset" link (or navigate to the preset admin
   page)
3. At the top of the page, you'll see the Bulk Preset Generator panel
4. Click "Estimate Combinations" to see how many presets will be generated
5. Review the estimate and click "Generate All Presets" to begin
6. Wait for the process to complete - progress will be shown in real-time

### Deleting All Presets

1. On the same preset admin page, click "Delete All Presets"
2. Confirm the action
3. All presets for that product will be permanently deleted

## How It Works

The plugin works in several stages:

1. **Layer Analysis**: Identifies all user-facing layers and choices (excluding
   visual/hidden layers)
2. **Combination Generation**: Creates a cartesian product of all possible
   option combinations
3. **Conditional Validation**: Each combination is tested against your
   conditional logic rules
4. **Preset Creation**: Valid combinations are saved as presets with descriptive
   names

## Performance Considerations

- Large numbers of combinations (1000+) may take several minutes to generate
- The plugin uses batch processing to avoid PHP timeout errors
- Each batch processes 50 combinations at a time
- Consider running during off-peak hours for products with many options

## Conditional Logic Support

The validator respects the following conditional logic rules:

- **Selection Rules**: `selected`, `not_selected`
- **Visibility Rules**: `visible`, `hidden`
- **Actions**: `hide`, `show`, `disable`, `enable`
- **Relationships**: `AND`, `OR`

Combinations that would result in hidden or disabled selected choices are
automatically filtered out.

## Naming Convention

Presets are automatically named using the selected choice names, separated by
" - ". For example:

- `1200 × 750 × 840mm - Blue - Steel - None - None`

## Support

For support, please contact Digital Services Northwest.

## Changelog

### 1.0.0

- Initial release
- Automatic combination generation
- Conditional logic validation
- Batch processing
- Progress tracking
- Estimation tool
- Bulk delete functionality

## Author

Digital Services Northwest\
https://digitalservicesnorthwest.co.uk

## Licence

This plugin is proprietary software. All rights reserved.
