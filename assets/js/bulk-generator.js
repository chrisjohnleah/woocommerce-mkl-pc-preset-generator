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
                        if (
                            response.data.valid_combination && PC && PC.fe &&
                            PC.fe.save_data
                        ) {
                            expandAndSavePreset(
                                productId,
                                response.data.valid_combination,
                                null, // Name will be generated from expanded config
                                function () {
                                    // After saving, continue with next batch
                                    if (!response.data.is_complete) {
                                        processBatch(
                                            productId,
                                            response.data.offset,
                                            totalGenerated,
                                        );
                                    } else {
                                        finishGeneration(
                                            totalGenerated,
                                            response.data.message,
                                        );
                                    }
                                },
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
                            finishGeneration(
                                totalGenerated,
                                response.data.message,
                            );
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

        // Simulate human interaction: CLICK each choice, then CLICK save
        function expandAndSavePreset(
            productId,
            combination,
            presetName,
            callback,
        ) {
            console.log("Clicking choices to configure preset:", combination);

            // Click each choice in sequence (like a human would)
            var currentIndex = 0;

            function clickNextChoice() {
                if (currentIndex >= combination.length) {
                    // All choices clicked, now save the preset
                    saveCurrentConfiguration(callback);
                    return;
                }

                var choice = combination[currentIndex];
                currentIndex++;

                // Skip null/None choices
                if (!choice.choice_id || choice.choice_name === "None") {
                    clickNextChoice();
                    return;
                }

                console.log(
                    "Clicking:",
                    choice.layer_name,
                    "=",
                    choice.choice_name,
                );

                // Find the ACTUAL DOM element for this choice and click it
                var selector = '.choice[data-choice-id="' + choice.choice_id + '"][data-layer-id="' + choice.layer_id + '"]';
                var $choiceElement = $(selector);
                
                if ($choiceElement.length) {
                    console.log("  Found DOM element, clicking it...");
                    // Actually click the DOM element (triggers full visual update pipeline)
                    $choiceElement.trigger('click');
                    
                    // Wait for rendering before next choice
                    setTimeout(clickNextChoice, 200);
                } else {
                    // Fallback: Try programmatic selection
                    console.warn("  DOM element not found, using selectChoice() fallback");
                    var layerContent = PC.fe.getLayerContent(choice.layer_id);
                    if (layerContent && layerContent.selectChoice) {
                        layerContent.selectChoice(choice.choice_id, true);
                        setTimeout(clickNextChoice, 200);
                    } else {
                        console.warn("Could not select:", choice.layer_name);
                        clickNextChoice();
                    }
                }
            }

            function saveCurrentConfiguration(finalCallback) {
                // Wait for final rendering to complete
                setTimeout(function () {
                    console.log("All choices clicked, now saving preset...");

                    // Get complete configuration from the now-rendered configurator
                    var completeConfig = PC.fe.save_data.save();
                    var configArray = JSON.parse(completeConfig);

                    // Generate unique name from all selected options
                    var generatedName = generatePresetName(configArray);
                    console.log("Generated preset name:", generatedName);

                    // Fill in the preset name field
                    var $presetInput = $(
                        '.mkl_pc_admin input[name="new_preset_title"]',
                    );
                    $presetInput.val(generatedName);

                    // Create mock element for the save workflow
                    var mockElement = {
                        $el: $("<div>"),
                        $input: $("<input>").val(generatedName),
                    };

                    // Listen for save completion
                    mockElement.$el.one("saved", function (event, response) {
                        if (response && response.saved) {
                            console.log("✓ Preset saved:", generatedName);

                            // Trigger image generation (screenshot of configurator)
                            if (
                                response.save_image_async &&
                                PC.fe.saveYourDesign
                            ) {
                                console.log("Generating screenshot...");
                                PC.fe.saveYourDesign.saveImage(
                                    response.save_image_async,
                                );
                                setTimeout(finalCallback, 1500); // Wait for image generation
                            } else {
                                finalCallback();
                            }
                        } else {
                            console.warn("✗ Save failed:", response);
                            finalCallback();
                        }
                    });

                    // Save the preset (like clicking the Save button)
                    PC.fe.saveYourDesign.saveDesign(mockElement, "preset");
                }, 500); // Final delay for last layer to render
            }

            // Start clicking choices
            clickNextChoice();
        }

        // Generate preset name from complete configuration (matches backend logic)
        function generatePresetName(configArray) {
            var productName = "Heavy Duty Workbench"; // Could get from PC.fe data
            var nameParts = [];

            // Extract non-"None" user choices (exclude visual/group layers)
            configArray.forEach(function (layer) {
                // Only include user-selectable choices that aren't "None", "No", or empty
                if (
                    layer.is_choice &&
                    layer.name &&
                    layer.name !== "None" &&
                    layer.name !== "No" &&
                    layer.name !== "" &&
                    !layer.layer_name.startsWith("Visual -")
                ) {
                    nameParts.push(layer.name);
                }
            });

            var name = productName + " - " + nameParts.join(" - ");

            // Truncate if too long
            if (name.length > 200) {
                name = name.substring(0, 197) + "...";
            }

            return name;
        }

        function finishGeneration(totalGenerated, message) {
            var $container = $(".mkl-pc-bulk-generator");
            var $estimateBtn = $container.find(".mkl-pc-estimate-btn");
            var $generateBtn = $container.find(".mkl-pc-generate-btn");
            var $deleteBtn = $container.find(".mkl-pc-delete-all-btn");
            var $progress = $container.find(".mkl-pc-bulk-generator-progress");
            var $progressBar = $progress.find(".progress-bar");
            var $progressStatus = $progress.find(".progress-status");

            var completionMessage = MKL_PC_BulkGenerator.strings.complete +
                " (" +
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
