# WARP.md

This file provides guidance to WARP (warp.dev) when working with code in this repository.

Repository type: WordPress plugin that extends “Product Configurator for WooCommerce” with bulk preset generation via an admin UI and WP‑CLI.

Quick start (WP‑CLI)
- These commands operate on a specific WooCommerce product ID and coordinate via shared run state.

```bash path=null start=null
# Start (or restart) a run for product 123
wp mkl-pc bulk start 123 \
  --chunk=100 \
  --max-presets=0 \
  --ignore-existing \
  --force

# Check status for all runs (with recent logs)
wp mkl-pc bulk status --all --log=5 --format=table

# Process reservations for product 123 for 5 minutes (no thumbnails for speed)
wp mkl-pc bulk worker 123 \
  --chunk=150 \
  --max-seconds=300 \
  --skip-thumbnail

# Pause / resume / cancel and clear state
wp mkl-pc bulk pause 123
wp mkl-pc bulk resume 123
wp mkl-pc bulk cancel 123 --clear

# Cleanup stale run state for all products (no prompt)
wp mkl-pc bulk cleanup --all --force
```

Notes
- No local build/lint/test tooling is configured (no composer.json, phpunit.xml, package.json). There is no test suite.
- The admin UI appears on the Product Configurator presets page; you can estimate, start, and monitor runs there as well.
- Dependencies: Product Configurator core, Save Your Design, and Conditional Logic must be active (the plugin checks for the `mkl_pc` function).

Big‑picture architecture
- Entry point: `mkl-pc-preset-bulk-generator.php` defines constants, checks dependencies, loads components, and registers CLI commands when `WP_CLI` is available.
- Run coordination: `includes/class-run-manager.php`
  - Stores per‑product run state in WordPress options (status, chunk size, attempted/saved/skipped counts, pending/reservations, logs).
  - Provides locking, reservations (offset/limit), pause/resume/cancel, completion detection, and payload formatting for UI/CLI.
  - Tunables via filters: `mkl_pc_preset_generator_reservation_ttl`, `mkl_pc_preset_generator_batch_size`, `mkl_pc_preset_generator_max_batch_size`.
- Generation pipeline
  - Smart generator: `includes/class-smart-combination-generator.php` walks the search space with early pruning using conditional logic; yields valid combinations in batches.
  - Conditional validator: `includes/class-conditional-validator.php` evaluates rules from Product Configurator DB (`layers/content/conditions`) and supports partial checks to prune early.
  - Configuration builder: `includes/class-configuration-builder.php` converts a user‑choice combination into a complete configuration including visual/non‑choice layers, mirroring frontend behavior and ordering.
  - Preset saver: `includes/class-preset-saver.php` persists presets to `mkl_pc_configuration` (content as JSON), avoids duplicates via a configuration hash, and generates/attaches thumbnails. Default author can be overridden.
  - Bulk worker: `includes/class-bulk-worker.php` orchestrates a reservation (generate → validate → optionally save → return results for UI), enforcing required “core” layers.
- Admin UI: `includes/class-admin-ui.php`
  - Injects a control panel on the presets page, drives runs via AJAX, renders progress and stats, and includes fallbacks so configuration images preview even if sub‑sizes are missing.
  - AJAX actions include: `mkl_pc_generate_presets_estimate`, `mkl_pc_begin_generation_run`, `mkl_pc_cancel_generation_run`, `mkl_pc_generate_presets_batch`, `mkl_pc_get_preset_snapshot`, `mkl_pc_save_expanded_preset`, `mkl_pc_delete_all_presets`.
- CLI: `includes/class-cli-commands.php` registers `wp mkl-pc bulk` with subcommands `start`, `pause`, `resume`, `cancel`, `status`, `worker`, `cleanup` that operate against the shared run state.

Key extension points (filters)
- `mkl_pc_preset_generator_include_layer` (bool, layer, product_id): include/exclude a layer from user‑facing generation.
- `mkl_pc_preset_generator_user_layers` (array layers, product_id): final list of user layers for generation.
- `mkl_pc_preset_generator_core_layers` (array names, product_id): which layer names are treated as “core” (must have a real selection).
- `mkl_pc_preset_generator_batch_size` / `mkl_pc_preset_generator_max_batch_size` (ints): batch sizing; applies to both UI/CLI.
- `mkl_pc_preset_generator_reservation_ttl` (int seconds): TTL for reservations before they’re recycled.
- `mkl_pc_preset_generator_default_author` (int user_id, product_id): default author for saved presets when no current user.

Operational tips specific to this repo
- For large products, run multiple `worker` processes concurrently (separate shells) to accelerate saving; state/locking is handled by the run manager.
- Use `--skip-thumbnail` when throughput is more important than images; you can regenerate images later from the preset.
- If you’re not in the WordPress root, pass `--path=/path/to/wp` to `wp`.
