(function ($, _) {
    "use strict";

    console.log("MKL PC Bulk Generator v1.1.4 loaded");
    var runMetrics = null;
    var currentRun = null;
    var statsUpdateScheduled = false;
    var statsTicker = null;
    var batchRetryTimer = null;
    var knownPresetTitles = new Set();
    var presetSnapshot = null;
    var snapshotPromise = null;

    function parseDisplayNumber(value) {
        if (typeof value === "number" && isFinite(value)) {
            return value;
        }
        var cleaned = String(value || "")
            .replace(/[^\d.-]/g, "")
            .replace(/^-$/, "");
        var parsed = parseInt(cleaned, 10);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function getSavedCount() {
        return runMetrics ? runMetrics.totalPresets : 0;
    }

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
        var $generateBtn = $container.find(".mkl-pc-generate-btn");
        var $deleteBtn = $container.find(".mkl-pc-delete-all-btn");
        var $progress = $container.find(".mkl-pc-bulk-generator-progress");
        var $progressBar = $progress.find(".progress-bar");
        var $progressStatus = $progress.find(".progress-status");
        var $stopBtn = $container.find(".mkl-pc-stop-btn");
        var $status = $container.find('[data-live="status"]');
        var $estimateBtn = $container.find('.mkl-pc-estimate-btn');
        var $estimateOutput = $container.find('[data-live="estimate-output"]');
        var $elapsed = $container.find('[data-live="elapsed"]');
        var $rate = $container.find('[data-live="rate"]');
        var $avgApply = $container.find('[data-live="avg-apply"]');
        var $avgSave = $container.find('[data-live="avg-save"]');
        var $skipped = $container.find('[data-live="skipped-duplicates"]');
        var $logList = $container.find('[data-live-log]');
        var $variationsPanel = $container.find('#mkl-pc-variations-panel');
        var $variationsList = $variationsPanel.length ? $variationsPanel.find('[data-variation-layer-list]') : $();
        var $variationsIncludeBase = $variationsPanel.length ? $variationsPanel.find('[data-variation-include-base]') : $();
        var $variationsLimit = $variationsPanel.length ? $variationsPanel.find('[data-variation-limit]') : $();
        var $variationsMessage = $variationsPanel.length ? $variationsPanel.find('[data-variation-message]') : $();
        var defaultGenerateLabel = $generateBtn.text();
        var defaultStopLabel = $stopBtn.length ? $stopBtn.text() : "Stop";
        var defaultStatusText = MKL_PC_BulkGenerator.strings.ready ||
            "Ready to start.";
        var defaultEstimateLabel = $estimateBtn.length
            ? $estimateBtn.text()
            : (MKL_PC_BulkGenerator.strings.estimate_action || "Estimate Valid Presets");
        var chunkSizeConfigured = Number(MKL_PC_BulkGenerator.batch_size) || 50;
        var emptyLogMessage = ($logList.text() || "").trim() ||
            MKL_PC_BulkGenerator.strings.log_empty ||
            "Activity will appear here once generation starts.";
        var logEntries = [];
        var canGenerateAfterRun = !$generateBtn.prop("disabled");
        var canDeleteAfterRun = !$deleteBtn.prop("disabled");
        var raf = window.requestAnimationFrame
            ? window.requestAnimationFrame.bind(window)
            : function (cb) {
                return setTimeout(cb, 60);
            };
        var estimatingInProgress = false;
        var combinationQueue = [];
        var combinationProcessing = false;
        var pendingBatchMeta = null;
        var lastRunPayload = null;
        var currentConfigState = new Map();
        var pendingTargetState = null;
        var layerChoices = [];
        var constraints = {}; // { layer_id: [choice_id] }
        var variationPrefs = {
            axes: [],
            includeBase: true,
            limit: 0,
        };
        var variationRunSummary = {
            added: 0,
            duplicates: 0,
            invalid: 0,
            baseSkipped: 0,
            limitReached: false,
        };

        var initialExisting = Number(MKL_PC_BulkGenerator.existing_total);
        if (Number.isFinite(initialExisting) && initialExisting >= 0) {
            presetSnapshot = {
                count: initialExisting,
                titles: new Set(),
                productId: getProductId(),
                titlesIncluded: false,
            };
            knownPresetTitles = presetSnapshot.titles;
            updateExistingStat(initialExisting);
        } else {
            updateExistingStat(null);
        }
        resetLiveView();
        renderLog();
        updateLiveStats();
        $stopBtn.prop("disabled", true).text(defaultStopLabel);
        $generateBtn.prop("disabled", false);
        canGenerateAfterRun = true;

        // Locks UI state
        var constraints = {}; // { layer_id: [choice_id] }
        var layerChoices = [];

        // Build Locks UI
        (function buildLocksUI() {
            var html = '' +
              '<div class="mkl-pc-bulk-panel" id="mkl-pc-locks-panel">' +
              '  <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">' +
              '    <strong>Locked Choices</strong>' +
              '    <button type="button" class="button" id="mkl-pc-locks-reset">Clear Locks</button>' +
              '  </div>' +
              '  <div id="mkl-pc-locks-body" style="margin-top:10px;display:grid;gap:8px;"></div>' +
              '</div>';
            $container.find('.mkl-pc-bulk-panels').prepend(html);
            $container.on('click', '#mkl-pc-locks-reset', function(){
                constraints = {};
                renderLocksBody();
            });
        })();

        // Fetch layers + choices to populate locks
        (function fetchLayerChoices() {
            var productId = getProductId();
            if (!productId) return;
            $.ajax({
                url: MKL_PC_BulkGenerator.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'mkl_pc_list_layers_choices',
                    nonce: MKL_PC_BulkGenerator.nonce,
                    product_id: productId,
                },
            }).done(function(res){
                if (res && res.success && res.data && Array.isArray(res.data.layers)) {
                    layerChoices = res.data.layers;
                    // Parse URL locks
                    constraints = parseConstraintsFromURL() || {};
                    renderLocksBody();
                    renderVariationLayerList();
                    syncVariationPrefsFromUI();
                }
            });
        })();

        function parseConstraintsFromURL() {
            try {
                var out = {};
                var params = new URLSearchParams(window.location.search);
                params.forEach(function(value, key){
                    var m = key.match(/^pc-lock\[(\d+)\]$/);
                    if (m) {
                        var lid = parseInt(m[1],10);
                        var val = parseInt(value,10);
                        if (Number.isFinite(lid) && Number.isFinite(val)) {
                            out[lid] = [val];
                        }
                    }
                });
                return out;
            } catch(e){ return {}; }
        }

        function renderLocksBody() {
            var $body = $('#mkl-pc-locks-body');
            if (!$body.length) return;
            var html = '';
            layerChoices.forEach(function(layer){
                var lid = layer.id;
                var lname = layer.name || (''+lid);
                var selected = (constraints[lid] && constraints[lid][0] !== null) ? constraints[lid][0] : null;
                html += '<div class="mkl-pc-lock-row" data-layer="'+lid+'">';
                html += '<label style="display:block;font-weight:600;">'+lname+'</label>';
                html += '<select class="mkl-pc-lock-select" style="min-width:220px;">';
                html += '<option value="">Any</option>';
                (layer.choices||[]).forEach(function(c){
                    if (c.id === null) return; // skip null
                    var sel = (selected !== null && Number(selected) === Number(c.id)) ? ' selected' : '';
                    html += '<option value="'+c.id+'"'+sel+'>'+c.name+'</option>';
                });
                html += '</select>';
                html += '</div>';
            });
            $body.html(html);
        }

        function ensureRunMetricsContext(initialExisting) {
            if (runMetrics) {
                return;
            }

            var existing = Number.isFinite(initialExisting)
                ? initialExisting
                : Number(MKL_PC_BulkGenerator.existing_total || 0);

            if (!Number.isFinite(existing) || existing < 0) {
                existing = 0;
            }

            initialiseRunMetrics(existing, chunkSizeConfigured);

            if (runMetrics && !runMetrics.runId) {
                runMetrics.runId = 'variation-' + Date.now();
            }
        }

        function setVariationMessage(text, tone) {
            if (!$variationsMessage.length) {
                return;
            }
            tone = tone || "info";
            $variationsMessage
                .removeClass("estimate--info estimate--success estimate--warn estimate--error")
                .addClass("estimate--" + tone)
                .text(text || "");
        }

        function resolveLayerName(layerId) {
            var match = (layerChoices || []).find(function (layer) {
                var lid = layer && (layer.id !== undefined ? layer.id : layer.layer_id);
                return Number(lid) === Number(layerId);
            });
            return match && match.name ? match.name : ('Layer ' + layerId);
        }

        function refreshVariationStatus() {
            if (! $variationsPanel.length) {
                return;
            }

            if (!variationPrefs.axes.length) {
                if (variationPrefs.includeBase) {
                    setVariationMessage(MKL_PC_BulkGenerator.strings.variations_hint || "No variation layers selected. Base presets will be generated as normal.", "info");
                } else {
                    setVariationMessage(MKL_PC_BulkGenerator.strings.variations_invalid || "Select at least one layer or include the current selection.", "warn");
                }
                return;
            }

            var axisNames = variationPrefs.axes.map(resolveLayerName);

            var message = (MKL_PC_BulkGenerator.strings.variations_active || 'Layer variations active for: %s.')
                .replace('%s', axisNames.join(', '));

            var tone = variationPrefs.includeBase ? 'success' : 'info';
            setVariationMessage(message, tone);
        }

        function renderVariationLayerList() {
            if (!$variationsList.length) {
                return;
            }

            if (!Array.isArray(layerChoices) || !layerChoices.length) {
                setVariationMessage(MKL_PC_BulkGenerator.strings.variations_select || "Select at least one layer to iterate.", "info");
                $variationsList.html('<p class="description">' + _.escape(MKL_PC_BulkGenerator.strings.variations_select || "Select at least one layer to iterate.") + '</p>');
                return;
            }

            var filteredAxes = variationPrefs.axes.filter(function (id) {
                return layerChoices.some(function (layer) {
                    var lid = layer && (layer.id !== undefined ? layer.id : layer.layer_id);
                    return Number(lid) === Number(id);
                });
            });
            variationPrefs.axes = filteredAxes.slice(0, 2);

            var html = layerChoices.map(function (layer) {
                var lid = layer && (layer.id !== undefined ? layer.id : layer.layer_id);
                lid = parseInt(lid, 10);
                if (!Number.isFinite(lid) || lid <= 0) {
                    return '';
                }
                var label = resolveLayerName(lid);
                var checked = variationPrefs.axes.indexOf(lid) !== -1 ? ' checked' : '';
                return '<label class="mkl-pc-variation-item" style="display:flex;align-items:center;gap:6px;">' +
                    '<input type="checkbox" value="' + lid + '"' + checked + ' />' +
                    _.escape(label) +
                    '</label>';
            }).join('');

            $variationsList.html(html);
        }

        function syncVariationPrefsFromUI() {
            if ($variationsList.length) {
                var selected = [];
                $variationsList.find('input[type="checkbox"]').each(function () {
                    if (this.checked) {
                        var value = parseInt($(this).val(), 10);
                        if (Number.isFinite(value) && value > 0) {
                            selected.push(value);
                        }
                    }
                });
                if (selected.length > 2) {
                    selected = selected.slice(0, 2);
                    setVariationMessage(MKL_PC_BulkGenerator.strings.variations_max_layers || "Select no more than two layers for this tool.", "warn");
                }
                variationPrefs.axes = selected;
            }

            if ($variationsIncludeBase.length) {
                variationPrefs.includeBase = !!$variationsIncludeBase.prop('checked');
            }

            if ($variationsLimit.length) {
                var limitValue = parseInt($variationsLimit.val(), 10);
                if (Number.isFinite(limitValue) && limitValue > 0) {
                    variationPrefs.limit = limitValue;
                } else {
                    variationPrefs.limit = 0;
                    $variationsLimit.val('');
                }
            }

            refreshVariationStatus();
        }

        function attachVariationEvents() {
            if (!$variationsPanel.length) {
                return;
            }

            if ($variationsList.length) {
                $variationsList.on('change', 'input[type="checkbox"]', function () {
                    syncVariationPrefsFromUI();
                });
            }

            if ($variationsIncludeBase.length) {
                $variationsIncludeBase.on('change', function () {
                    syncVariationPrefsFromUI();
                });
            }

            if ($variationsLimit.length) {
                $variationsLimit.on('change', function () {
                    syncVariationPrefsFromUI();
                });
            }
        }

        function updateVariationSummaryDisplay() {
            if (!variationPrefs.axes.length) {
                refreshVariationStatus();
                return;
            }

            var axisNames = variationPrefs.axes.map(resolveLayerName);
            var prefix = '';
            if (axisNames.length) {
                prefix = (MKL_PC_BulkGenerator.strings.variations_active || 'Layer variations active for: %s.')
                    .replace('%s', axisNames.join(', ')) + ' ';
            }

            var summaryMessage = (MKL_PC_BulkGenerator.strings.variations_summary || 'Queued %1$s variation presets (skipped %2$s duplicates, %3$s invalid).')
                .replace('%1$s', formatNumber(variationRunSummary.added || 0))
                .replace('%2$s', formatNumber(variationRunSummary.duplicates || 0))
                .replace('%3$s', formatNumber(variationRunSummary.invalid || 0));

            if (variationRunSummary.baseSkipped) {
                summaryMessage += ' (' + formatNumber(variationRunSummary.baseSkipped) + ' base selections skipped)';
            }

            if (variationRunSummary.limitReached) {
                summaryMessage += ' ' + (MKL_PC_BulkGenerator.strings.variations_limit || 'Variation limit reached – refine your selection or raise the limit.');
            }

            setVariationMessage(prefix + summaryMessage, variationRunSummary.limitReached ? 'warn' : 'info');
        }

        renderVariationLayerList();
        attachVariationEvents();
        syncVariationPrefsFromUI();

        // Track constraint changes
        $container.on('change', '.mkl-pc-lock-select', function(){
            var $row = $(this).closest('.mkl-pc-lock-row');
            var layerId = parseInt($row.data('layer'),10);
            var val = $(this).val();
            if (!layerId) return;
            if (!val) {
                delete constraints[layerId];
            } else {
                constraints[layerId] = [ parseInt(val,10) ];
            }
        });
        function getChunkSize() {
            return chunkSizeConfigured;
        }

        function average(values) {
            if (!values || !values.length) {
                return 0;
            }
            var total = values.reduce(function (sum, value) {
                return sum + value;
            }, 0);
            return total / values.length;
        }

        function formatNumber(value) {
            var num = Number(value);
            if (!isFinite(num)) {
                return "0";
            }
            return num.toLocaleString();
        }

        function formatRange(lower, upper) {
            if (!isFinite(lower) || !isFinite(upper)) {
                return "-";
            }
            return formatNumber(Math.round(Math.max(0, lower))) +
                " – " +
                formatNumber(Math.round(Math.max(0, upper)));
        }

        function getProductId() {
            var productId = Number($generateBtn.data("product-id"));
            return Number.isFinite(productId) && productId > 0 ? productId : null;
        }

        function updateExistingStat(count) {
            var formatted = "-";
            if (Number.isFinite(count) && count >= 0) {
                formatted = formatNumber(count);
                MKL_PC_BulkGenerator.existing_total = count;
            }
            if ($container && $container.length) {
                $container.find('[data-stat="existing"]').text(formatted);
            }
            if (presetSnapshot) {
                presetSnapshot.count = Number.isFinite(count) && count >= 0
                    ? count
                    : presetSnapshot.count;
            }
        }

        function requestPresetSnapshot(options) {
            options = options || {};
            var force = options.force === true;
            var productId = getProductId();

        if (!productId) {
            return $.Deferred()
                .reject("Missing product ID for preset snapshot.")
                .promise();
        }

            if (
                !force &&
                presetSnapshot &&
                presetSnapshot.productId === productId
            ) {
            return $.Deferred().resolve(presetSnapshot).promise();
        }

        if (snapshotPromise) {
            return snapshotPromise;
        }

        var deferred = $.Deferred();
        snapshotPromise = deferred.promise();

        $.ajax({
            url: MKL_PC_BulkGenerator.ajax_url,
            type: "POST",
            data: {
                action: "mkl_pc_get_preset_snapshot",
                nonce: MKL_PC_BulkGenerator.nonce,
                product_id: productId,
                include_titles: 1,
            },
        })
            .done(function (response) {
                if (!response || !response.success || !response.data) {
                    var message = response && response.data && response.data.message
                        ? response.data.message
                        : "Unknown server response.";
                    deferred.reject(message);
                    return;
                }

                var totalPresets = Number(response.data.count);
                if (!Number.isFinite(totalPresets) || totalPresets < 0) {
                    totalPresets = 0;
                }

                presetSnapshot = {
                    productId: productId,
                    count: totalPresets,
                    titles: new Set(),
                    titlesIncluded: !!response.data.titles_included,
                };

                knownPresetTitles = presetSnapshot.titles;

                MKL_PC_BulkGenerator.existing_total = totalPresets;
                updateExistingStat(totalPresets);

                // Seed known titles set if we received them
                if (response.data.titles && Array.isArray(response.data.titles)) {
                    response.data.titles.forEach(function (t) {
                        if (typeof t === "string" && t.trim().length) {
                            knownPresetTitles.add(t.trim().toLowerCase());
                        }
                    });
                }

                deferred.resolve(presetSnapshot);
            })
            .fail(function (xhr) {
                var message = (xhr && xhr.responseJSON && xhr.responseJSON.data &&
                    xhr.responseJSON.data.message)
                    ? xhr.responseJSON.data.message
                    : (xhr && xhr.statusText) || "Request failed.";
                deferred.reject(message);
            })
            .always(function () {
                snapshotPromise = null;
            });

        return deferred.promise();
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

        function setEstimateOutput(text, tone) {
            if (!$estimateOutput.length) {
                return;
            }
            tone = tone || "info";
            $estimateOutput
                .removeClass("estimate--info estimate--success estimate--warn estimate--error")
                .addClass("estimate--" + tone)
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
            if ($skipped.length) {
                $skipped.text("0");
            }
            if ($estimateOutput.length) {
                setEstimateOutput(
                    MKL_PC_BulkGenerator.strings.estimate_prompt ||
                        "Tap estimate to calculate.",
                    "info",
                );
            }
            if ($estimateBtn.length && !estimatingInProgress) {
                $estimateBtn.prop("disabled", false).text(defaultEstimateLabel);
            }
            combinationQueue = [];
            combinationProcessing = false;
            pendingBatchMeta = null;
            variationRunSummary = {
                added: 0,
                duplicates: 0,
                invalid: 0,
                baseSkipped: 0,
                limitReached: false,
            };
            refreshVariationStatus();
        }

        function captureButtonState() {
            canGenerateAfterRun = !$generateBtn.prop("disabled");
            canDeleteAfterRun = !$deleteBtn.prop("disabled");
        }

        function setRunningState(isRunning) {
            if (isRunning) {
                captureButtonState();
                $generateBtn
                    .prop("disabled", true)
                    .text(MKL_PC_BulkGenerator.strings.generating || "Generating...");
                $deleteBtn.prop("disabled", true);
                if ($stopBtn.length) {
                    $stopBtn.prop("disabled", false).text(
                        defaultStopLabel || "Stop Run",
                    );
                }
                if ($estimateBtn.length) {
                    $estimateBtn.prop("disabled", true);
                }
            } else {
                $generateBtn
                    .prop("disabled", !canGenerateAfterRun)
                    .text(defaultGenerateLabel);
                $deleteBtn.prop("disabled", !canDeleteAfterRun);
                if ($stopBtn.length) {
                    $stopBtn.prop("disabled", true).text(defaultStopLabel || "Stop Run");
                }
                if ($estimateBtn.length && !estimatingInProgress) {
                    $estimateBtn.prop("disabled", false).text(defaultEstimateLabel);
                }
            }
            updateVariationSummaryDisplay();
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
                if ($skipped.length) {
                    $skipped.text("0");
                }
                return;
            }

            var now = typeof performance !== "undefined" && performance.now
                ? performance.now()
                : Date.now();

            var elapsedMs = Math.max(0, now - runMetrics.startedAt);
            var savedForRate = Math.max(
                runMetrics.totalPresets,
                runMetrics.serverSavedTotal || 0,
            );
            var perMinute = elapsedMs > 0
                ? savedForRate / (elapsedMs / 60000)
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
            if ($skipped.length) {
                $skipped.text(
                    runMetrics.skippedDuplicates
                        ? runMetrics.skippedDuplicates.toString()
                        : "0",
                );
            }
        }

        function initialiseRunMetrics(initialCount, chunkSize) {
            var now = typeof performance !== "undefined" && performance.now
                ? performance.now()
                : Date.now();

            runMetrics = window.MKL_PC_BulkMetrics = {
                startedAt: now,
                batches: [],
                applyDurations: [],
                saveDurations: [],
                totalPresets: 0,
                asyncThumbnails: 0,
                skippedDuplicates: 0,
                skippedServer: 0,
                chunkSize: chunkSize,
                attemptedTotal: 0,
                serverAttemptedTotal: 0,
                serverSavedTotal: 0,
                pendingReservations: 0,
                pendingOffsets: 0,
                initialExisting: Number.isFinite(initialCount) ? initialCount : 0,
                runId: null,
            };
        }

        function clearBatchRetry() {
            if (batchRetryTimer) {
                clearTimeout(batchRetryTimer);
                batchRetryTimer = null;
            }
        }

        function scheduleBatchRetry(productId, delay) {
            clearBatchRetry();
            if (!currentRun || currentRun.finished || currentRun.cancelled) {
                return;
            }
            batchRetryTimer = setTimeout(function () {
                batchRetryTimer = null;
                processBatch(productId);
            }, Math.max(200, delay || 200));
        }

        function syncRunFromPayload(run) {
            if (!run) {
                return;
            }

            if (!currentRun) {
                currentRun = {
                    productId: getProductId(),
                    cancelled: false,
                    finished: false,
                };
            }

            currentRun.runId = run.run_id || currentRun.runId || null;

            if (!currentRun.chunkSize || currentRun.chunkSize < 1) {
                currentRun.chunkSize = getChunkSize();
            }

            if (typeof run.chunk_size === "number" && run.chunk_size > 0) {
                currentRun.chunkSize = run.chunk_size;
            }

            currentRun.nextOffset = typeof run.next_offset === "number"
                ? run.next_offset
                : currentRun.nextOffset || 0;
            currentRun.attemptedTotal = typeof run.attempted_total === "number"
                ? run.attempted_total
                : currentRun.attemptedTotal || 0;
            currentRun.savedTotal = typeof run.saved_total === "number"
                ? run.saved_total
                : currentRun.savedTotal || 0;
            currentRun.pendingReservations = typeof run.reservations === "number"
                ? run.reservations
                : currentRun.pendingReservations || 0;
            currentRun.pendingOffsets = typeof run.pending === "number"
                ? run.pending
                : currentRun.pendingOffsets || 0;
            currentRun.isComplete = !!run.is_complete;
            currentRun.isExhausted = !!run.is_exhausted;
            currentRun.updatedAt = run.updated_at
                ? run.updated_at * 1000
                : Date.now();
            currentRun.reservationTtl = run.reservation_ttl || currentRun.reservationTtl || 0;
            lastRunPayload = run;

            if (run.variations && typeof run.variations === "object") {
                var serverVariations = run.variations;
                var serverAxes = Array.isArray(serverVariations.axes)
                    ? Array.from(
                        new Set(
                            serverVariations.axes
                                .map(function (id) {
                                    return parseInt(id, 10);
                                })
                                .filter(function (id) {
                                    return Number.isFinite(id) && id > 0;
                                })
                        )
                    )
                    : [];
                if (serverAxes.length > 2) {
                    serverAxes = serverAxes.slice(0, 2);
                }
                var serverIncludeBase = serverVariations.include_base !== undefined
                    ? !!serverVariations.include_base
                    : variationPrefs.includeBase;
                var serverLimit = serverVariations.limit && parseInt(serverVariations.limit, 10) > 0
                    ? parseInt(serverVariations.limit, 10)
                    : 0;

                var axesChanged = serverAxes.length !== variationPrefs.axes.length || serverAxes.some(function (value, index) {
                    return value !== variationPrefs.axes[index];
                });
                var includeChanged = serverIncludeBase !== variationPrefs.includeBase;
                var limitChanged = serverLimit !== variationPrefs.limit;

                if (axesChanged || includeChanged || limitChanged) {
                    variationPrefs.axes = serverAxes;
                    variationPrefs.includeBase = serverIncludeBase;
                    variationPrefs.limit = serverLimit;

                    renderVariationLayerList();
                    if ($variationsIncludeBase.length) {
                        $variationsIncludeBase.prop('checked', variationPrefs.includeBase);
                    }
                    if ($variationsLimit.length) {
                        $variationsLimit.val(variationPrefs.limit > 0 ? variationPrefs.limit : '');
                    }
                    syncVariationPrefsFromUI();
                }
            }

            if (!runMetrics) {
                initialiseRunMetrics(
                    Number.isFinite(presetSnapshot && presetSnapshot.count)
                        ? presetSnapshot.count
                        : 0,
                    currentRun.chunkSize,
                );
            }

            runMetrics.chunkSize = currentRun.chunkSize;
            runMetrics.attemptedTotal = currentRun.attemptedTotal;
            runMetrics.serverAttemptedTotal = currentRun.attemptedTotal;
            runMetrics.serverSavedTotal = currentRun.savedTotal;
            runMetrics.pendingReservations = currentRun.pendingReservations;
            runMetrics.pendingOffsets = currentRun.pendingOffsets;
            runMetrics.runId = currentRun.runId;
        }

        function requestBackendRun(productId, options) {
            options = options || {};
            var payload = {
                action: "mkl_pc_begin_generation_run",
                nonce: MKL_PC_BulkGenerator.nonce,
                product_id: productId,
                chunk_size: options.chunkSize || chunkSizeConfigured,
            };
            if (constraints && Object.keys(constraints).length) {
                payload.constraints = JSON.stringify(constraints);
            }

            if (variationPrefs) {
                payload.variation_axes = JSON.stringify(variationPrefs.axes || []);
                payload.variation_include_base = variationPrefs.includeBase ? 1 : 0;
                if (variationPrefs.limit && variationPrefs.limit > 0) {
                    payload.variation_limit = variationPrefs.limit;
                }
            }

            if (options.forceNew) {
                payload.force_new = 1;
            }

            return $.ajax({
                url: MKL_PC_BulkGenerator.ajax_url,
                type: "POST",
                data: payload,
            });
        }

        function cancelBackendRun(productId, runId) {
            if (!productId || !runId) {
                return $.Deferred().reject().promise();
            }

            return $.ajax({
                url: MKL_PC_BulkGenerator.ajax_url,
                type: "POST",
                data: {
                    action: "mkl_pc_cancel_generation_run",
                    nonce: MKL_PC_BulkGenerator.nonce,
                    product_id: productId,
                    run_id: runId,
                },
            });
        }

        function cancelRun() {
            if (!currentRun || currentRun.cancelled) {
                return;
            }
            currentRun.cancelled = true;
            clearBatchRetry();
            pendingBatchMeta = null;
            combinationQueue = [];
            combinationProcessing = false;
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
            if (currentRun.runId) {
                cancelBackendRun(currentRun.productId, currentRun.runId)
                    .done(function (resp) {
                        // If backend confirms cancellation, finish immediately to unstick UI
                        var ok = resp && resp.success && resp.data && resp.data.run && resp.data.run.cancelled;
                        if (ok) {
                            combinationQueue = [];
                            combinationProcessing = false;
                            pendingBatchMeta = null;
                            finishGeneration(
                                getSavedCount(),
                                MKL_PC_BulkGenerator.strings.cancelled ||
                                    "Generation cancelled by user.",
                                { cancelled: true },
                            );
                        }
                    })
                    .always(function () {
                        scheduleStatsUpdate();
                    });
                return;
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

            if (!variationPrefs.includeBase && !variationPrefs.axes.length) {
                var invalidMessage = MKL_PC_BulkGenerator.strings.variations_invalid || "Select at least one layer or include the current selection.";
                setVariationMessage(invalidMessage, "warn");
                setStatus(invalidMessage, "warn");
                return;
            }

            variationRunSummary = {
                added: 0,
                duplicates: 0,
                invalid: 0,
                baseSkipped: 0,
                limitReached: false,
            };

            var preparingLabel = MKL_PC_BulkGenerator.strings.preparing ||
                "Preparing preset run...";
            setStatus(preparingLabel, "info");
            appendLog(preparingLabel, "info", { force: true });
            setRunningState(true);

            if (variationPrefs.axes.length) {
                var axisNames = variationPrefs.axes.map(resolveLayerName);
                appendLog('Layer variations enabled for: ' + axisNames.join(', '), 'info', { force: true });
                updateVariationSummaryDisplay();
            } else {
                refreshVariationStatus();
            }

            var snapshot = null;
            if (presetSnapshot && presetSnapshot.productId === productId) {
                snapshot = presetSnapshot;
            }
            if (!snapshot) {
                var initialCount = Number(MKL_PC_BulkGenerator.existing_total);
                if (!Number.isFinite(initialCount) || initialCount < 0) {
                    initialCount = null;
                }
                snapshot = {
                    count: initialCount,
                    titles: new Set(),
                    productId: productId,
                    titlesIncluded: false,
                };
                presetSnapshot = snapshot;
            } else if (!(snapshot.titles instanceof Set)) {
                snapshot.titles = new Set();
                snapshot.titlesIncluded = !!snapshot.titlesIncluded;
            }

            knownPresetTitles = snapshot.titles instanceof Set ? snapshot.titles : new Set();
            updateExistingStat(snapshot.count);

            var titlesPromise = snapshot.titlesIncluded
                ? $.Deferred().resolve(snapshot).promise()
                : requestPresetSnapshot({ force: true });

            titlesPromise
                .done(function (finalSnapshot) {
                    beginRun(productId, finalSnapshot || snapshot);
                })
                .fail(function (errorMessage) {
                    var message = typeof errorMessage === "string"
                        ? errorMessage
                        : "Unable to load existing presets.";
                    appendLog("Failed to prepare run: " + message, "error", { force: true });
                    setStatus(
                        (MKL_PC_BulkGenerator.strings.error || "An error occurred") +
                            ": " + message,
                        "error",
                    );
                    setRunningState(false);
                });
        }

        function beginRun(productId, snapshot) {
            snapshot = snapshot || {};
            if (!(snapshot.titles instanceof Set)) {
                snapshot.titles = new Set();
            }

            knownPresetTitles = snapshot.titles instanceof Set ? snapshot.titles : new Set();
            if (!presetSnapshot || presetSnapshot.productId !== productId) {
                presetSnapshot = {
                    count: Number.isFinite(snapshot.count) ? snapshot.count : 0,
                    titles: knownPresetTitles,
                    productId: productId,
                    titlesIncluded: !!snapshot.titlesIncluded,
                };
            } else {
                presetSnapshot.count = Number.isFinite(snapshot.count)
                    ? snapshot.count
                    : presetSnapshot.count || 0;
                presetSnapshot.titles = knownPresetTitles;
                presetSnapshot.titlesIncluded = !!snapshot.titlesIncluded;
            }

            resetLiveView();
            updateExistingStat(presetSnapshot.count);
            setRunningState(true);

            var preparingLabel = MKL_PC_BulkGenerator.strings.preparing ||
                "Preparing preset run...";
            setStatus(preparingLabel, "info");
            appendLog(
                "Preparing backend run context...",
                "info",
                { force: true },
            );

            requestBackendRun(productId, { chunkSize: chunkSizeConfigured })
                .done(function (response) {
                    var run = response && response.success && response.data
                        ? response.data.run
                        : null;

                    if (!run || !run.run_id) {
                        var missingMessage = MKL_PC_BulkGenerator.strings.error ||
                            "Failed to prepare run.";
                        setStatus(missingMessage, "error");
                        appendLog(missingMessage, "error", { force: true });
                        setRunningState(false);
                        return;
                    }

                    var chunkSize = typeof run.chunk_size === "number" && run.chunk_size > 0
                        ? run.chunk_size
                        : chunkSizeConfigured;

                    initialiseRunMetrics(presetSnapshot.count, chunkSize);

                    currentRun = {
                        productId: productId,
                        runId: run.run_id,
                        cancelled: false,
                        finished: false,
                        chunkSize: chunkSize,
                        attemptedTotal: typeof run.attempted_total === "number"
                            ? run.attempted_total
                            : 0,
                        initialExisting: runMetrics.initialExisting,
                        savedTotal: typeof run.saved_total === "number"
                            ? run.saved_total
                            : 0,
                    };

                    syncRunFromPayload(run);

                    appendLog(
                        "Run " + run.run_id + " initialised. Batch size " +
                            formatNumber(currentRun.chunkSize) + ".",
                        "info",
                        { force: true },
                    );

                    var generatingLabel = MKL_PC_BulkGenerator.strings.generating ||
                        "Generating presets...";
                    setStatus(generatingLabel, "info");
                    appendLog(
                        "Generation started for product #" + productId +
                            " (run " + run.run_id + ").",
                        "info",
                        { force: true },
                    );

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
                    clearBatchRetry();
                    processBatch(productId);
                })
                .fail(function (xhr) {
                    var errorMessage = MKL_PC_BulkGenerator.strings.error ||
                        "Failed to prepare run.";
                    if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        errorMessage = xhr.responseJSON.data.message;
                    }
                    setStatus(errorMessage, "error");
                    appendLog("Failed to prepare run: " + errorMessage, "error", { force: true });
                    setRunningState(false);
                });
        }

        if ($stopBtn.length) {
            $stopBtn.on("click", cancelRun);
        }

        if ($estimateBtn.length) {
            $estimateBtn.on("click", function () {
                if (estimatingInProgress) {
                    return;
                }
                var productId = $(this).data("product-id");
                if (!productId) {
                    return;
                }

                estimatingInProgress = true;
                $estimateBtn
                    .prop("disabled", true)
                    .text(MKL_PC_BulkGenerator.strings.estimating || "Estimating...");
                setEstimateOutput(
                    MKL_PC_BulkGenerator.strings.estimating || "Estimating...",
                    "info",
                );

                $.ajax({
                    url: MKL_PC_BulkGenerator.ajax_url,
                    type: "POST",
                    data: {
                        action: "mkl_pc_generate_presets_estimate",
                        nonce: MKL_PC_BulkGenerator.nonce,
                        product_id: productId,
                        constraints: (constraints && Object.keys(constraints).length)
                            ? JSON.stringify(constraints)
                            : undefined,
                        variation_axes: JSON.stringify(variationPrefs.axes || []),
                        variation_include_base: variationPrefs.includeBase ? 1 : 0,
                        variation_limit: variationPrefs.limit || 0,
                    },
                })
                    .done(function (response) {
                        if (response && response.success && response.data) {
                            var message = response.data.message ||
                                MKL_PC_BulkGenerator.strings.estimate_complete ||
                                "Estimate complete.";
                            setEstimateOutput(message, "success");
                        } else {
                            var failMessage = response && response.data && response.data.message
                                ? response.data.message
                                : (MKL_PC_BulkGenerator.strings.estimate_failed ||
                                    "Failed to estimate presets.");
                            setEstimateOutput(failMessage, "error");
                        }
                    })
                    .fail(function (xhr) {
                        var failMessage = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
                            ? xhr.responseJSON.data.message
                            : (MKL_PC_BulkGenerator.strings.estimate_failed ||
                                "Failed to estimate presets.");
                        setEstimateOutput(failMessage, "error");
                    })
                    .always(function () {
                        estimatingInProgress = false;
                        if (!currentRun || currentRun.finished || currentRun.cancelled) {
                            $estimateBtn.prop("disabled", false).text(defaultEstimateLabel);
                        }
                    });
            });
        }

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

                        // Update existing snapshot/count
                        presetSnapshot = {
                            count: 0,
                            titles: new Set(),
                            productId: getProductId(),
                            titlesIncluded: false,
                        };
                        knownPresetTitles = presetSnapshot.titles;
                        MKL_PC_BulkGenerator.existing_total = 0;
                        combinationQueue = [];
                        combinationProcessing = false;
                        pendingBatchMeta = null;
                        pendingTargetState = null;
                        currentConfigState = new Map();
                        updateExistingStat(0);
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
        function processBatch(productId) {
            if (!productId) {
                return;
            }

            if (!currentRun || currentRun.finished) {
                return;
            }

            if (isRunCancelled()) {
                finishGeneration(
                    getSavedCount(),
                    MKL_PC_BulkGenerator.strings.cancelled ||
                        "Generation cancelled by user.",
                    { cancelled: true },
                );
                return;
            }

            setStatus(
                MKL_PC_BulkGenerator.strings.searching ||
                    "Searching for next valid combination...",
                "info",
            );
            processBatchAfterPreload(productId);
        }

        // Process batch via AJAX
        function processBatchAfterPreload(productId) {
            if (!currentRun || !currentRun.runId) {
                setStatus(
                    MKL_PC_BulkGenerator.strings.error ||
                        "Run context missing. Please restart generation.",
                    "error",
                );
                finishGeneration(
                    getSavedCount(),
                    MKL_PC_BulkGenerator.strings.error ||
                        "Run context missing.",
                    { error: true },
                );
                return;
            }

            var batchSize = currentRun.chunkSize || getChunkSize();
            var attemptsBeforeBatch = runMetrics && Number.isFinite(runMetrics.attemptedTotal)
                ? runMetrics.attemptedTotal
                : 0;
            var batchStartedAt =
                typeof performance !== "undefined" && performance.now
                    ? performance.now()
                    : Date.now();

            $.ajax({
                url: MKL_PC_BulkGenerator.ajax_url,
                type: "POST",
                data: {
                    action: "mkl_pc_generate_presets_batch",
                    nonce: MKL_PC_BulkGenerator.nonce,
                    product_id: productId,
                    run_id: currentRun.runId,
                    batch_size: batchSize,
                },
                success: function (response) {
                    if (!response || !response.success) {
                        var failMessage = response && response.data && response.data.message
                            ? response.data.message
                            : (MKL_PC_BulkGenerator.strings.error || "Unexpected error.");
                        setStatus(
                            (MKL_PC_BulkGenerator.strings.error || "Error") + ": " + failMessage,
                            "error",
                        );
                        appendLog(
                            "Generation stopped: " + failMessage,
                            "error",
                            { force: true },
                        );
                        finishGeneration(
                            getSavedCount(),
                            failMessage,
                            { error: true },
                        );
                        return;
                    }

                    var data = response.data || {};
                    if (data.run) {
                        syncRunFromPayload(data.run);
                    }

                    if (data.variation_summary) {
                        var vs = data.variation_summary;
                        var skipped = vs.skipped || {};
                        variationRunSummary.added += vs.added || 0;
                        variationRunSummary.duplicates += skipped.duplicate || 0;
                        variationRunSummary.invalid += skipped.invalid || 0;
                        variationRunSummary.baseSkipped += skipped.base || 0;
                        if (vs.limit_reached) {
                            variationRunSummary.limitReached = true;
                        }
                        updateVariationSummaryDisplay();
                    }

                    var attemptedFromServer = data.run && typeof data.run.attempted_total === "number"
                        ? data.run.attempted_total
                        : attemptsBeforeBatch;

                    if (runMetrics) {
                        runMetrics.attemptedTotal = attemptedFromServer;
                        runMetrics.skippedServer =
                            (runMetrics.skippedServer || 0) + (data.skipped || 0);
                    }
                    if (currentRun) {
                        currentRun.attemptedTotal = attemptedFromServer;
                    }

                    var savedTotalClient = runMetrics ? runMetrics.totalPresets : 0;
                    var savedTotalServer = runMetrics ? runMetrics.serverSavedTotal || 0 : 0;
                    var savedTotalForStats = Math.max(savedTotalClient, savedTotalServer);

                    var serverSkippedTotal = runMetrics
                        ? runMetrics.skippedServer || 0
                        : (data.skipped || 0);
                    var duplicateSkipped = runMetrics
                        ? runMetrics.skippedDuplicates || 0
                        : 0;
                    var totalSkipped = serverSkippedTotal + duplicateSkipped;

                    var combosRaw = [];
                    if (Array.isArray(data.valid_combinations)) {
                        combosRaw = data.valid_combinations;
                    } else if (Array.isArray(data.valid_combination)) {
                        combosRaw = data.valid_combination;
                    } else if (data.expanded_configuration) {
                        combosRaw = [{
                            preset_name: data.preset_name || "",
                            expanded_configuration: data.expanded_configuration,
                        }];
                    }

                    var queuedCount = 0;
                    combosRaw.forEach(function (combo) {
                        combinationQueue.push({
                            productId: productId,
                            name: combo && combo.preset_name ? combo.preset_name : "",
                            configuration: combo && Array.isArray(combo.expanded_configuration)
                                ? combo.expanded_configuration
                                : [],
                        });
                        queuedCount++;
                    });

                    var progressMessage =
                        "Processed " + formatNumber(attemptedFromServer) +
                        " combinations • Saved " + formatNumber(savedTotalForStats);
                    if (totalSkipped > 0) {
                        progressMessage +=
                            " • Skipped " + formatNumber(totalSkipped);
                    }
                    if (queuedCount > 0) {
                        progressMessage +=
                            " • Queued " + formatNumber(queuedCount) +
                            (queuedCount === 1 ? " preset" : " presets");
                    }
                    if (typeof data.claimed_offset === "number") {
                        progressMessage +=
                            " • Offset " + formatNumber(data.claimed_offset);
                    }

                    $progressStatus.text(progressMessage);
                    setStatus(
                        MKL_PC_BulkGenerator.strings.generating ||
                            "Generating presets...",
                        "info",
                    );

                    $container.find('[data-stat="generated"]').text(
                        formatNumber(savedTotalForStats),
                    );
                    updateExistingStat(
                        (runMetrics ? runMetrics.initialExisting : 0) + savedTotalForStats,
                    );

                    pendingBatchMeta = data;

                    clearBatchRetry();

                    if (combinationQueue.length) {
                        processCombinationQueue();
                        return;
                    }

                    if (isRunCancelled()) {
                        finishGeneration(
                            getSavedCount(),
                            MKL_PC_BulkGenerator.strings.cancelled ||
                                "Generation cancelled by user.",
                            { cancelled: true },
                        );
                        return;
                    }

                    var isComplete = !!data.is_complete || (data.run && data.run.is_complete);
                    if (isComplete) {
                        finishGeneration(
                            getSavedCount(),
                            data.message ||
                                MKL_PC_BulkGenerator.strings.complete ||
                                "Generation complete.",
                        );
                        return;
                    }

                    scheduleBatchRetry(productId, 250);
                },
                error: function (xhr) {
                    var message = MKL_PC_BulkGenerator.strings.error || "An error occurred";
                    var errorCode = null;
                    if (xhr && xhr.responseJSON && xhr.responseJSON.data) {
                        if (xhr.responseJSON.data.message) {
                            message = xhr.responseJSON.data.message;
                        }
                        if (xhr.responseJSON.data.code) {
                            errorCode = xhr.responseJSON.data.code;
                        }
                    }
                    setStatus(message, "error");
                    appendLog(message, "error", { force: true });
                    if (errorCode === "run_mismatch" || errorCode === "run_missing") {
                        finishGeneration(
                            getSavedCount(),
                            message,
                            { error: true },
                        );
                    } else {
                        scheduleBatchRetry(productId, 400);
                    }
                },
                complete: function () {
                    if (runMetrics) {
                        var nowComplete =
                            typeof performance !== "undefined" && performance.now
                                ? performance.now()
                                : Date.now();
                        runMetrics.batches.push(nowComplete - batchStartedAt);
                        scheduleStatsUpdate();
                    }
                },
            });
        }

        function processCombinationQueue() {
            // If user cancelled, end the run immediately regardless of queue state
            if (isRunCancelled()) {
                combinationQueue = [];
                combinationProcessing = false;
                pendingBatchMeta = null;
                finishGeneration(
                    getSavedCount(),
                    MKL_PC_BulkGenerator.strings.cancelled ||
                        "Generation cancelled by user.",
                    { cancelled: true },
                );
                return;
            }

            if (combinationProcessing) {
                return;
            }

            if (!combinationQueue.length) {
                if (pendingBatchMeta) {
                    var meta = pendingBatchMeta;
                    pendingBatchMeta = null;

                    if (isRunCancelled()) {
                        finishGeneration(
                            getSavedCount(),
                            MKL_PC_BulkGenerator.strings.cancelled ||
                                "Generation cancelled by user.",
                            { cancelled: true },
                        );
                        return;
                    }

                    var runPayload = meta.run || null;
                    if (runPayload) {
                        syncRunFromPayload(runPayload);
                    }

                    var metaMessage = meta.message || "";
                    var metaProductId = meta.productId || (currentRun ? currentRun.productId : null);
                    var metaComplete = !!meta.is_complete || (runPayload && runPayload.is_complete);

                    if (metaComplete) {
                        finishGeneration(
                            getSavedCount(),
                            metaMessage ||
                                MKL_PC_BulkGenerator.strings.complete ||
                                "Generation complete.",
                        );
                    } else {
                        scheduleBatchRetry(metaProductId || getProductId(), 250);
                    }
                }
                return;
            }

            // Already handled at top, but keep this guard for safety
            if (isRunCancelled()) {
                combinationQueue = [];
                combinationProcessing = false;
                pendingBatchMeta = null;
                finishGeneration(
                    getSavedCount(),
                    MKL_PC_BulkGenerator.strings.cancelled ||
                        "Generation cancelled by user.",
                    { cancelled: true },
                );
                return;
            }

            combinationProcessing = true;
            var nextItem = combinationQueue.shift() || {};
            var targetProductId = nextItem.productId || (currentRun ? currentRun.productId : null);
            var presetName = nextItem.name || "";
            var configuration = Array.isArray(nextItem.configuration)
                ? nextItem.configuration
                : [];

            if (!targetProductId || !configuration.length) {
                combinationProcessing = false;
                setTimeout(processCombinationQueue, 0);
                return;
            }

            if (runMetrics) {
                setStatus(
                    "Rendering preset #" + (runMetrics.totalPresets + 1) + "...",
                    "info",
                );
                if (
                    runMetrics.totalPresets < 5 ||
                    runMetrics.totalPresets % 10 === 0
                ) {
                    appendLog(
                        "Rendering preset #" + (runMetrics.totalPresets + 1) + "...",
                        "info",
                    );
                }
            }

            var applyStartedAt =
                typeof performance !== "undefined" && performance.now
                    ? performance.now()
                    : Date.now();

            applyAndSavePreset(
                targetProductId,
                presetName,
                configuration,
                function () {
                    if (runMetrics) {
                        var now =
                            typeof performance !== "undefined" && performance.now
                                ? performance.now()
                                : Date.now();
                        runMetrics.applyDurations.push(now - applyStartedAt);
                        scheduleStatsUpdate();
                    }
                    combinationProcessing = false;
                    processCombinationQueue();
                },
            );
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
                pendingTargetState = null;
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

            // Prefer mimicking Save Your Design exactly: build content via FE state and use core endpoints
            function buildContentViaFrontend() {
                try {
                    if (!window.PC || !PC.fe || !PC.fe.layers || !PC.fe.save_data) return null;
                    // Match upstream: order by image_order for correct stacking
                    var prevOrderBy = PC.fe.layers.orderBy;
                    PC.fe.layers.orderBy = 'image_order';
                    PC.fe.layers.sort();
                    var content = PC.fe.save_data.save();
                    // Reset
                    PC.fe.layers.orderBy = prevOrderBy || 'order';
                    PC.fe.layers.sort();
                    return content;
                } catch (e) {
                    return null;
                }
            }

            var contentString = buildContentViaFrontend();
            if (!contentString) {
                // Fallback to our expanded configuration JSON
                contentString = JSON.stringify(configuration);
            }

            // Use Save Your Design save endpoint for presets
            var saveData = {
                action: 'mkl_pc_save_configuration',
                content: contentString,
                config_type: 'preset',
                id: productId,
                title: generatedName,
                security: (window.PC_SYD && PC_SYD.save_config_nonce) ? PC_SYD.save_config_nonce : ''
            };

            $.ajax({
                url: MKL_PC_BulkGenerator.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: saveData,
                success: function (response) {
                    var presetId = response && response.success && response.data
                        ? (response.data.config_id || response.data.ID)
                        : null;

                    if (response && response.success && presetId) {
                        console.log("✓ Preset saved:", generatedName, "#", presetId);

                        var updatedExistingTotal;
                        if (runMetrics) {
                            runMetrics.totalPresets += 1;
                            runMetrics.serverSavedTotal = Math.max(
                                runMetrics.serverSavedTotal || 0,
                                runMetrics.totalPresets,
                            );
                            var savedForTotals = Math.max(
                                runMetrics.totalPresets,
                                runMetrics.serverSavedTotal || 0,
                            );
                            updatedExistingTotal = runMetrics.initialExisting + savedForTotals;
                            scheduleStatsUpdate();
                        } else {
                            updatedExistingTotal = (MKL_PC_BulkGenerator.existing_total || 0) + 1;
                        }

                        if (titleKey) {
                            knownPresetTitles.add(titleKey);
                        }

                        if (pendingTargetState && typeof pendingTargetState.forEach === "function") {
                            currentConfigState = new Map();
                            pendingTargetState.forEach(function (value, key) {
                                currentConfigState.set(key, value);
                            });
                            pendingTargetState = null;
                        } else {
                            currentConfigState = new Map();
                            userSelections.forEach(function (choice) {
                                currentConfigState.set(String(choice.layer_id), choice.choice_id);
                            });
                        }

                        if (presetSnapshot && presetSnapshot.productId === productId) {
                            presetSnapshot.count = updatedExistingTotal;
                            presetSnapshot.titles = knownPresetTitles;
                        }

                        MKL_PC_BulkGenerator.existing_total = updatedExistingTotal;
                        updateExistingStat(updatedExistingTotal);

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

                        // Ensure thumbnail exists – mimic core flow first, then fallback to our endpoint
                        (function ensureThumbnail() {
                            function fallbackEnsure() {
                                $.ajax({
                                    url: MKL_PC_BulkGenerator.ajax_url,
                                    type: 'POST',
                                    dataType: 'json',
                                    data: {
                                        action: 'mkl_pc_generate_preset_thumbnail',
                                        nonce: MKL_PC_BulkGenerator.nonce,
                                        preset_id: presetId,
                                    },
                                }).done(function (r2) {
                                    if (r2 && r2.success && r2.data && r2.data.url) {
                                        appendLog("Generated thumbnail for preset #" + presetId, 'success');
                                    }
                                });
                            }

                            // Try core endpoint first
                            if (window.PC_SYD && PC_SYD.save_config_image_nonce) {
                                $.ajax({
                                    url: MKL_PC_BulkGenerator.ajax_url,
                                    type: 'POST',
                                    dataType: 'json',
                                    data: {
                                        action: 'mkl_pc_generate_configuration_image',
                                        config_id: presetId,
                                        security: PC_SYD.save_config_image_nonce,
                                    },
                                }).done(function (res) {
                                    if (!(res && res.success && res.data && res.data.thumbnail)) {
                                        // Core returned missing URL; use our robust endpoint
                                        fallbackEnsure();
                                    } else {
                                        appendLog("Generated thumbnail via core for preset #" + presetId, 'success');
                                    }
                                }).fail(fallbackEnsure);
                            } else {
                                fallbackEnsure();
                            }
                        })();

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
                        // Ensure thumbnail exists for the duplicate preset if the server returned its ID
                        var existingId = response && response.data && response.data.preset_id
                            ? Number(response.data.preset_id)
                            : null;
                        if (existingId && Number.isFinite(existingId) && existingId > 0) {
                            $.ajax({
                                url: MKL_PC_BulkGenerator.ajax_url,
                                type: "POST",
                                data: {
                                    action: "mkl_pc_generate_preset_thumbnail",
                                    nonce: MKL_PC_BulkGenerator.nonce,
                                    preset_id: existingId,
                                },
                            }).done(function (thumbRes) {
                                if (thumbRes && thumbRes.success && thumbRes.data && thumbRes.data.url) {
                                    appendLog(
                                        "Ensured thumbnail for duplicate preset #" + existingId,
                                        "success",
                                        { force: false },
                                    );
                                }
                            });
                        }
                        if (titleKey) {
                            knownPresetTitles.add(titleKey);
                        }
                        if (pendingTargetState && typeof pendingTargetState.forEach === "function") {
                            currentConfigState = new Map();
                            pendingTargetState.forEach(function (value, key) {
                                currentConfigState.set(key, value);
                            });
                            pendingTargetState = null;
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
                    pendingTargetState = null;
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
                    pendingTargetState = null;
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
                    nameParts.push(layer.layer_name + ": " + layer.name);
                }
            });

            var name = productName;
            if (nameParts.length) {
                name += " - " + nameParts.join(" - ");
            }

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
            if (isRunCancelled()) {
                // Do not proceed with any UI side-effects if cancelled
                setTimeout(function(){ callback && callback(); }, 0);
                return;
            }
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

            var resolvedPresetName = presetName && presetName.length
                ? presetName
                : generatePresetName(expandedConfiguration);
            var normalizedResolvedName = resolvedPresetName
                ? resolvedPresetName.trim().toLowerCase()
                : "";

            if (normalizedResolvedName && knownPresetTitles.has(normalizedResolvedName)) {
                appendLog(
                    "Skipped duplicate preset: " + resolvedPresetName,
                    "warn",
                    { force: true },
                );
                if (runMetrics) {
                    runMetrics.skippedDuplicates =
                        (runMetrics.skippedDuplicates || 0) + 1;
                    scheduleStatsUpdate();
                }
                setTimeout(callback, 50);
                return;
            }

            presetName = resolvedPresetName;

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

            var targetStateMap = new Map();
            userSelections.forEach(function (item) {
                targetStateMap.set(String(item.layer_id), item.choice_id);
            });
            pendingTargetState = targetStateMap;

            var queueSelections = userSelections.filter(function (item) {
                var prev = currentConfigState.has(String(item.layer_id))
                    ? currentConfigState.get(String(item.layer_id))
                    : null;
                return prev !== item.choice_id;
            });

            if (!queueSelections.length) {
                savePresetConfiguration(
                    productId,
                    presetName,
                    expandedConfiguration,
                    function () {
                        currentConfigState = new Map();
                        userSelections.forEach(function (choice) {
                            currentConfigState.set(String(choice.layer_id), choice.choice_id);
                        });
                        callback();
                    },
                );
                return;
            }

            var queue = queueSelections.slice();

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
                if (isRunCancelled()) {
                    setTimeout(function(){ callback && callback(); }, 0);
                    return;
                }
                if (!queue.length) {
                    setTimeout(function () {
                        if (isRunCancelled()) {
                            callback && callback();
                            return;
                        }
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

            combinationQueue = [];
            combinationProcessing = false;
            pendingBatchMeta = null;
            pendingTargetState = null;
            clearBatchRetry();

            var $container = $(".mkl-pc-bulk-generator");
            var $progress = $container.find(".mkl-pc-bulk-generator-progress");
            var $progressBar = $progress.find(".progress-bar");
            var $progressStatus = $progress.find(".progress-status");
            var productIdForRefresh = currentRun ? currentRun.productId : null;
            var cancelled = !!options.cancelled;
            var errored = !!options.error;
            var savedTotalClient = runMetrics ? runMetrics.totalPresets : totalGenerated;
            var savedTotalServer = runMetrics && typeof runMetrics.serverSavedTotal === "number"
                ? runMetrics.serverSavedTotal
                : savedTotalClient;
            var savedTotal = Math.max(savedTotalClient, savedTotalServer);
            var attemptedTotalClient = runMetrics && typeof runMetrics.attemptedTotal === "number"
                ? runMetrics.attemptedTotal
                : totalGenerated;
            var attemptedTotalServer = runMetrics && typeof runMetrics.serverAttemptedTotal === "number"
                ? runMetrics.serverAttemptedTotal
                : attemptedTotalClient;
            var attemptedTotal = Math.max(attemptedTotalClient, attemptedTotalServer);

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
            var completionSummary = finalMessage +
                " • Saved " + formatNumber(savedTotal) +
                (attemptedTotal > savedTotal
                    ? " (attempted " + formatNumber(attemptedTotal) + ")"
                    : "");
            var skippedSummary = runMetrics
                ? (runMetrics.skippedDuplicates || 0) + (runMetrics.skippedServer || 0)
                : 0;
            if (skippedSummary > 0) {
                completionSummary += " • Skipped " + formatNumber(skippedSummary);
            }
            $progressStatus.text(completionSummary);
            var existingTotal = runMetrics
                ? runMetrics.initialExisting + savedTotal
                : savedTotal;
            $container.find('[data-stat="generated"]').text(
                formatNumber(savedTotal),
            );
            updateExistingStat(existingTotal);
            MKL_PC_BulkGenerator.existing_total = existingTotal;
            setStatus(finalMessage, statusTone);

            if (presetSnapshot && presetSnapshot.productId === productIdForRefresh) {
                presetSnapshot.count = existingTotal;
                presetSnapshot.titles = knownPresetTitles;
            }

            var skippedText = skippedSummary > 0
                ? " (" + formatNumber(skippedSummary) + " skipped)"
                : "";

            if (cancelled) {
                appendLog(
                    "Generation cancelled after saving " + formatNumber(savedTotal) +
                        " presets (attempted " + formatNumber(attemptedTotal) + ")" +
                        skippedText +
                        ".",
                    "warn",
                    { force: true },
                );
            } else if (errored) {
                appendLog(
                    "Generation stopped after saving " + formatNumber(savedTotal) +
                        " presets (attempted " + formatNumber(attemptedTotal) + ")" +
                        skippedText +
                        ".",
                    "error",
                    { force: true },
                );
            } else {
                appendLog(
                    "Generation complete: saved " + formatNumber(savedTotal) +
                        " presets (attempted " + formatNumber(attemptedTotal) +
                        (skippedSummary > 0
                            ? ", " + formatNumber(skippedSummary) + " skipped"
                            : "") +
                        ").",
                    "success",
                    { force: true },
                );
            }

            if (runMetrics) {
                runMetrics.serverSavedTotal = Math.max(
                    runMetrics.serverSavedTotal || 0,
                    savedTotal,
                );
                runMetrics.serverAttemptedTotal = Math.max(
                    runMetrics.serverAttemptedTotal || 0,
                    attemptedTotal,
                );
                runMetrics.attemptedTotal = Math.max(
                    runMetrics.attemptedTotal || 0,
                    attemptedTotal,
                );

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
                    chunkSize: runMetrics.chunkSize,
                    batches: runMetrics.batches.length,
                    avgBatchMs: Math.round(average(runMetrics.batches)),
                    saves: runMetrics.saveDurations.length,
                    avgSaveMs: Math.round(average(runMetrics.saveDurations)),
                    applyCalls: runMetrics.applyDurations.length,
                    avgApplyMs: Math.round(average(runMetrics.applyDurations)),
                    asyncThumbnails: runMetrics.asyncThumbnails || 0,
                    skippedServer: runMetrics.skippedServer || 0,
                    skippedDuplicates: runMetrics.skippedDuplicates || 0,
                    attemptedTotal: runMetrics.attemptedTotal || 0,
                    initialExisting: runMetrics.initialExisting || 0,
                });
                console.log("Batch AJAX durations (ms):", runMetrics.batches);
                console.log("Apply durations (ms):", runMetrics.applyDurations);
                console.log("Save AJAX durations (ms):", runMetrics.saveDurations);
                console.groupEnd();
            }

            scheduleStatsUpdate();
            if (runMetrics) {
                runMetrics.runId = null;
            }
            currentRun = null;
        }
    }
})(jQuery, _);
