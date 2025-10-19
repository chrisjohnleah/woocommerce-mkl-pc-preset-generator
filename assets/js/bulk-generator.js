(function ($, _) {
    "use strict";

    console.log("MKL PC Bulk Generator v1.0.6 loaded");
    var runMetrics = null;
    var currentRun = null;
    var statsUpdateScheduled = false;
    var statsTicker = null;
    var knownPresetTitles = new Set();

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
        var $stopBtn = $container.find(".mkl-pc-stop-btn");
        var $status = $container.find('[data-live="status"]');
        var $elapsed = $container.find('[data-live="elapsed"]');
        var $rate = $container.find('[data-live="rate"]');
        var $avgApply = $container.find('[data-live="avg-apply"]');
        var $avgSave = $container.find('[data-live="avg-save"]');
        var $asyncThumbs = $container.find('[data-live="async-thumbs"]');
        var $skipped = $container.find('[data-live="skipped-duplicates"]');
        var $logList = $container.find('[data-live-log]');
        var defaultGenerateLabel = $generateBtn.text();
        var defaultEstimateLabel = $estimateBtn.text();
        var defaultStopLabel = $stopBtn.length ? $stopBtn.text() : "Stop";
        var defaultStatusText = MKL_PC_BulkGenerator.strings.ready ||
            "Ready to start.";
        var emptyLogMessage = $.trim($logList.text()) ||
            MKL_PC_BulkGenerator.strings.log_empty ||
            "Activity will appear here once generation starts.";
        var logEntries = [];
        var canGenerateAfterRun = !$generateBtn.prop("disabled");
        var canEstimateAfterRun = !$estimateBtn.prop("disabled");
        var canDeleteAfterRun = !$deleteBtn.prop("disabled");
        var raf = window.requestAnimationFrame
            ? window.requestAnimationFrame.bind(window)
            : function (cb) {
                return setTimeout(cb, 60);
            };

        collectExistingPresetTitles();
        resetLiveView();
        renderLog();
        updateLiveStats();
        $stopBtn.prop("disabled", true).text(defaultStopLabel);

        function average(values) {
            if (!values || !values.length) {
                return 0;
            }
            var total = values.reduce(function (sum, value) {
                return sum + value;
            }, 0);
            return total / values.length;
        }

        function collectExistingPresetTitles() {
            var titles = new Set();
            try {
                if (
                    window.PC_Presets_Configurations &&
                    typeof window.PC_Presets_Configurations.each === "function"
                ) {
                    window.PC_Presets_Configurations.each(function (model) {
                        var title = model && typeof model.get === "function"
                            ? model.get("title")
                            : null;
                        if (title) {
                            titles.add(String(title).trim().toLowerCase());
                        }
                    });
                    knownPresetTitles = titles;
                }
            } catch (err) {
                console.warn("Bulk generator: failed to collect preset titles", err);
            }
        }

        function formatDuration(ms) {
            if (!ms || ms < 0) {
                return "0:00";
            }
            var totalSeconds = Math.floor(ms / 1000);
            var minutes = Math.floor(totalSeconds / 60);
            var seconds = totalSeconds % 60;
            return minutes + ":" + (seconds < 10 ? "0" + seconds : seconds);
        }

        function renderLog() {
            if (!$logList.length) {
                return;
            }

            if (!logEntries.length) {
                $logList.html(
                    '<li class="log-entry log-entry--info"><span>' +
                        _.escape(emptyLogMessage) +
                        "</span></li>",
                );
                return;
            }

            var html = logEntries
                .map(function (entry) {
                    var timeText = entry.timeText ||
                        (entry.timestamp instanceof Date
                            ? entry.timestamp.toLocaleTimeString()
                            : entry.timestamp);
                    return (
                        '<li class="log-entry log-entry--' + entry.tone + '">' +
                        "<span>" + _.escape(entry.message) + "</span>" +
                        '<span class="timestamp">' + _.escape(timeText) + "</span>" +
                        "</li>"
                    );
                })
                .join("");

            $logList.html(html);
            if ($logList[0] && $logList[0].scrollHeight > $logList[0].clientHeight) {
                $logList[0].scrollTop = $logList[0].scrollHeight;
            }
        }

        function appendLog(message, tone, options) {
            if (!$logList.length) {
                return;
            }
            options = options || {};

            if (
                !options.force &&
                runMetrics &&
                runMetrics.totalPresets > 5 &&
                runMetrics.totalPresets % 10 !== 0
            ) {
                return;
            }

            var entry = {
                message: message,
                tone: tone || "info",
                timestamp: new Date(),
            };
            entry.timeText = entry.timestamp.toLocaleTimeString();

            logEntries.push(entry);
            if (logEntries.length > 12) {
                logEntries.shift();
            }
            renderLog();
        }

        function setStatus(text, tone) {
            if (!$status.length) {
                return;
            }
            tone = tone || "info";
            $status
                .removeClass("status--info status--success status--warn status--error")
                .addClass("status--" + tone)
                .text(text);
        }

        function resetLiveView() {
            setStatus(defaultStatusText, "info");
            logEntries = [];
            renderLog();
            $elapsed.text("0:00");
            $rate.text("0");
            $avgApply.text("-");
            $avgSave.text("-");
            $asyncThumbs.text("0");
            if ($skipped.length) {
                $skipped.text("0");
            }
        }

        function captureButtonState() {
            canGenerateAfterRun = !$generateBtn.prop("disabled");
            canEstimateAfterRun = !$estimateBtn.prop("disabled");
            canDeleteAfterRun = !$deleteBtn.prop("disabled");
        }

        function setRunningState(isRunning) {
            if (isRunning) {
                captureButtonState();
                $estimateBtn.prop("disabled", true);
                $generateBtn
                    .prop("disabled", true)
                    .text(MKL_PC_BulkGenerator.strings.generating || "Generating...");
                $deleteBtn.prop("disabled", true);
                if ($stopBtn.length) {
                    $stopBtn.prop("disabled", false).text(
                        defaultStopLabel || "Stop Run",
                    );
                }
            } else {
                $estimateBtn.prop("disabled", !canEstimateAfterRun);
                $generateBtn
                    .prop("disabled", !canGenerateAfterRun)
                    .text(defaultGenerateLabel);
                $deleteBtn.prop("disabled", !canDeleteAfterRun);
                if ($stopBtn.length) {
                    $stopBtn.prop("disabled", true).text(defaultStopLabel || "Stop Run");
                }
            }
            scheduleStatsUpdate();
        }

        function scheduleStatsUpdate() {
            if (statsUpdateScheduled) {
                return;
            }

            statsUpdateScheduled = true;
            raf(function () {
                statsUpdateScheduled = false;
                updateLiveStats();
            });
        }

        function updateLiveStats() {
            if (!runMetrics) {
                $elapsed.text("0:00");
                $rate.text("0");
                $avgApply.text("-");
                $avgSave.text("-");
                $asyncThumbs.text("0");
                if ($skipped.length) {
                    $skipped.text("0");
                }
                return;
            }

            var now = typeof performance !== "undefined" && performance.now
                ? performance.now()
                : Date.now();

            var elapsedMs = Math.max(0, now - runMetrics.startedAt);
            var perMinute = elapsedMs > 0
                ? runMetrics.totalPresets / (elapsedMs / 60000)
                : 0;

            $elapsed.text(formatDuration(elapsedMs));
            $rate.text(perMinute > 0 ? perMinute.toFixed(1) : "0");
            $avgApply.text(
                runMetrics.applyDurations.length
                    ? Math.round(average(runMetrics.applyDurations)) + " ms"
                    : "-",
            );
            $avgSave.text(
                runMetrics.saveDurations.length
                    ? Math.round(average(runMetrics.saveDurations)) + " ms"
                    : "-",
            );
            $asyncThumbs.text(
                runMetrics.asyncThumbnails
                    ? runMetrics.asyncThumbnails.toString()
                    : "0",
            );
            if ($skipped.length) {
                $skipped.text(
                    runMetrics.skippedDuplicates
                        ? runMetrics.skippedDuplicates.toString()
                        : "0",
                );
            }
        }

        function cancelRun() {
            if (!currentRun || currentRun.cancelled) {
                return;
            }
            currentRun.cancelled = true;
            setStatus(
                MKL_PC_BulkGenerator.strings.cancelling || "Cancelling...",
                "warn",
            );
            appendLog(
                "Cancellation requested. Finishing current preset...",
                "warn",
                { force: true },
            );
            if ($stopBtn.length) {
                $stopBtn
                    .prop("disabled", true)
                    .text(MKL_PC_BulkGenerator.strings.cancelling || "Cancelling...");
            }
            scheduleStatsUpdate();
        }

        function isRunCancelled() {
            return !!(currentRun && currentRun.cancelled);
        }

        function markRunFinished() {
            if (statsTicker) {
                clearInterval(statsTicker);
                statsTicker = null;
            }
            if (currentRun) {
                currentRun.finished = true;
            }
            scheduleStatsUpdate();
        }

        function startRun(productId) {
            if (!productId) {
                return;
            }

            if (currentRun && !currentRun.finished && !currentRun.cancelled) {
                return;
            }

            collectExistingPresetTitles();
            resetLiveView();
            setRunningState(true);

            var generatingLabel = MKL_PC_BulkGenerator.strings.generating ||
                "Generating presets...";
            setStatus(generatingLabel, "info");
            appendLog(
                "Generation started for product #" + productId + ".",
                "info",
                { force: true },
            );

            var now = typeof performance !== "undefined" && performance.now
                ? performance.now()
                : Date.now();

            runMetrics = window.MKL_PC_BulkMetrics = {
                startedAt: now,
                preloads: [],
                batches: [],
                applyDurations: [],
                saveDurations: [],
                totalPresets: 0,
                asyncThumbnails: 0,
                skippedDuplicates: 0,
            };

            currentRun = {
                productId: productId,
                cancelled: false,
                finished: false,
            };

            if (statsTicker) {
                clearInterval(statsTicker);
            }
            statsTicker = setInterval(function () {
                if (runMetrics && (!currentRun || !currentRun.finished)) {
                    updateLiveStats();
                }
            }, 1000);

            $progress.addClass("active");
            $progressBar.css("width", "0%").text("0%");
            $progressStatus.text(generatingLabel);
            $container.find('[data-stat="generated"]').text("0");

            scheduleStatsUpdate();
            processBatch(productId, 0, 0);
        }

        if ($stopBtn.length) {
            $stopBtn.on("click", cancelRun);
        }

        // Estimate button
        $estimateBtn.on("click", function () {
            var productId = $(this).data("product-id");
            if (!productId) {
                return;
            }

            setStatus(
                MKL_PC_BulkGenerator.strings.estimating ||
                    "Estimating combinations...",
                "info",
            );
            appendLog(
                "Estimating valid combinations...",
                "info",
                { force: true },
            );

            $estimateBtn
                .prop("disabled", true)
                .text(
                    MKL_PC_BulkGenerator.strings.estimating ||
                        "Estimating...",
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

                        $container.find('[data-stat="estimated"]').text(
                            validCount.toLocaleString() + " valid",
                        );
                        $container.find('[data-stat="existing"]').text(
                            response.data.existing.toLocaleString(),
                        );

                        $generateBtn.prop("disabled", false);
                        canGenerateAfterRun = true;

                        var successMessage = response.data.message ||
                            "Estimate complete.";
                        setStatus(successMessage, "success");
                        appendLog(
                            "Estimate complete: " +
                                validCount.toLocaleString() +
                                " valid presets.",
                            "success",
                            { force: true },
                        );
                        scheduleStatsUpdate();

                        if (response.data.message) {
                            alert(response.data.message);
                        }
                    } else {
                        var errorMessage = response.data &&
                                response.data.message
                            ? response.data.message
                            : MKL_PC_BulkGenerator.strings.error;
                        setStatus(
                            (MKL_PC_BulkGenerator.strings.error || "Error") +
                                ": " +
                                errorMessage,
                            "error",
                        );
                        appendLog(
                            "Estimate failed: " + errorMessage,
                            "error",
                            { force: true },
                        );
                        alert(
                            (MKL_PC_BulkGenerator.strings.error || "Error") +
                                ": " +
                                errorMessage,
                        );
                    }
                },
                error: function () {
                    var errorText = MKL_PC_BulkGenerator.strings.error ||
                        "An error occurred";
                    setStatus(errorText, "error");
                    appendLog(
                        "Estimate failed: " + errorText,
                        "error",
                        { force: true },
                    );
                    alert(errorText);
                },
                complete: function () {
                    $estimateBtn
                        .prop("disabled", false)
                        .text(defaultEstimateLabel);
                    scheduleStatsUpdate();
                },
            });
        });

        // Generate button
        $generateBtn.on("click", function () {
            var productId = $(this).data("product-id");
            if (!productId) {
                return;
            }

            if (
                !confirm(
                    MKL_PC_BulkGenerator.strings.confirm_start ||
                        "Start generating all valid preset combinations?",
                )
            ) {
                return;
            }

            startRun(productId);
        });

        // Delete all button
        $deleteBtn.on("click", function () {
            var productId = $(this).data("product-id");

            // Confirm deletion
            if (!confirm(MKL_PC_BulkGenerator.strings.confirm_delete)) {
                return;
            }

            $deleteBtn.prop("disabled", true);
            setStatus(
                MKL_PC_BulkGenerator.strings.deleting || "Deleting presets...",
                "warn",
            );
            appendLog(
                "Deleting all presets for product #" + productId + "...",
                "warn",
                { force: true },
            );

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
                        appendLog(
                            response.data.deleted +
                                " presets deleted successfully.",
                            "success",
                            { force: true },
                        );
                        setStatus(
                            MKL_PC_BulkGenerator.strings.deleted ||
                                "Presets deleted.",
                            "success",
                        );

                        // Reload configurations list
                        if (window.PC_Presets_Configurations) {
                            window.PC_Presets_Configurations.reset();
                        }
                        collectExistingPresetTitles();
                    } else {
                        setStatus(
                            MKL_PC_BulkGenerator.strings.error + ": " +
                                response.data.message,
                            "error",
                        );
                        appendLog(
                            "Delete failed: " + response.data.message,
                            "error",
                            { force: true },
                        );
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
                    scheduleStatsUpdate();
                },
            });
        });

        // Process batch function
        function processBatch(productId, offset, totalGenerated) {
            if (isRunCancelled()) {
                finishGeneration(
                    totalGenerated,
                    MKL_PC_BulkGenerator.strings.cancelled ||
                        "Generation cancelled by user.",
                    { cancelled: true },
                );
                return;
            }

            // Preload images on first batch only
            if (offset === 0 && totalGenerated === 0) {
                $progress.show();
                $progressStatus.text(
                    MKL_PC_BulkGenerator.strings.preloading ||
                        "Preloading images for instant rendering...",
                );
                setStatus(
                    MKL_PC_BulkGenerator.strings.preloading ||
                        "Preloading images for instant rendering...",
                    "info",
                );
                appendLog(
                    "Preloading configurator images...",
                    "info",
                    { force: true },
                );

                var preloadStartedAt =
                    typeof performance !== "undefined" && performance.now
                        ? performance.now()
                        : Date.now();

                preloadConfiguratorImages(function () {
                    if (runMetrics) {
                        var now =
                            typeof performance !== "undefined" && performance.now
                                ? performance.now()
                                : Date.now();
                        runMetrics.preloads.push(now - preloadStartedAt);
                        scheduleStatsUpdate();
                    }
                    setStatus(
                        MKL_PC_BulkGenerator.strings.preload_complete ||
                            "Images ready. Searching for valid combinations...",
                        "info",
                    );
                    // Continue with batch generation after preload
                    processBatchAfterPreload(productId, offset, totalGenerated);
                });
                return;
            }

            setStatus(
                MKL_PC_BulkGenerator.strings.searching ||
                    "Searching for next valid combination...",
                "info",
            );
            processBatchAfterPreload(productId, offset, totalGenerated);
        }

        // Process batch after images are preloaded
        function processBatchAfterPreload(productId, offset, totalGenerated) {
            var batchStartedAt =
                typeof performance !== "undefined" && performance.now
                    ? performance.now()
                    : Date.now();
            if (isRunCancelled()) {
                finishGeneration(
                    totalGenerated,
                    MKL_PC_BulkGenerator.strings.cancelled ||
                        "Generation cancelled by user.",
                    { cancelled: true },
                );
                return;
            }

            setStatus(
                MKL_PC_BulkGenerator.strings.searching ||
                    "Searching for valid combinations...",
                "info",
            );

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
                        setStatus(
                            MKL_PC_BulkGenerator.strings.generating ||
                                "Generating presets...",
                            "info",
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
                            if (runMetrics) {
                                setStatus(
                                    "Rendering preset #" +
                                        (runMetrics.totalPresets + 1) + "...",
                                    "info",
                                );
                            }
                            if (
                                runMetrics &&
                                (runMetrics.totalPresets < 5 ||
                                    runMetrics.totalPresets % 10 === 0)
                            ) {
                                appendLog(
                                    "Rendering preset #" +
                                        (runMetrics.totalPresets + 1) +
                                        "...",
                                    "info",
                                );
                            }
                            if (isRunCancelled()) {
                                finishGeneration(
                                    totalGenerated,
                                    MKL_PC_BulkGenerator.strings.cancelled ||
                                        "Generation cancelled by user.",
                                    { cancelled: true },
                                );
                                return;
                            }
                            var applyStartedAt =
                                typeof performance !== "undefined" && performance.now
                                    ? performance.now()
                                    : Date.now();
                            applyAndSavePreset(
                                productId,
                                response.data.preset_name || "",
                                response.data.expanded_configuration,
                                function () {
                                    if (runMetrics) {
                                        var now =
                                            typeof performance !== "undefined" && performance.now
                                                ? performance.now()
                                                : Date.now();
                                        runMetrics.applyDurations.push(
                                            now - applyStartedAt,
                                        );
                                        scheduleStatsUpdate();
                                    }
                                    if (!response.data.is_complete) {
                                        if (isRunCancelled()) {
                                            finishGeneration(
                                                totalGenerated,
                                                MKL_PC_BulkGenerator.strings.cancelled ||
                                                    "Generation cancelled by user.",
                                                { cancelled: true },
                                            );
                                            return;
                                        }
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
                            if (isRunCancelled()) {
                                finishGeneration(
                                    totalGenerated,
                                    MKL_PC_BulkGenerator.strings.cancelled ||
                                        "Generation cancelled by user.",
                                    { cancelled: true },
                                );
                                return;
                            }
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
                        var failureMessage = response.data && response.data.message
                            ? response.data.message
                            : MKL_PC_BulkGenerator.strings.error;
                        setStatus(
                            (MKL_PC_BulkGenerator.strings.error || "Error") +
                                ": " +
                                failureMessage,
                            "error",
                        );
                        appendLog(
                            "Generation stopped: " + failureMessage,
                            "error",
                            { force: true },
                        );
                        finishGeneration(
                            totalGenerated,
                            failureMessage,
                            { error: true },
                        );
                        alert(
                            MKL_PC_BulkGenerator.strings.error + ": " +
                                response.data.message,
                        );
                    }
                },
                error: function (xhr, status, error) {
                    var errorText = MKL_PC_BulkGenerator.strings.error + ": " +
                        error;
                    setStatus(errorText, "error");
                    appendLog(errorText, "error", { force: true });
                    alert(errorText);
                    finishGeneration(
                        totalGenerated,
                        error,
                        { error: true },
                    );
                },
                complete: function () {
                    if (runMetrics) {
                        var now =
                            typeof performance !== "undefined" && performance.now
                                ? performance.now()
                                : Date.now();
                        runMetrics.batches.push(now - batchStartedAt);
                        scheduleStatsUpdate();
                    }
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
            var normalizedName = generatedName ? generatedName.trim() : "";
            var titleKey = normalizedName ? normalizedName.toLowerCase() : "";

            if (titleKey && knownPresetTitles.has(titleKey)) {
                setStatus(
                    "Skipped duplicate preset title.",
                    "warn",
                );
                appendLog(
                    "Skipped duplicate preset title: " + normalizedName,
                    "warn",
                    { force: true },
                );
                if (runMetrics) {
                    runMetrics.skippedDuplicates =
                        (runMetrics.skippedDuplicates || 0) + 1;
                    scheduleStatsUpdate();
                }
                setTimeout(callback, 120);
                return;
            }

            configuration = enrichConfigurationOrdering(configuration);

            setStatus(
                MKL_PC_BulkGenerator.strings.saving || "Saving preset...",
                "info",
            );

            var ajaxStartedAt =
                typeof performance !== "undefined" && performance.now
                    ? performance.now()
                    : Date.now();

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
                        if (runMetrics) {
                            runMetrics.totalPresets += 1;
                            scheduleStatsUpdate();
                        }

                        if (titleKey) {
                            knownPresetTitles.add(titleKey);
                        }

                        var $presetInput = $('.mkl_pc_admin input[name="new_preset_title"]');
                        if ($presetInput.length) {
                            $presetInput.val(generatedName);
                        }

                        $(document).trigger("mkl_pc_preset_saved", {
                            post_id: presetId,
                            title: generatedName,
                            content: configuration,
                        });

                        var thumbnailInfo = response.data &&
                            response.data.thumbnail
                            ? response.data.thumbnail
                            : null;
                        if (
                            runMetrics &&
                            thumbnailInfo &&
                            thumbnailInfo.mode === "async"
                        ) {
                            runMetrics.asyncThumbnails =
                                (runMetrics.asyncThumbnails || 0) + 1;
                        }

                        if (
                            !runMetrics ||
                            runMetrics.totalPresets <= 5 ||
                            runMetrics.totalPresets % 10 === 0
                        ) {
                            appendLog(
                                "Saved preset #" + presetId + " • " + generatedName,
                                "success",
                            );
                        }
                        setStatus(
                            "Saved preset #" + presetId +
                                " (" + runMetrics.totalPresets + " total).",
                            "success",
                        );

                        setTimeout(callback, 200);
                        return;
                    }

                    var errorMessage = response && response.data && response.data.message
                        ? response.data.message
                        : "Unknown error";

                    if (errorMessage && errorMessage.toLowerCase().indexOf('duplicate') !== -1) {
                        console.log('Preset already exists, skipping:', generatedName);
                        setStatus(
                            "Skipped duplicate preset.",
                            "warn",
                        );
                        appendLog(
                            "Skipped duplicate preset: " + generatedName,
                            "warn",
                            { force: true },
                        );
                        if (runMetrics) {
                            runMetrics.skippedDuplicates =
                                (runMetrics.skippedDuplicates || 0) + 1;
                            scheduleStatsUpdate();
                        }
                        if (titleKey) {
                            knownPresetTitles.add(titleKey);
                        }
                        setTimeout(callback, 200);
                        return;
                    }

                    console.warn('✗ Failed to create preset:', errorMessage, response);
                    setStatus(
                        "Failed to create preset: " + errorMessage,
                        "error",
                    );
                    appendLog(
                        "Preset save failed: " + errorMessage,
                        "error",
                        { force: true },
                    );
                    setTimeout(callback, 200);
                },
                error: function (xhr, status, error) {
                    console.warn("✗ AJAX save failed:", error || status);
                    setStatus(
                        "Preset save failed: " + (error || status),
                        "error",
                    );
                    appendLog(
                        "Preset save failed: " + (error || status),
                        "error",
                        { force: true },
                    );
                    setTimeout(callback, 200);
                },
                complete: function () {
                    if (runMetrics) {
                        var now =
                            typeof performance !== "undefined" && performance.now
                                ? performance.now()
                                : Date.now();
                        runMetrics.saveDurations.push(now - ajaxStartedAt);
                        scheduleStatsUpdate();
                    }
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

                if (layer.get("active")) {
                    onReady();
                    return;
                }

                var resolved = false;
                var cleanup = function () {
                    if (resolved) {
                        return;
                    }
                    resolved = true;
                    layer.off("change:active", onChange);
                    if (timeoutId) {
                        clearTimeout(timeoutId);
                    }
                    onReady();
                };

                var onChange = function (model, active) {
                    if (active) {
                        cleanup();
                    }
                };

                layer.on("change:active", onChange);

                var layerSelector =
                    '.layers-list-item[data-layer="' +
                    layer.id +
                    '"] > .layer-item';
                var $layerHeader = $(layerSelector);

                if ($layerHeader.length) {
                    $layerHeader.trigger("click");
                }

                // Fallback if UI event didn't set it active
                layer.set("active", true);

                var timeoutId = setTimeout(function () {
                    layer.off("change:active", onChange);
                    if (layer.get("active") || attempt >= 3) {
                        cleanup();
                        return;
                    }

                    openLayer(layer, onReady, attempt + 1);
                }, 60);
            }

            function waitForChoice(model, onReady, attempt) {
                attempt = attempt || 0;

                if (!model || typeof model.get !== "function") {
                    onReady();
                    return;
                }

                if (model.get("active")) {
                    onReady();
                    return;
                }

                var resolved = false;
                var cleanup = function () {
                    if (resolved) {
                        return;
                    }
                    resolved = true;
                    model.off("change:active", onChange);
                    if (timeoutId) {
                        clearTimeout(timeoutId);
                    }
                    onReady();
                };

                var onChange = function (choice, active) {
                    if (active) {
                        cleanup();
                    }
                };

                model.on("change:active", onChange);

                var timeoutId = setTimeout(function () {
                    model.off("change:active", onChange);
                    if (model.get("active") || attempt >= 4) {
                        cleanup();
                        return;
                    }

                    waitForChoice(model, onReady, attempt + 1);
                }, 60);
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
                    }, 60);
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

        function finishGeneration(totalGenerated, message, options) {
            options = options || {};
            if (currentRun && currentRun.finished) {
                return;
            }

            var $container = $(".mkl-pc-bulk-generator");
            var $progress = $container.find(".mkl-pc-bulk-generator-progress");
            var $progressBar = $progress.find(".progress-bar");
            var $progressStatus = $progress.find(".progress-status");
            var productIdForRefresh = currentRun ? currentRun.productId : null;
            var ajaxEndpoint = (typeof MKL_PC_BulkGenerator !== "undefined" &&
                MKL_PC_BulkGenerator.ajax_url)
                ? MKL_PC_BulkGenerator.ajax_url
                : (typeof ajaxurl !== "undefined" ? ajaxurl : null);

            var cancelled = !!options.cancelled;
            var errored = !!options.error;

            var defaultMessage = cancelled
                ? (MKL_PC_BulkGenerator.strings.cancelled ||
                    "Generation cancelled by user.")
                : (errored
                    ? (MKL_PC_BulkGenerator.strings.error || "An error occurred.")
                    : (MKL_PC_BulkGenerator.strings.complete ||
                        "Generation complete!"));

            var finalMessage = message ? message : defaultMessage;
            var statusTone = cancelled ? "warn" : (errored ? "error" : "success");

            setRunningState(false);
            markRunFinished();

            $progressBar.css("width", "100%").text("100%");
            $progressStatus.text(finalMessage);
            $container.find('[data-stat="generated"]').text(
                totalGenerated.toLocaleString(),
            );
            $container.find('[data-stat="existing"]').text(
                totalGenerated.toLocaleString(),
            );
            setStatus(finalMessage, statusTone);

            if (cancelled) {
                appendLog(
                    "Generation cancelled after " + totalGenerated + " presets" +
                        (runMetrics && runMetrics.skippedDuplicates
                            ? " (" + runMetrics.skippedDuplicates + " skipped)"
                            : "") +
                        ".",
                    "warn",
                    { force: true },
                );
            } else if (errored) {
                appendLog(
                    "Generation stopped after " + totalGenerated + " presets" +
                        (runMetrics && runMetrics.skippedDuplicates
                            ? " (" + runMetrics.skippedDuplicates + " skipped)"
                            : "") +
                        ".",
                    "error",
                    { force: true },
                );
            } else {
                appendLog(
                    "Generation complete (" + totalGenerated + " presets" +
                        (runMetrics && runMetrics.skippedDuplicates
                            ? ", " + runMetrics.skippedDuplicates + " skipped"
                            : "") +
                        ").",
                    "success",
                    { force: true },
                );
            }

            if (productIdForRefresh && ajaxEndpoint && window.PC_Presets_Configurations) {
                $.ajax({
                    url: ajaxEndpoint,
                    type: "GET",
                    data: {
                        action: "mkl_pc_get_content",
                        data: "configurations",
                        id: productIdForRefresh,
                        status: "preset",
                    },
                    success: function (configs) {
                        if (configs && Array.isArray(configs)) {
                            window.PC_Presets_Configurations.reset(configs);
                            collectExistingPresetTitles();
                        }
                    },
                });
            } else {
                collectExistingPresetTitles();
            }

            if (runMetrics) {
                var now =
                    typeof performance !== "undefined" && performance.now
                        ? performance.now()
                        : Date.now();
                var totalTime = now - runMetrics.startedAt;
                var average = function (values) {
                    if (!values || !values.length) {
                        return 0;
                    }
                    var sum = values.reduce(function (acc, val) {
                        return acc + val;
                    }, 0);
                    return sum / values.length;
                };

                console.groupCollapsed(
                    "MKL Bulk Generator metrics: " +
                        totalGenerated +
                        " presets in " +
                        Math.round(totalTime) +
                        "ms",
                );
                console.table({
                    presets: runMetrics.totalPresets,
                    totalTimeMs: Math.round(totalTime),
                    preloadCount: runMetrics.preloads.length,
                    avgPreloadMs: Math.round(average(runMetrics.preloads)),
                    batches: runMetrics.batches.length,
                    avgBatchMs: Math.round(average(runMetrics.batches)),
                    saves: runMetrics.saveDurations.length,
                    avgSaveMs: Math.round(average(runMetrics.saveDurations)),
                    applyCalls: runMetrics.applyDurations.length,
                    avgApplyMs: Math.round(average(runMetrics.applyDurations)),
                    asyncThumbnails: runMetrics.asyncThumbnails || 0,
                    skippedDuplicates: runMetrics.skippedDuplicates || 0,
                });
                console.log("Preload durations (ms):", runMetrics.preloads);
                console.log("Batch AJAX durations (ms):", runMetrics.batches);
                console.log("Apply durations (ms):", runMetrics.applyDurations);
                console.log("Save AJAX durations (ms):", runMetrics.saveDurations);
                console.groupEnd();
            }

            scheduleStatsUpdate();
            currentRun = null;
        }
    }
})(jQuery, _);
