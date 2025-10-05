(function ($, _) {
    "use strict";

    // Wait for PC to be ready
    wp.hooks.addAction("PC.fe.start", "MKL/PC/BulkGenerator", function (view) {
        // Check if we're on the preset admin page
        if (!$(".mkl_pc_admin").length) {
            return;
        }

        // Add the bulk generator UI
        var template = wp.template("mkl-pc-bulk-generator-ui");
        $(".mkl_pc_admin").prepend(template());

        // Initialize handlers
        initHandlers();
    }, 60);

    function initHandlers() {
        var $container = $(".mkl-pc-bulk-generator");
        var $estimateBtn = $container.find(".mkl-pc-estimate-btn");
        var $generateBtn = $container.find(".mkl-pc-generate-btn");
        var $deleteBtn = $container.find(".mkl-pc-delete-all-btn");
        var $progress = $container.find(".mkl-pc-bulk-generator-progress");
        var $progressBar = $progress.find(".progress-bar");
        var $progressStatus = $progress.find(".progress-status");

        // Estimate button
        $estimateBtn.on("click", function () {
            var productId = $(this).data("product-id");

            $estimateBtn.prop("disabled", true).text(
                "Counting... Please wait",
            );

            $.ajax({
                url: MKL_PC_BulkGenerator.ajax_url,
                type: "POST",
                data: {
                    action: "mkl_pc_generate_presets_estimate",
                    nonce: MKL_PC_BulkGenerator.nonce,
                    product_id: productId,
                },
                success: function (response) {
                    if (response.success) {
                        var validCount = response.data.valid_count || 0;
                        var totalChecked = response.data.total_checked || 0;

                        // Update stats with actual valid count
                        $container.find('[data-stat="estimated"]').text(
                            validCount.toLocaleString() + " valid",
                        );
                        $container.find('[data-stat="existing"]').text(
                            response.data.existing.toLocaleString(),
                        );

                        // Enable generate button
                        $generateBtn.prop("disabled", false);

                        // Show message
                        alert(response.data.message);
                    } else {
                        alert(
                            MKL_PC_BulkGenerator.strings.error + ": " +
                                response.data.message,
                        );
                    }
                },
                error: function () {
                    alert(MKL_PC_BulkGenerator.strings.error);
                },
                complete: function () {
                    $estimateBtn.prop("disabled", false).text(
                        "Estimate Combinations",
                    );
                },
            });
        });

        // Generate button
        $generateBtn.on("click", function () {
            var productId = $(this).data("product-id");

            // Confirm before proceeding
            if (!confirm("Start generating all valid preset combinations?")) {
                return;
            }

            // Disable buttons
            $estimateBtn.prop("disabled", true);
            $generateBtn.prop("disabled", true);
            $deleteBtn.prop("disabled", true);

            // Show progress
            $progress.addClass("active");
            $progressBar.css("width", "0%").text("0%");
            $progressStatus.text(MKL_PC_BulkGenerator.strings.generating);

            // Reset generated count
            $container.find('[data-stat="generated"]').text("0");

            // Start batch processing
            processBatch(productId, 0, 0);
        });

        // Delete all button
        $deleteBtn.on("click", function () {
            var productId = $(this).data("product-id");

            // Confirm deletion
            if (!confirm(MKL_PC_BulkGenerator.strings.confirm_delete)) {
                return;
            }

            $deleteBtn.prop("disabled", true);

            $.ajax({
                url: MKL_PC_BulkGenerator.ajax_url,
                type: "POST",
                data: {
                    action: "mkl_pc_delete_all_presets",
                    nonce: MKL_PC_BulkGenerator.nonce,
                    product_id: productId,
                },
                success: function (response) {
                    if (response.success) {
                        alert(
                            response.data.deleted +
                                " presets deleted successfully.",
                        );

                        // Update existing count
                        $container.find('[data-stat="existing"]').text("0");
                        $container.find('[data-stat="generated"]').text("0");

                        // Reload configurations list
                        if (window.PC_Presets_Configurations) {
                            window.PC_Presets_Configurations.reset();
                        }
                    } else {
                        alert(
                            MKL_PC_BulkGenerator.strings.error + ": " +
                                response.data.message,
                        );
                    }
                },
                error: function () {
                    alert(MKL_PC_BulkGenerator.strings.error);
                },
                complete: function () {
                    $deleteBtn.prop("disabled", false);
                },
            });
        });

        // Process batch function
        function processBatch(productId, offset, totalGenerated) {
            $.ajax({
                url: MKL_PC_BulkGenerator.ajax_url,
                type: "POST",
                data: {
                    action: "mkl_pc_generate_presets_batch",
                    nonce: MKL_PC_BulkGenerator.nonce,
                    product_id: productId,
                    offset: offset,
                    batch_size: 50,
                    total_generated: totalGenerated,
                },
                success: function (response) {
                    if (response.success) {
                        // Use server's count (more accurate than client-side addition)
                        totalGenerated = response.data.total_generated ||
                            totalGenerated;

                        // Update progress
                        var progress = response.data.progress || 0;
                        var safetyLimit = response.data.safety_limit || 500;

                        $progressBar.css("width", progress + "%").text(
                            progress + "%",
                        );
                        $progressStatus.text(
                            "Generated " + totalGenerated + " / " +
                                safetyLimit + " valid presets (+" +
                                response.data.saved + " saved, " +
                                response.data.skipped + " skipped this batch)",
                        );

                        // Update generated count
                        $container.find('[data-stat="generated"]').text(
                            totalGenerated.toLocaleString(),
                        );

                        // HYBRID APPROACH: If backend sent a valid combination, expand it using PC.fe
                        if (response.data.valid_combination && PC && PC.fe && PC.fe.save_data) {
                            expandAndSavePreset(
                                productId,
                                response.data.valid_combination,
                                response.data.preset_name,
                                function() {
                                    // After saving, continue with next batch
                                    if (!response.data.is_complete) {
                                        processBatch(
                                            productId,
                                            response.data.offset,
                                            totalGenerated,
                                        );
                                    } else {
                                        finishGeneration(totalGenerated, response.data.message);
                                    }
                                }
                            );
                        } else if (!response.data.is_complete) {
                            // No valid combination in this batch, continue
                            processBatch(
                                productId,
                                response.data.offset,
                                totalGenerated,
                            );
                        } else {
                            // Complete!
                            finishGeneration(totalGenerated, response.data.message);
                        }
                    } else {
                        alert(
                            MKL_PC_BulkGenerator.strings.error + ": " +
                                response.data.message,
                        );
                        $estimateBtn.prop("disabled", false);
                        $generateBtn.prop("disabled", false);
                        $deleteBtn.prop("disabled", false);
                    }
                },
                error: function (xhr, status, error) {
                    alert(MKL_PC_BulkGenerator.strings.error + ": " + error);
                    $estimateBtn.prop("disabled", false);
                    $generateBtn.prop("disabled", false);
                    $deleteBtn.prop("disabled", false);
                },
            });
        }

        // Expand combination using PC.fe and save it
        function expandAndSavePreset(productId, combination, presetName, callback) {
            console.log('Expanding combination:', combination);
            
            // Apply combination to configurator (this triggers visual layer updates)
            PC.fe.setConfig(combination);
            
            // Wait a moment for conditional logic to process
            setTimeout(function() {
                // Get complete configuration from PC.fe (includes all visual layers)
                var completeConfig = PC.fe.save_data.save();
                
                console.log('Expanded configuration:', completeConfig);
                
                // Send to backend for saving
                $.ajax({
                    url: MKL_PC_BulkGenerator.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'mkl_pc_save_expanded_preset',
                        nonce: MKL_PC_BulkGenerator.nonce,
                        product_id: productId,
                        preset_name: presetName,
                        configuration: completeConfig
                    },
                    success: function(saveResponse) {
                        if (saveResponse.success) {
                            console.log('Saved preset #' + saveResponse.data.preset_id);
                            callback();
                        } else {
                            console.error('Failed to save preset:', saveResponse.data.message);
                            callback();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error saving preset:', error);
                        callback();
                    }
                });
            }, 100); // 100ms delay for conditional logic to process
        }

        function finishGeneration(totalGenerated, message) {
            var $container = $(".mkl-pc-bulk-generator");
            var $estimateBtn = $container.find(".mkl-pc-estimate-btn");
            var $generateBtn = $container.find(".mkl-pc-generate-btn");
            var $deleteBtn = $container.find(".mkl-pc-delete-all-btn");
            var $progress = $container.find(".mkl-pc-bulk-generator-progress");
            var $progressBar = $progress.find(".progress-bar");
            var $progressStatus = $progress.find(".progress-status");

            var completionMessage =
                MKL_PC_BulkGenerator.strings.complete + " (" +
                totalGenerated + " valid presets saved)";

            if (message) {
                completionMessage += " - " + message;
            }

            $progressStatus.text(completionMessage);
            $progressBar.css("width", "100%").text("100%");

            // Re-enable buttons
            $estimateBtn.prop("disabled", false);
            $generateBtn.prop("disabled", false);
            $deleteBtn.prop("disabled", false);

            // Update existing count
            $container.find('[data-stat="existing"]').text(
                totalGenerated.toLocaleString(),
            );

            // Reload configurations list
            if (window.PC_Presets_Configurations) {
                // Fetch fresh data
                $.ajax({
                    url: ajaxurl,
                    type: "GET",
                    data: {
                        action: "mkl_pc_get_content",
                        data: "configurations",
                        id: productId,
                        status: "preset",
                    },
                    success: function (configs) {
                        if (configs && Array.isArray(configs)) {
                            window.PC_Presets_Configurations
                                .reset(configs);
                        }
                    },
                });
            }
        }
    }
})(jQuery, _);
