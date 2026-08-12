# Changelog

All notable changes to `lsm-tree/laravel` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0] - 2026-08-12

Initial release.

### Added

- `LsmTree` engine: append-only writes, immutable sorted runs, size-tiered
  compaction, tombstone deletes and write-ahead logging.
- Segment store drivers: `memory`, `file` (sorted files with a sparse index and
  an atomically replaced manifest) and `database` (transactional compaction).
- Write-ahead log drivers: `none`, `memory`, `file` and `database`.
- Packed Bloom filters, sized from bits-per-key and built while streaming.
- Streaming k-way merge: compaction holds one entry per input run rather than
  one per key in the level.
- `LsmManager` with named stores, plus `extendSegments()` and `extendWal()` for
  drivers the package does not ship.
- `Lsm` facade and container bindings for `KeyValueStoreInterface` and
  `MaintenanceInterface`.
- Maintenance locking through Laravel's atomic cache locks.
- `MemTableFlushed` and `CompactionCompleted` events; optional PSR-3 logging
  with per-type filtering.
- Queued `RunCompaction` job, unique per store.
- Artisan commands: `lsm:install`, `lsm:stats`, `lsm:flush`, `lsm:compact`,
  `lsm:prune`, `lsm:import`, `lsm:get`, `lsm:put`, `lsm:forget`.
- Import from JSON Lines, NDJSON, CSV and TSV, from a local path or any disk.

[Unreleased]: https://github.com/lsm-tree/laravel/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/lsm-tree/laravel/releases/tag/v1.0.0
