(function ($, _) {
    "use strict";

    console.log("MKL PC Bulk Generator v1.0.5 loaded");

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

    function enrichConfigurationOrdering(configuration) {
        if (!Array.isArray(configuration) || !PC || !PC.fe || !PC.fe.layers) {
            return configuration;
        }

        configuration.forEach(function (item) {
            if (!item || !item.layer_id) {
                return;
            }

            var layerModel = PC.fe.layers.get(item.layer_id);
            if (layerModel && typeof layerModel.get === "function") {
                var layerOrder = parseInt(layerModel.get("order"), 10);
                var layerImageOrder = parseInt(layerModel.get("image_order"), 10);

                if (!Number.isNaN(layerOrder)) {
                    item.order = layerOrder;
                }

                if (!Number.isNaN(layerImageOrder)) {
                    item.image_order = layerImageOrder;
                } else if (
                    !Number.isNaN(layerOrder) &&
                    (item.image_order === undefined ||
                        item.image_order === null ||
                        item.image_order === "")
                ) {
                    item.image_order = layerOrder;
                }
            }
        });

        return configuration;
    }

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
            // Preload images on first batch only
            if (offset === 0 && totalGenerated === 0) {
                var $container = $(".mkl-pc-bulk-generator");
                var $progress = $container.find(
                    ".mkl-pc-bulk-generator-progress",
                );
                var $progressStatus = $progress.find(".progress-status");

                $progress.show();
                $progressStatus.text(
                    "Preloading images for instant rendering...",
                );

                preloadConfiguratorImages(function () {
                    // Continue with batch generation after preload
                    processBatchAfterPreload(productId, offset, totalGenerated);
                });
                return;
            }

            processBatchAfterPreload(productId, offset, totalGenerated);
        }

        // Process batch after images are preloaded
        function processBatchAfterPreload(productId, offset, totalGenerated) {
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
                            response.data.expanded_configuration &&
                            Array.isArray(response.data.expanded_configuration)
                        ) {
                            response.data.expanded_configuration =
                                enrichConfigurationOrdering(
                                    response.data.expanded_configuration,
                                );
                            applyAndSavePreset(
                                productId,
                                response.data.preset_name || "",
                                response.data.expanded_configuration,
                                function () {
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

        // Preload all configurator images for instant rendering
        function preloadConfiguratorImages(callback) {
            console.log("🖼️ Preloading all configurator images...");
            var imageUrls = [];

            // Collect all choice images from all layers
            if (PC.fe.layers) {
                PC.fe.layers.each(function (layer) {
                    var choices = layer.get("choices");
                    if (choices) {
                        choices.each(function (choice) {
                            // Get the main image
                            var mainImage = choice.get_image();
                            if (mainImage) imageUrls.push(mainImage);

                            // Get thumbnail if different
                            var thumbnail = choice.get_image("thumbnail");
                            if (thumbnail && thumbnail !== mainImage) {
                                imageUrls.push(thumbnail);
                            }

                            // Get angles (different views)
                            var angles = choice.get("angles");
                            if (angles && angles.length) {
                                angles.forEach(function (angle) {
                                    if (angle.image && angle.image.large) {
                                        imageUrls.push(angle.image.large);
                                    }
                                });
                            }
                        });
                    }
                });
            }

            // Remove duplicates
            imageUrls = Array.from(new Set(imageUrls));

            console.log("  Found " + imageUrls.length + " images to preload");

            if (imageUrls.length === 0) {
                callback();
                return;
            }

            // Preload all images
            var loadedCount = 0;
            var totalCount = imageUrls.length;

            imageUrls.forEach(function (url) {
                var img = new Image();
                img.onload = img.onerror = function () {
                    loadedCount++;
                    if (loadedCount === totalCount) {
                        console.log("✓ All images preloaded!");
                        callback();
                    }
                };
                img.src = url;
            });
        }

        function savePresetConfiguration(
            productId,
            presetName,
            configuration,
            callback,
        ) {
            if (!Array.isArray(configuration)) {
                console.warn("No configuration provided. Skipping save.");
                callback();
                return;
            }

            var generatedName =
                presetName && presetName.length
                    ? presetName
                    : generatePresetName(configuration);

            configuration = enrichConfigurationOrdering(configuration);

            $.ajax({
                url: MKL_PC_BulkGenerator.ajax_url,
                type: "POST",
                data: {
                    action: "mkl_pc_save_expanded_preset",
                    nonce: MKL_PC_BulkGenerator.nonce,
                    product_id: productId,
                    preset_name: generatedName,
                    configuration: JSON.stringify(configuration),
                },
                success: function (response) {
                    var presetId = response.success
                        ? response.data && response.data.preset_id
                        : null;

                    if (response.success && presetId) {
                        console.log("✓ Preset saved:", generatedName, "#", presetId);

                        var $presetInput = $('.mkl_pc_admin input[name="new_preset_title"]');
                        if ($presetInput.length) {
                            $presetInput.val(generatedName);
                        }

                        $(document).trigger("mkl_pc_preset_saved", {
                            post_id: presetId,
                            title: generatedName,
                            content: configuration,
                        });

                        setTimeout(callback, 200);
                        return;
                    }

                    var errorMessage = response && response.data && response.data.message
                        ? response.data.message
                        : "Unknown error";

                    if (errorMessage && errorMessage.toLowerCase().indexOf('duplicate') !== -1) {
                        console.log('Preset already exists, skipping:', generatedName);
                        setTimeout(callback, 200);
                        return;
                    }

                    console.warn('✗ Failed to create preset:', errorMessage, response);
                    setTimeout(callback, 200);
                },
                error: function (xhr, status, error) {
                    console.warn("✗ AJAX save failed:", error || status);
                    setTimeout(callback, 200);
                },
            });
        }

        // Generate preset name from complete configuration (matches backend logic)
        function generatePresetName(configArray) {
            var productName = "Heavy Duty Workbench"; // Could get from PC.fe data
            var nameParts = [];

            // Ambiguous choice names that should be prefixed with layer name
            var ambiguousChoices = [
                "Yes",
                "No",
                "Enabled",
                "Disabled",
                "On",
                "Off",
            ];

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
                    // For ambiguous choice names, prefix with layer name
                    if (ambiguousChoices.indexOf(layer.name) !== -1) {
                        nameParts.push(layer.layer_name + ": " + layer.name);
                    } else {
                        // For descriptive choices (like "4 Drawer Unit"), just use the choice name
                        nameParts.push(layer.name);
                    }
                }
            });

            var name = productName + " - " + nameParts.join(" - ");

            // Truncate if too long
            if (name.length > 200) {
                name = name.substring(0, 197) + "...";
            }

            return name;
        }

        function applyAndSavePreset(
            productId,
            presetName,
            expandedConfiguration,
            callback,
        ) {
            if (
                !expandedConfiguration ||
                !Array.isArray(expandedConfiguration) ||
                !expandedConfiguration.length
            ) {
                savePresetConfiguration(
                    productId,
                    presetName,
                    expandedConfiguration,
                    callback,
                );
                return;
            }

            expandedConfiguration = enrichConfigurationOrdering(
                expandedConfiguration,
            );

            if (!PC || !PC.fe || typeof PC.fe.setConfig !== "function") {
                console.warn(
                    "Configurator not ready, saving configuration directly.",
                );
                savePresetConfiguration(
                    productId,
                    presetName,
                    expandedConfiguration,
                    callback,
                );
                return;
            }

            var userSelections = expandedConfiguration.filter(function (item) {
                return (
                    item &&
                    item.is_choice &&
                    item.layer_id &&
                    item.choice_id &&
                    (!item.layer_name ||
                        item.layer_name.indexOf("Visual -") !== 0)
                );
            });

            userSelections.sort(function (a, b) {
                function resolveOrder(entry) {
                    if (
                        typeof entry.image_order !== "undefined" &&
                        entry.image_order !== null &&
                        entry.image_order !== ""
                    ) {
                        return parseInt(entry.image_order, 10);
                    }
                    if (
                        typeof entry.order !== "undefined" &&
                        entry.order !== null &&
                        entry.order !== ""
                    ) {
                        return parseInt(entry.order, 10);
                    }
                    return parseInt(entry.layer_id, 10) || 0;
                }

                var orderA = resolveOrder(a);
                var orderB = resolveOrder(b);

                if (orderA === orderB) {
                    if (a.layer_id === b.layer_id) {
                        return parseInt(a.choice_id, 10) - parseInt(b.choice_id, 10);
                    }
                    return parseInt(a.layer_id, 10) - parseInt(b.layer_id, 10);
                }
                return orderA - orderB;
            });

            if (!userSelections.length) {
                savePresetConfiguration(
                    productId,
                    presetName,
                    expandedConfiguration,
                    callback,
                );
                return;
            }

            var configItems = userSelections.map(function (item) {
                return {
                    layer_id: item.layer_id,
                    choice_id: item.choice_id,
                };
            });

            PC.fe.setConfig(configItems);

            var queue = userSelections.slice();

            function openLayer(layer, onReady, attempt) {
                attempt = attempt || 0;

                if (!layer) {
                    onReady();
                    return;
                }

                if (!layer.get("active")) {
                    var layerSelector =
                        '.layers-list-item[data-layer="' +
                        layer.id +
                        '"] > .layer-item';
                    var $layerHeader = $(layerSelector);

                    if ($layerHeader.length) {
                        $layerHeader.trigger("click");
                    }

                    layer.set("active", true);
                }

                if (layer.get("active")) {
                    setTimeout(onReady, 120);
                    return;
                }

                if (attempt >= 10) {
                    layer.set("active", true);
                    setTimeout(onReady, 120);
                    return;
                }

                setTimeout(function () {
                    openLayer(layer, onReady, attempt + 1);
                }, 80);
            }

            function waitForChoice(model, onReady, attempt) {
                attempt = attempt || 0;

                if (!model || typeof model.get !== "function") {
                    setTimeout(onReady, 120);
                    return;
                }

                if (model.get("active")) {
                    setTimeout(onReady, 100);
                    return;
                }

                if (attempt >= 20) {
                    console.warn("Choice did not activate in time", model.id);
                    setTimeout(onReady, 100);
                    return;
                }

                setTimeout(function () {
                    waitForChoice(model, onReady, attempt + 1);
                }, 80);
            }

            function processNext() {
                if (!queue.length) {
                    setTimeout(function () {
                        var finalConfig = JSON.parse(
                            PC.fe.save_data.save(false),
                        );
                        finalConfig = enrichConfigurationOrdering(finalConfig);
                        savePresetConfiguration(
                            productId,
                            presetName,
                            finalConfig,
                            callback,
                        );
                    }, 150);
                    return;
                }

                var item = queue.shift();
                var layer = PC.fe.layers.get(item.layer_id);

                openLayer(layer, function () {
                    var collection = PC.fe.getLayerContent(item.layer_id);
                    var model =
                        collection && typeof collection.get === "function"
                            ? collection.get(item.choice_id)
                            : null;

                    var $button = $(
                        "#choice_" + item.layer_id + "_" + item.choice_id,
                    );
                    var view =
                        $button.length &&
                        $button.closest(".choice").data("view")
                            ? $button.closest(".choice").data("view")
                            : null;

                    if (view && typeof view.set_choice === "function") {
                        view.set_choice({
                            type: "mousedown",
                            button: 0,
                            currentTarget: $button[0],
                            preventDefault: function () {},
                        });
                    } else if (
                        collection &&
                        typeof collection.selectChoice === "function"
                    ) {
                        collection.selectChoice(item.choice_id, true);
                    }

                    waitForChoice(model, processNext);
                });
            }

            processNext();
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
