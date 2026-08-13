# Changelog

All notable changes to `mdzahid-pro/lsm-tree` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- `CompactionStalledException`, raised when a compaction policy never stops
  returning a plan. A policy with a termination bug now fails with a diagnostic
  instead of spinning forever.
- `authors`, `support` and `homepage` metadata, so the package advertises a
  maintainer and somewhere to report bugs.
- A "What it looks like in practice" section in `README.md`: four worked
  scenarios and the segment driver each one wants.

### Changed

- Renamed the package from `lsm-tree/laravel` to `mdzahid-pro/lsm-tree`, which
  is the repository it actually lives in. Install with
  `composer require mdzahid-pro/lsm-tree`. No namespace, class or config key
  changed.

### Fixed

- Compaction stored the merged run twice on the `memory` driver. Every merge
  doubled that run's storage, inflated read amplification and overreported
  `statistics()->runs`. With `compaction.max_runs_per_level` set to 2 the extra
  copy held the level at its threshold and `compact()` never returned.
- `SegmentStoreInterface::replace()` now documents that implementations must not
  store the replacement, which `write()` has already stored. The three shipped
  drivers had read the undocumented parameter three different ways.
- A rejected import line now names the line it came from. An unknown operation
  type, an empty type column and a valueless `put` previously raised errors with
  no source context.

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

[Unreleased]: https://github.com/mdzahid-pro/lsm-tree/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/mdzahid-pro/lsm-tree/releases/tag/v1.0.0
