<?php

/**
 * Bulk run manager
 *
 * Coordinates shared run state, locking, reservations, and logging so that
 * both the admin UI and background workers (CLI, cron, etc.) can co-operate
 * on the same generation jobs.
 */

if (! defined('ABSPATH')) {
    exit;
}

class MKL_PC_Preset_Run_Manager
{
    const STATUS_QUEUED = 'queued';
    const STATUS_ACTIVE = 'active';
    const STATUS_PAUSED = 'paused';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_COMPLETE = 'complete';

    const LOG_MAX_BYTES = 400000;
    const LOG_MAX_ENTRIES = 250;

    /**
     * Singleton instance.
     *
     * @var MKL_PC_Preset_Run_Manager|null
     */
    private static $instance = null;

    /**
     * Retrieve singleton instance.
     *
     * @return MKL_PC_Preset_Run_Manager
     */
    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        // Intentionally left blank.
    }

    /**
     * Ensure a run exists (or create a new one) for the given product.
     *
     * @param int   $product_id
     * @param array $args
     * @return array Payload describing the run.
     */
    public function begin_run($product_id, array $args = [])
    {
        $product_id = (int) $product_id;
        if ($product_id <= 0) {
            throw new InvalidArgumentException('Invalid product ID.');
        }

        $chunk_size = isset($args['chunk_size']) ? (int) $args['chunk_size'] : 0;
        $force_new = !empty($args['force_new']);

        $config = [
            'max_presets' => isset($args['max_presets']) ? max(0, (int) $args['max_presets']) : 0,
            'ignore_existing' => !empty($args['ignore_existing']),
            'throttle' => isset($args['throttle']) ? max(0, (int) $args['throttle']) : 0,
        ];

        $lock_token = $this->acquire_lock($product_id);
        if (! $lock_token) {
            throw new RuntimeException(__('Unable to initialise run. Please retry.', 'mkl-pc-preset-generator'));
        }

        try {
            $state = $this->get_state($product_id);

            if ($force_new || $this->should_reset_state($state)) {
                $state = $this->create_state($product_id, $chunk_size, $config);
                $this->append_log_entry($state, 'info', sprintf(__('Started new run (%s).', 'mkl-pc-preset-generator'), $state['run_id']));
            } else {
                if (!is_array($state)) {
                    $state = $this->create_state($product_id, $chunk_size, $config);
                    $this->append_log_entry($state, 'info', sprintf(__('Initialised run (%s).', 'mkl-pc-preset-generator'), $state['run_id']));
                } else {
                    $state['config'] = array_merge($state['config'], array_filter($config, function ($value) {
                        return $value !== 0 && $value !== false;
                    }));

                    if ($chunk_size > 0 && empty($state['chunk_size_locked'])) {
                        $state['chunk_size'] = $this->normalize_batch_size($chunk_size, $product_id);
                        $state['chunk_size_locked'] = true;
                    }

                    if (empty($state['status']) || in_array($state['status'], [self::STATUS_CANCELLED, self::STATUS_COMPLETE], true)) {
                        $state = $this->create_state($product_id, $chunk_size, $state['config']);
                        $this->append_log_entry($state, 'info', sprintf(__('Restarted run (%s).', 'mkl-pc-preset-generator'), $state['run_id']));
                    }
                }
            }

            $state['status'] = self::STATUS_ACTIVE;
            $state['updated_at'] = time();

            $this->save_state($product_id, $state);

            return $this->prepare_payload($state);
        } finally {
            $this->release_lock($product_id, $lock_token);
        }
    }

    /**
     * Reserve the next available batch for processing.
     *
     * @param int    $product_id
     * @param string $run_id
     * @param array  $args
     * @return array
     */
    public function reserve_batch($product_id, $run_id, array $args = [])
    {
        $product_id = (int) $product_id;
        $requested_chunk = isset($args['chunk_size']) ? (int) $args['chunk_size'] : 0;

        $lock_token = $this->acquire_lock($product_id);
        if (! $lock_token) {
            return [
                'status' => 'busy',
                'reservation' => null,
                'state' => null,
                'message' => __('Unable to acquire run lock.', 'mkl-pc-preset-generator'),
            ];
        }

        try {
            $state = $this->get_state($product_id);
            if (!is_array($state) || empty($state['run_id']) || $state['run_id'] !== $run_id) {
                return [
                    'status' => 'mismatch',
                    'reservation' => null,
                    'state' => null,
                    'message' => __('Run context no longer available. Please start a new run.', 'mkl-pc-preset-generator'),
                ];
            }

            $this->cleanup_expired_reservations($state);

            if ($requested_chunk > 0 && empty($state['chunk_size_locked'])) {
                $state['chunk_size'] = $this->normalize_batch_size($requested_chunk, $product_id);
                $state['chunk_size_locked'] = true;
            }

            $chunk_size = isset($state['chunk_size'])
                ? (int) $state['chunk_size']
                : $this->normalize_batch_size($requested_chunk, $product_id);

            if ($chunk_size < 1) {
                $chunk_size = $this->normalize_batch_size($chunk_size, $product_id);
                $state['chunk_size'] = $chunk_size;
            }

            if ($state['status'] !== self::STATUS_ACTIVE) {
                $payload = $this->prepare_payload($state);
                $this->save_state($product_id, $state);

                return [
                    'status' => $state['status'],
                    'reservation' => null,
                    'state' => $payload,
                    'message' => __('Run is not active.', 'mkl-pc-preset-generator'),
                ];
            }

            if (!empty($state['config']['max_presets']) && isset($state['saved_total']) && $state['saved_total'] >= $state['config']['max_presets']) {
                $state['limit_reached'] = true;
                $state['status'] = self::STATUS_COMPLETE;
                $state['completed_at'] = time();
                $this->append_log_entry($state, 'info', __('Preset limit reached. Marking run complete.', 'mkl-pc-preset-generator'));
                $payload = $this->prepare_payload($state);
                $this->save_state($product_id, $state);

                return [
                    'status' => $state['status'],
                    'reservation' => null,
                    'state' => $payload,
                    'message' => __('Preset limit reached.', 'mkl-pc-preset-generator'),
                ];
            }

            $reservation = $this->claim_next_reservation($state, $chunk_size, $run_id);
            if (! $reservation) {
                if ($this->can_mark_complete($state)) {
                    $state['status'] = self::STATUS_COMPLETE;
                    $state['completed_at'] = time();
                    $this->append_log_entry($state, 'info', __('No remaining batches. Run completed.', 'mkl-pc-preset-generator'));
                }

                $payload = $this->prepare_payload($state);
                $this->save_state($product_id, $state);

                return [
                    'status' => $state['status'],
                    'reservation' => null,
                    'state' => $payload,
                    'message' => __('No remaining batches.', 'mkl-pc-preset-generator'),
                ];
            }

            $state['updated_at'] = time();
            $this->save_state($product_id, $state);

            return [
                'status' => $state['status'],
                'reservation' => $reservation,
                'state' => $this->prepare_payload($state),
                'message' => '',
            ];
        } finally {
            $this->release_lock($product_id, $lock_token);
        }
    }

    /**
     * Finish a reservation after it has been processed.
     *
     * @param int    $product_id
     * @param string $run_id
     * @param string $reservation_id
     * @param array  $meta
     * @return array|null Updated payload or null on mismatch.
     */
    public function complete_reservation($product_id, $run_id, $reservation_id, array $meta = [])
    {
        $product_id = (int) $product_id;
        $attempted = isset($meta['attempted']) ? (int) $meta['attempted'] : 0;
        $saved = isset($meta['saved']) ? (int) $meta['saved'] : 0;
        $skipped = isset($meta['skipped']) ? (int) $meta['skipped'] : 0;
        $limit = isset($meta['limit']) ? (int) $meta['limit'] : 0;
        $offset = isset($meta['offset']) ? (int) $meta['offset'] : 0;

        $lock_token = $this->acquire_lock($product_id);
        if (! $lock_token) {
            return null;
        }

        try {
            $state = $this->get_state($product_id);
            if (!is_array($state) || empty($state['run_id']) || $state['run_id'] !== $run_id) {
                return null;
            }

            $this->cleanup_expired_reservations($state);

            $this->finalize_reservation(
                $state,
                $reservation_id,
                $offset,
                $attempted,
                $limit,
                [
                    'saved' => $saved,
                    'skipped' => $skipped,
                ]
            );

            if ($saved > 0) {
                $this->append_log_entry(
                    $state,
                    'success',
                    sprintf(__('Saved %d preset(s) from reservation %s.', 'mkl-pc-preset-generator'), $saved, $reservation_id),
                    ['offset' => $offset, 'limit' => $limit]
                );
            }

            if ($skipped > 0) {
                $this->append_log_entry(
                    $state,
                    'info',
                    sprintf(__('Skipped %d combinations.', 'mkl-pc-preset-generator'), $skipped),
                    ['offset' => $offset, 'limit' => $limit]
                );
            }

            if (!empty($state['config']['max_presets']) && $state['saved_total'] >= $state['config']['max_presets']) {
                $state['limit_reached'] = true;
                $state['status'] = self::STATUS_COMPLETE;
                $state['completed_at'] = time();
                $this->append_log_entry($state, 'info', __('Preset limit reached. Marking run complete.', 'mkl-pc-preset-generator'));
            } elseif ($this->can_mark_complete($state)) {
                $state['status'] = self::STATUS_COMPLETE;
                $state['completed_at'] = time();
                $this->append_log_entry($state, 'info', __('All reservations completed.', 'mkl-pc-preset-generator'));
            }

            $state['updated_at'] = time();

            $this->save_state($product_id, $state);

            return $this->prepare_payload($state);
        } finally {
            $this->release_lock($product_id, $lock_token);
        }
    }

    /**
     * Release a reservation and re-queue its offset.
     *
     * @param int    $product_id
     * @param string $run_id
     * @param string $reservation_id
     * @return array|null Updated payload or null on mismatch.
     */
    public function release_reservation($product_id, $run_id, $reservation_id)
    {
        $product_id = (int) $product_id;
        $lock_token = $this->acquire_lock($product_id);
        if (! $lock_token) {
            return null;
        }

        try {
            $state = $this->get_state($product_id);
            if (!is_array($state) || empty($state['run_id']) || $state['run_id'] !== $run_id) {
                return null;
            }

            $this->release_reservation_offset($state, $reservation_id);
            $this->append_log_entry($state, 'warn', sprintf(__('Reservation %s released back to queue.', 'mkl-pc-preset-generator'), $reservation_id));

            $state['updated_at'] = time();
            $this->save_state($product_id, $state);

            return $this->prepare_payload($state);
        } finally {
            $this->release_lock($product_id, $lock_token);
        }
    }

    /**
     * Mark the run as paused.
     *
     * @param int    $product_id
     * @param string $run_id
     * @return array|null
     */
    public function pause_run($product_id, $run_id)
    {
        return $this->set_run_status($product_id, $run_id, self::STATUS_PAUSED);
    }

    /**
     * Mark the run as active again.
     *
     * @param int    $product_id
     * @param string $run_id
     * @return array|null
     */
    public function resume_run($product_id, $run_id)
    {
        return $this->set_run_status($product_id, $run_id, self::STATUS_ACTIVE);
    }

    /**
     * Cancel a run and clear reservations.
     *
     * @param int    $product_id
     * @param string $run_id
     * @return array|null
     */
    public function cancel_run($product_id, $run_id)
    {
        $product_id = (int) $product_id;
        $lock_token = $this->acquire_lock($product_id);
        if (! $lock_token) {
            return null;
        }

        try {
            $state = $this->get_state($product_id);
            if (!is_array($state) || empty($state['run_id']) || $state['run_id'] !== $run_id) {
                return null;
            }

            $state['status'] = self::STATUS_CANCELLED;
            $state['cancelled'] = true;
            $state['reservations'] = [];
            $state['pending_offsets'] = [];
            $state['updated_at'] = time();
            $state['completed_at'] = time();

            $this->append_log_entry($state, 'warn', __('Run cancelled.', 'mkl-pc-preset-generator'));

            $this->save_state($product_id, $state);

            return $this->prepare_payload($state);
        } finally {
            $this->release_lock($product_id, $lock_token);
        }
    }

    /**
     * Retrieve raw run state.
     *
     * @param int $product_id
     * @return array|null
     */
    public function get_state($product_id)
    {
        $state = get_option($this->get_state_option_key($product_id), null);
        if (!is_array($state)) {
            return null;
        }

        if (!isset($state['log']) || !is_array($state['log'])) {
            $state['log'] = [];
            $state['log_bytes'] = 0;
        }

        if (!isset($state['config']) || !is_array($state['config'])) {
            $state['config'] = [
                'max_presets' => 0,
                'ignore_existing' => false,
                'throttle' => 0,
            ];
        }

        return $state;
    }

    /**
     * Persist run state.
     *
     * @param int   $product_id
     * @param array $state
     * @return void
     */
    public function save_state($product_id, array $state)
    {
        update_option($this->get_state_option_key($product_id), $state, false);
    }

    /**
     * Remove stored run state and related locks for a product.
     *
     * @param int $product_id
     * @return void
     */
    public function clear_state($product_id)
    {
        delete_option($this->get_state_option_key($product_id));
        delete_option($this->get_lock_option_key($product_id));
        delete_option('mkl_pc_bulk_offset_' . (int) $product_id);
    }

    /**
     * Retrieve prepared payload for the current state.
     *
     * @param int $product_id
     * @return array
     */
    public function get_payload($product_id)
    {
        $state = $this->get_state($product_id);
        return $this->prepare_payload($state);
    }

    /**
     * List all stored run states keyed by product ID.
     *
     * @return array<int, array>
     */
    public function list_runs()
    {
        global $wpdb;

        $prefix = $this->get_state_option_prefix();
        $like = $wpdb->esc_like($prefix) . '%';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                $like
            ),
            ARRAY_A
        );

        if (empty($rows)) {
            return [];
        }

        $states = [];

        foreach ($rows as $row) {
            $option = isset($row['option_name']) ? $row['option_name'] : '';
            if (strpos($option, $prefix) !== 0) {
                continue;
            }

            $product_id = (int) substr($option, strlen($prefix));
            if ($product_id <= 0) {
                continue;
            }

            $value = maybe_unserialize($row['option_value']);
            if (is_array($value)) {
                $states[$product_id] = $value;
            }
        }

        return $states;
    }

    /**
     * Convert state to payload safe for frontend/CLI consumers.
     *
     * @param array|null $state
     * @return array
     */
    public function prepare_payload($state)
    {
        if (!is_array($state)) {
            return [];
        }

        return [
            'run_id' => isset($state['run_id']) ? $state['run_id'] : '',
            'status' => isset($state['status']) ? $state['status'] : self::STATUS_QUEUED,
            'chunk_size' => isset($state['chunk_size']) ? (int) $state['chunk_size'] : 0,
            'next_offset' => isset($state['next_offset']) ? (int) $state['next_offset'] : 0,
            'attempted_total' => isset($state['attempted_total']) ? (int) $state['attempted_total'] : 0,
            'saved_total' => isset($state['saved_total']) ? (int) $state['saved_total'] : 0,
            'skipped_total' => isset($state['skipped_total']) ? (int) $state['skipped_total'] : 0,
            'pending' => isset($state['pending_offsets']) ? count((array) $state['pending_offsets']) : 0,
            'reservations' => isset($state['reservations']) ? count((array) $state['reservations']) : 0,
            'started_at' => isset($state['started_at']) ? (int) $state['started_at'] : 0,
            'updated_at' => isset($state['updated_at']) ? (int) $state['updated_at'] : 0,
            'completed_at' => isset($state['completed_at']) ? (int) $state['completed_at'] : 0,
            'is_complete' => !empty($state['status']) && $state['status'] === self::STATUS_COMPLETE,
            'is_exhausted' => !empty($state['is_exhausted']),
            'limit_reached' => !empty($state['limit_reached']),
            'config' => isset($state['config']) ? $state['config'] : [],
            'log' => isset($state['log']) ? $state['log'] : [],
        ];
    }

    /**
     * Acquire lock helper.
     *
     * @param int $product_id
     * @param int $timeout
     * @return string|false
     */
    private function acquire_lock($product_id, $timeout = 5)
    {
        $lock_key = $this->get_lock_option_key($product_id);
        $token = uniqid('lock_', true);
        $attempt_until = time() + max(1, (int) $timeout);
        $lock_ttl = 10;

        do {
            $existing = get_option($lock_key);
            if (is_array($existing) && isset($existing['token'], $existing['expires'])) {
                if ((int) $existing['expires'] < time()) {
                    delete_option($lock_key);
                    $existing = false;
                }
            } elseif (!empty($existing)) {
                delete_option($lock_key);
                $existing = false;
            }

            if (false === $existing) {
                $stored = [
                    'token' => $token,
                    'expires' => time() + $lock_ttl,
                ];
                if (add_option($lock_key, $stored, '', 'no')) {
                    return $token;
                }
            }

            usleep(150000);
        } while (time() < $attempt_until);

        return false;
    }

    /**
     * Release lock helper.
     *
     * @param int    $product_id
     * @param string $token
     * @return void
     */
    private function release_lock($product_id, $token)
    {
        if (!$token) {
            return;
        }

        $lock_key = $this->get_lock_option_key($product_id);
        $existing = get_option($lock_key);
        if (is_array($existing) && isset($existing['token']) && $existing['token'] === $token) {
            delete_option($lock_key);
        }
    }

    /**
     * Determine whether stored state should be reset.
     *
     * @param array|null $state
     * @return bool
     */
    private function should_reset_state($state)
    {
        if (!is_array($state) || empty($state['run_id'])) {
            return true;
        }

        if (!empty($state['cancelled']) || (!empty($state['status']) && in_array($state['status'], [self::STATUS_CANCELLED, self::STATUS_COMPLETE], true))) {
            return true;
        }

        $updated_at = isset($state['updated_at']) ? (int) $state['updated_at'] : 0;
        $ttl = isset($state['reservation_ttl']) ? (int) $state['reservation_ttl'] : 120;
        $idle_limit = max($ttl * 4, 600);

        if ($updated_at > 0 && (time() - $updated_at) > $idle_limit) {
            return true;
        }

        return false;
    }

    /**
     * Create fresh run state.
     *
     * @param int   $product_id
     * @param int   $chunk_size
     * @param array $config
     * @return array
     */
    private function create_state($product_id, $chunk_size = 0, array $config = [])
    {
        $chunk_size = $this->normalize_batch_size($chunk_size, $product_id);

        $reservation_ttl = (int) apply_filters(
            'mkl_pc_preset_generator_reservation_ttl',
            120,
            $product_id
        );

        $state = [
            'version' => 2,
            'run_id' => wp_generate_uuid4(),
            'product_id' => $product_id,
            'status' => self::STATUS_ACTIVE,
            'chunk_size' => $chunk_size,
            'chunk_size_locked' => $chunk_size > 0,
            'next_offset' => 0,
            'attempted_total' => 0,
            'saved_total' => 0,
            'skipped_total' => 0,
            'started_at' => time(),
            'updated_at' => time(),
            'reservations' => [],
            'pending_offsets' => [],
            'reservation_ttl' => max(30, $reservation_ttl),
            'total_batches' => 0,
            'completed_chunks' => 0,
            'is_complete' => false,
            'is_exhausted' => false,
            'limit_reached' => false,
            'log' => [],
            'log_bytes' => 0,
            'config' => wp_parse_args(
                $config,
                [
                    'max_presets' => 0,
                    'ignore_existing' => false,
                    'throttle' => 0,
                ]
            ),
        ];

        return $state;
    }

    /**
     * Remove stale reservations and recycle their offsets.
     *
     * @param array $state
     * @return void
     */
    private function cleanup_expired_reservations(array &$state)
    {
        if (empty($state['reservations']) || !is_array($state['reservations'])) {
            $state['reservations'] = [];
            return;
        }

        $ttl = isset($state['reservation_ttl']) ? (int) $state['reservation_ttl'] : 120;
        $now = time();
        $pending = isset($state['pending_offsets']) && is_array($state['pending_offsets'])
            ? $state['pending_offsets']
            : [];

        foreach ($state['reservations'] as $id => $reservation) {
            $started_at = isset($reservation['started_at']) ? (int) $reservation['started_at'] : 0;
            if ($started_at > 0 && ($now - $started_at) > $ttl) {
                $offset = isset($reservation['offset']) ? (int) $reservation['offset'] : 0;
                if ($offset >= 0) {
                    $pending[] = $offset;
                }

                unset($state['reservations'][$id]);

                $this->append_log_entry(
                    $state,
                    'warn',
                    sprintf(__('Released expired reservation %s.', 'mkl-pc-preset-generator'), $id),
                    ['offset' => $offset]
                );
            }
        }

        if (!empty($pending)) {
            $pending = array_values(array_unique(array_map('intval', $pending)));
            sort($pending, SORT_NUMERIC);
            $state['pending_offsets'] = $pending;
        } else {
            $state['pending_offsets'] = [];
        }
    }

    /**
     * Create a reservation for the next chunk.
     *
     * @param array  $state
     * @param int    $chunk_size
     * @param string $run_id
     * @return array|null
     */
    private function claim_next_reservation(array &$state, $chunk_size, $run_id)
    {
        $chunk_size = max(1, (int) $chunk_size);
        $reservation_id = uniqid('res_', true);

        if (!isset($state['pending_offsets']) || !is_array($state['pending_offsets'])) {
            $state['pending_offsets'] = [];
        }

        $offset = null;
        if (!empty($state['pending_offsets'])) {
            sort($state['pending_offsets'], SORT_NUMERIC);
            $offset = array_shift($state['pending_offsets']);
        }

        if ($offset === null) {
            $offset = isset($state['next_offset']) ? (int) $state['next_offset'] : 0;
            $state['next_offset'] = $offset + $chunk_size;
        }

        if (!isset($state['reservations']) || !is_array($state['reservations'])) {
            $state['reservations'] = [];
        }

        $state['reservations'][$reservation_id] = [
            'offset' => $offset,
            'limit' => $chunk_size,
            'run_id' => $run_id,
            'started_at' => time(),
        ];

        return [
            'id' => $reservation_id,
            'offset' => $offset,
            'limit' => $chunk_size,
        ];
    }

    /**
     * Finalise reservation stats.
     *
     * @param array  $state
     * @param string $reservation_id
     * @param int    $offset
     * @param int    $produced
     * @param int    $limit
     * @param array  $meta
     * @return void
     */
    private function finalize_reservation(array &$state, $reservation_id, $offset, $produced, $limit, array $meta = [])
    {
        if (isset($state['reservations'][$reservation_id])) {
            unset($state['reservations'][$reservation_id]);
        }

        $state['attempted_total'] = isset($state['attempted_total'])
            ? (int) $state['attempted_total'] + max(0, (int) $produced)
            : max(0, (int) $produced);

        $state['total_batches'] = isset($state['total_batches'])
            ? (int) $state['total_batches'] + 1
            : 1;

        $state['completed_chunks'] = isset($state['completed_chunks'])
            ? (int) $state['completed_chunks'] + 1
            : 1;

        if (isset($meta['skipped'])) {
            $state['skipped_total'] = isset($state['skipped_total'])
                ? (int) $state['skipped_total'] + max(0, (int) $meta['skipped'])
                : max(0, (int) $meta['skipped']);
        }

        if (isset($meta['saved'])) {
            $state['saved_total'] = isset($state['saved_total'])
                ? (int) $state['saved_total'] + max(0, (int) $meta['saved'])
                : max(0, (int) $meta['saved']);
        }

        if ((int) $produced < (int) $limit) {
            $state['is_exhausted'] = true;
        }

        $state['last_offset'] = $offset;
        $state['last_limit'] = $limit;
    }

    /**
     * Release reservation offset back to queue.
     *
     * @param array  $state
     * @param string $reservation_id
     * @return void
     */
    private function release_reservation_offset(array &$state, $reservation_id)
    {
        if (!isset($state['reservations'][$reservation_id])) {
            return;
        }

        $reservation = $state['reservations'][$reservation_id];
        unset($state['reservations'][$reservation_id]);

        if (!isset($state['pending_offsets']) || !is_array($state['pending_offsets'])) {
            $state['pending_offsets'] = [];
        }

        $offset = isset($reservation['offset']) ? (int) $reservation['offset'] : null;
        if ($offset !== null) {
            $state['pending_offsets'][] = $offset;
            $state['pending_offsets'] = array_values(array_unique(array_map('intval', $state['pending_offsets'])));
            sort($state['pending_offsets'], SORT_NUMERIC);
        }
    }

    /**
     * Append a log entry and trim history.
     *
     * @param array  $state
     * @param string $level
     * @param string $message
     * @param array  $context
     * @return void
     */
    private function append_log_entry(array &$state, $level, $message, array $context = [])
    {
        $entry = [
            'time' => time(),
            'level' => $level,
            'message' => $message,
        ];

        if (!empty($context)) {
            $entry['context'] = $context;
        }

        if (!isset($state['log']) || !is_array($state['log'])) {
            $state['log'] = [];
            $state['log_bytes'] = 0;
        }

        $encoded = wp_json_encode($entry);
        $bytes = strlen($encoded);

        $state['log'][] = $entry;
        $state['log_bytes'] = isset($state['log_bytes']) ? (int) $state['log_bytes'] + $bytes : $bytes;

        while ($state['log_bytes'] > self::LOG_MAX_BYTES && count($state['log']) > 1) {
            $removed = array_shift($state['log']);
            $state['log_bytes'] -= strlen(wp_json_encode($removed));
        }

        if (count($state['log']) > self::LOG_MAX_ENTRIES) {
            $excess = count($state['log']) - self::LOG_MAX_ENTRIES;
            $removed_entries = array_splice($state['log'], 0, $excess);
            foreach ($removed_entries as $removed_entry) {
                $state['log_bytes'] -= strlen(wp_json_encode($removed_entry));
            }
        }
    }

    /**
     * Helper to set run status.
     *
     * @param int    $product_id
     * @param string $run_id
     * @param string $status
     * @return array|null
     */
    private function set_run_status($product_id, $run_id, $status)
    {
        $product_id = (int) $product_id;
        $lock_token = $this->acquire_lock($product_id);
        if (! $lock_token) {
            return null;
        }

        try {
            $state = $this->get_state($product_id);
            if (!is_array($state) || empty($state['run_id']) || $state['run_id'] !== $run_id) {
                return null;
            }

            $state['status'] = $status;
            $state['updated_at'] = time();

            $label = $status === self::STATUS_ACTIVE ? __('Run resumed.', 'mkl-pc-preset-generator') : __('Run paused.', 'mkl-pc-preset-generator');
            $this->append_log_entry($state, 'info', $label);

            $this->save_state($product_id, $state);

            return $this->prepare_payload($state);
        } finally {
            $this->release_lock($product_id, $lock_token);
        }
    }

    /**
     * Determine whether a run can be marked complete.
     *
     * @param array $state
     * @return bool
     */
    private function can_mark_complete(array $state)
    {
        if (!empty($state['limit_reached'])) {
            return true;
        }

        $has_reservations = !empty($state['reservations']);
        $has_pending = !empty($state['pending_offsets']);

        if ($has_reservations || $has_pending) {
            return false;
        }

        if (!empty($state['is_exhausted'])) {
            return true;
        }

        return false;
    }

    /**
     * Option key helper for run state storage.
     *
     * @param int $product_id
     * @return string
     */
    private function get_state_option_key($product_id)
    {
        return $this->get_state_option_prefix() . (int) $product_id;
    }

    /**
     * Option key helper for run locking.
     *
     * @param int $product_id
     * @return string
     */
    private function get_lock_option_key($product_id)
    {
        return 'mkl_pc_bulk_lock_' . (int) $product_id;
    }

    /**
     * Prefix used for stored run options.
     *
     * @return string
     */
    private function get_state_option_prefix()
    {
        return 'mkl_pc_bulk_state_';
    }

    /**
     * Normalise batch size based on filters.
     *
     * @param int $requested
     * @param int $product_id
     * @return int
     */
    public function normalize_batch_size($requested, $product_id)
    {
        $default = (int) apply_filters('mkl_pc_preset_generator_batch_size', 50, $product_id);
        $max = (int) apply_filters('mkl_pc_preset_generator_max_batch_size', 250, $product_id);

        $batch_size = (int) $requested;
        if ($batch_size < 1) {
            $batch_size = $default;
        }

        if ($max > 0) {
            $batch_size = min($batch_size, $max);
        }

        return max(1, $batch_size);
    }
}
