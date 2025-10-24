<?php

/**
 * WP-CLI commands for the bulk preset generator.
 */

if (! defined('ABSPATH')) {
    exit;
}

class MKL_PC_Preset_Bulk_CLI_Commands
{
    /**
     * @var MKL_PC_Preset_Run_Manager
     */
    private $run_manager;

    public function __construct()
    {
        $this->run_manager = MKL_PC_Preset_Run_Manager::instance();
    }

    /**
     * Start (or restart) a run.
     *
     * ## OPTIONS
     *
     * <product-id>
     * : WooCommerce product ID.
     *
     * [--chunk=<size>]
     * : Override batch size.
     *
     * [--max-presets=<count>]
     * : Optional preset cap for this run.
     *
     * [--ignore-existing]
     * : Hint for workers to skip existing presets when saving (CLI workers enforce this automatically via hashes).
     *
     * [--force]
     * : Reset run state even if an active run exists.
     */
    public function start($args, $assoc_args)
    {
        $product_id = $this->resolve_product_id($args);
        $product = $this->get_product($product_id);

        $chunk = isset($assoc_args['chunk']) ? (int) $assoc_args['chunk'] : 0;
        $max_presets = isset($assoc_args['max-presets']) ? max(0, (int) $assoc_args['max-presets']) : 0;
        $force_new = WP_CLI\Utils\get_flag_value($assoc_args, 'force', false);
        $ignore_existing = WP_CLI\Utils\get_flag_value($assoc_args, 'ignore-existing', false);

        try {
            $payload = $this->run_manager->begin_run($product_id, [
                'chunk_size' => $chunk,
                'force_new' => $force_new,
                'max_presets' => $max_presets,
                'ignore_existing' => $ignore_existing,
            ]);
        } catch (Exception $e) {
            WP_CLI::error($e->getMessage());
        }

        WP_CLI::success(sprintf(
            'Run %s initialised for product %d (%s).',
            $payload['run_id'],
            $product_id,
            $product ? $product->post_title : 'Unknown'
        ));

        $this->render_payload($product_id, $payload);
    }

    /**
     * Pause an active run.
     *
     * ## OPTIONS
     *
     * <product-id>
     * : WooCommerce product ID.
     */
    public function pause($args, $assoc_args)
    {
        $product_id = $this->resolve_product_id($args);
        $state = $this->require_run_state($product_id);

        $payload = $this->run_manager->pause_run($product_id, $state['run_id']);
        if (! $payload) {
            WP_CLI::error('Unable to pause run (state mismatch).');
        }

        WP_CLI::success(sprintf('Run %s paused for product %d.', $payload['run_id'], $product_id));
        $this->render_payload($product_id, $payload);
    }

    /**
     * Resume a paused run.
     *
     * ## OPTIONS
     *
     * <product-id>
     * : WooCommerce product ID.
     */
    public function resume($args, $assoc_args)
    {
        $product_id = $this->resolve_product_id($args);
        $state = $this->require_run_state($product_id);

        $payload = $this->run_manager->resume_run($product_id, $state['run_id']);
        if (! $payload) {
            WP_CLI::error('Unable to resume run (state mismatch).');
        }

        WP_CLI::success(sprintf('Run %s resumed for product %d.', $payload['run_id'], $product_id));
        $this->render_payload($product_id, $payload);
    }

    /**
     * Cancel a run.
     *
     * ## OPTIONS
     *
     * <product-id>
     * : WooCommerce product ID.
     *
     * [--clear]
     * : Remove stored state immediately after cancellation.
     */
    public function cancel($args, $assoc_args)
    {
        $product_id = $this->resolve_product_id($args);
        $state = $this->require_run_state($product_id);

        $payload = $this->run_manager->cancel_run($product_id, $state['run_id']);
        if (! $payload) {
            WP_CLI::error('Unable to cancel run (state mismatch).');
        }

        WP_CLI::warning(sprintf('Run %s cancelled for product %d.', $payload['run_id'], $product_id));
        if (WP_CLI\Utils\get_flag_value($assoc_args, 'clear', false)) {
            $this->run_manager->clear_state($product_id);
            WP_CLI::log('Run state cleared.');
        } else {
            $this->render_payload($product_id, $payload);
        }
    }

    /**
     * Display run status.
     *
     * ## OPTIONS
     *
     * [<product-id>]
     * : WooCommerce product ID. When omitted, use --all.
     *
     * [--all]
     * : Show every tracked run.
     *
     * [--log[=<n>]]
     * : Display the last n log entries (default 5). Pass 0 to suppress.
     *
     * [--format=<format>]
     * : Output format; table|json|yaml. Default: table.
     */
    public function status($args, $assoc_args)
    {
        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';
        $log_count = WP_CLI\Utils\get_flag_value($assoc_args, 'log', 5);
        $log_count = ($log_count === false) ? 5 : (int) $log_count;

        if (WP_CLI\Utils\get_flag_value($assoc_args, 'all', false)) {
            $states = $this->run_manager->list_runs();
            if (empty($states)) {
                WP_CLI::log('No active runs found.');
                return;
            }

            $items = [];
            foreach ($states as $product_id => $state) {
                $payload = $this->run_manager->prepare_payload($state);
                $items[] = $this->format_payload_row($product_id, $payload);
                if ($log_count > 0) {
                    $this->render_logs($payload, $log_count);
                }
            }

            WP_CLI\Utils\format_items($format, $items, array_keys(reset($items)));
            return;
        }

        $product_id = $this->resolve_product_id($args, false);
        if (! $product_id) {
            WP_CLI::error('Please provide a product ID or use --all.');
        }

        $payload = $this->run_manager->get_payload($product_id);
        if (empty($payload)) {
            WP_CLI::warning(sprintf('No run state found for product %d.', $product_id));
            return;
        }

        WP_CLI\Utils\format_items($format, [$this->format_payload_row($product_id, $payload)], array_keys($this->format_payload_row($product_id, $payload)));

        if ($log_count > 0) {
            $this->render_logs($payload, $log_count);
        }
    }

    /**
     * Run a worker loop to process reservations for a product.
     *
     * ## OPTIONS
     *
     * <product-id>
     * : WooCommerce product ID.
     *
     * [--run=<id>]
     * : Run ID to join. Defaults to the current run.
     *
     * [--chunk=<size>]
     * : Override batch size for this worker session.
     *
     * [--max-batches=<n>]
     * : Stop after processing n reservations.
     *
     * [--max-seconds=<seconds>]
     * : Stop after n seconds.
     *
     * [--sleep=<seconds>]
     * : Sleep between reservations (fractional seconds allowed).
     *
     * [--skip-thumbnail]
     * : Skip thumbnail generation when saving presets.
     */
    public function worker($args, $assoc_args)
    {
        $product_id = $this->resolve_product_id($args);
        $state = $this->require_run_state($product_id);

        $run_id = isset($assoc_args['run']) ? sanitize_text_field($assoc_args['run']) : $state['run_id'];
        if ($run_id !== $state['run_id']) {
            WP_CLI::warning(sprintf('Run ID mismatch (current run ID: %s).', $state['run_id']));
        }

        $chunk = isset($assoc_args['chunk']) ? (int) $assoc_args['chunk'] : 0;
        $max_batches = isset($assoc_args['max-batches']) ? max(1, (int) $assoc_args['max-batches']) : 0;
        $max_seconds = isset($assoc_args['max-seconds']) ? max(1, (int) $assoc_args['max-seconds']) : 0;
        $sleep = isset($assoc_args['sleep']) ? max(0, (float) $assoc_args['sleep']) : 0;
        $skip_thumbnail = WP_CLI\Utils\get_flag_value($assoc_args, 'skip-thumbnail', false);

        $worker = new MKL_PC_Preset_Bulk_Worker($product_id, [
            'save_presets' => true,
            'expand_for_ui' => false,
            'skip_thumbnail' => $skip_thumbnail,
        ]);

        $start_time = microtime(true);
        $processed = 0;
        $saved_total = 0;
        $skipped_total = 0;
        $duplicate_total = 0;

        while (true) {
            $reservation_response = $this->run_manager->reserve_batch($product_id, $run_id, [
                'chunk_size' => $chunk,
            ]);

            if (!is_array($reservation_response)) {
                WP_CLI::error('Unexpected reservation response.');
            }

            $status = isset($reservation_response['status']) ? $reservation_response['status'] : '';
            $reservation = isset($reservation_response['reservation']) ? $reservation_response['reservation'] : null;
            $payload = isset($reservation_response['state']) ? $reservation_response['state'] : [];
            $message = isset($reservation_response['message']) ? $reservation_response['message'] : '';

            if (! $reservation) {
                if ($status === MKL_PC_Preset_Run_Manager::STATUS_ACTIVE && empty($payload['is_complete'])) {
                    if (!empty($message)) {
                        WP_CLI::log($message);
                    }
                } else {
                    WP_CLI::log($message !== '' ? $message : 'No reservations available.');
                }
                break;
            }

            if ($status !== MKL_PC_Preset_Run_Manager::STATUS_ACTIVE) {
                WP_CLI::warning(sprintf('Run status is %s. Halting worker.', $status));
                break;
            }

            $reservation_id = $reservation['id'];
            $offset = (int) $reservation['offset'];
            $limit = (int) $reservation['limit'];

            $processed++;

            try {
                $result = $worker->process($offset, $limit, [
                    'run_id' => $run_id,
                    'reservation_id' => $reservation_id,
                ]);
            } catch (Exception $e) {
                $this->run_manager->release_reservation($product_id, $run_id, $reservation_id);
                WP_CLI::error(sprintf('Processing failed: %s', $e->getMessage()));
            }

            $consumed = isset($result['attempted']) ? (int) $result['attempted'] : 0;
            $saved = isset($result['saved']) ? (int) $result['saved'] : 0;
            $skipped = isset($result['skipped']) ? (int) $result['skipped'] : 0;
            $duplicates = isset($result['duplicates']) ? (int) $result['duplicates'] : 0;
            $duration = isset($result['duration']) ? (float) $result['duration'] : 0.0;

            $saved_total += $saved;
            $skipped_total += $skipped;
            $duplicate_total += $duplicates;

            $updated_payload = $this->run_manager->complete_reservation($product_id, $run_id, $reservation_id, [
                'offset' => $offset,
                'limit' => $limit,
                'attempted' => $consumed,
                'saved' => $saved,
                'skipped' => $skipped,
            ]);

            if ($updated_payload) {
                $payload = $updated_payload;
            }

            WP_CLI::log(sprintf(
                '#%d Offset %d (%d) => Saved:%d Skipped:%d Duplicates:%d Time:%0.2fs',
                $processed,
                $offset,
                $limit,
                $saved,
                $skipped,
                $duplicates,
                $duration
            ));

            if ($max_batches > 0 && $processed >= $max_batches) {
                WP_CLI::warning('Max batches reached, stopping worker.');
                break;
            }

            if ($max_seconds > 0 && (microtime(true) - $start_time) >= $max_seconds) {
                WP_CLI::warning('Max runtime reached, stopping worker.');
                break;
            }

            if (!empty($payload['is_complete'])) {
                WP_CLI::success('Run marked complete.');
                break;
            }

            if ($sleep > 0) {
                usleep((int) round($sleep * 1e6));
            }
        }

        $elapsed = microtime(true) - $start_time;

        WP_CLI::success(sprintf(
            'Worker finished. Batches:%d Saved:%d Skipped:%d Duplicates:%d Duration:%0.2fs',
            $processed,
            $saved_total,
            $skipped_total,
            $duplicate_total,
            $elapsed
        ));
    }

    /**
     * Cleanup run state (clear stale or completed runs).
     *
     * ## OPTIONS
     *
     * [<product-id>]
     * : Optional product ID. When omitted with --all, clears every stale run.
     *
     * [--all]
     * : Apply to every run.
     *
     * [--force]
     * : Do not prompt before clearing.
     */
    public function cleanup($args, $assoc_args)
    {
        $force = WP_CLI\Utils\get_flag_value($assoc_args, 'force', false);

        if (WP_CLI\Utils\get_flag_value($assoc_args, 'all', false)) {
            $states = $this->run_manager->list_runs();
            if (empty($states)) {
                WP_CLI::log('No stored runs to cleanup.');
                return;
            }

            foreach ($states as $product_id => $state) {
                if (! $force) {
                    WP_CLI::confirm(sprintf('Clear run state for product %d?', $product_id));
                }
                $this->run_manager->clear_state($product_id);
                WP_CLI::log(sprintf('Cleared run state for product %d.', $product_id));
            }
            return;
        }

        $product_id = $this->resolve_product_id($args, false);
        if (! $product_id) {
            WP_CLI::error('Provide a product ID or use --all.');
        }

        if (! $force) {
            WP_CLI::confirm(sprintf('Clear run state for product %d?', $product_id));
        }

        $this->run_manager->clear_state($product_id);
        WP_CLI::success(sprintf('Cleared run state for product %d.', $product_id));
    }

    /**
     * Resolve product ID from CLI args.
     *
     * @param array $args
     * @param bool  $required
     * @return int|null
     */
    private function resolve_product_id(array $args, $required = true)
    {
        if (empty($args)) {
            if ($required) {
                WP_CLI::error('Missing product ID.');
            }
            return null;
        }

        $product_id = absint($args[0]);
        if ($product_id <= 0) {
            WP_CLI::error('Product ID must be a positive integer.');
        }

        return $product_id;
    }

    /**
     * Load product post.
     *
     * @param int $product_id
     * @return WP_Post|null
     */
    private function get_product($product_id)
    {
        $product = get_post($product_id);
        if (! $product) {
            WP_CLI::warning(sprintf('Product %d not found.', $product_id));
            return null;
        }

        return $product;
    }

    /**
     * Ensure run state exists for product.
     *
     * @param int $product_id
     * @return array
     */
    private function require_run_state($product_id)
    {
        $state = $this->run_manager->get_state($product_id);
        if (!is_array($state) || empty($state['run_id'])) {
            WP_CLI::error(sprintf('No active run for product %d.', $product_id));
        }

        return $state;
    }

    /**
     * Format payload row for table output.
     *
     * @param int   $product_id
     * @param array $payload
     * @return array
     */
    private function format_payload_row($product_id, array $payload)
    {
        $updated = isset($payload['updated_at']) ? (int) $payload['updated_at'] : 0;
        $started = isset($payload['started_at']) ? (int) $payload['started_at'] : 0;

        return [
            'product' => $product_id,
            'run_id' => isset($payload['run_id']) ? $payload['run_id'] : '',
            'status' => isset($payload['status']) ? $payload['status'] : '',
            'chunk' => isset($payload['chunk_size']) ? (int) $payload['chunk_size'] : 0,
            'attempted' => isset($payload['attempted_total']) ? (int) $payload['attempted_total'] : 0,
            'saved' => isset($payload['saved_total']) ? (int) $payload['saved_total'] : 0,
            'skipped' => isset($payload['skipped_total']) ? (int) $payload['skipped_total'] : 0,
            'pending' => isset($payload['pending']) ? (int) $payload['pending'] : 0,
            'active_res' => isset($payload['reservations']) ? (int) $payload['reservations'] : 0,
            'started' => $started ? date('Y-m-d H:i:s', $started) : '-',
            'updated' => $updated ? date('Y-m-d H:i:s', $updated) : '-',
        ];
    }

    /**
     * Render human-readable payload summary.
     *
     * @param int   $product_id
     * @param array $payload
     * @return void
     */
    private function render_payload($product_id, array $payload)
    {
        $row = $this->format_payload_row($product_id, $payload);
        WP_CLI\Utils\format_items('table', [$row], array_keys($row));
    }

    /**
     * Render trailing log entries.
     *
     * @param array $payload
     * @param int   $limit
     * @return void
     */
    private function render_logs(array $payload, $limit)
    {
        if (empty($payload['log']) || $limit <= 0) {
            return;
        }

        $entries = array_slice(array_reverse($payload['log']), 0, $limit);
        WP_CLI::log('Recent log entries:');
        foreach ($entries as $entry) {
            $time = isset($entry['time']) ? date('Y-m-d H:i:s', (int) $entry['time']) : '';
            $level = isset($entry['level']) ? strtoupper($entry['level']) : 'INFO';
            $message = isset($entry['message']) ? $entry['message'] : '';
            WP_CLI::log(sprintf('  [%s] %s %s', $time, $level, $message));
        }
    }
}

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('mkl-pc bulk', 'MKL_PC_Preset_Bulk_CLI_Commands');
}
