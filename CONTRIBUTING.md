# Contributing

## Getting set up

```bash
composer install
composer check     # pint, phpstan level 8, phpunit
```

## What a good pull request looks like

- One concern per pull request.
- A test that fails before the change and passes after it.
- `composer check` green.
- A `CHANGELOG.md` entry under `## [Unreleased]`.

## Things to know before changing the engine

**Two rules keep this correct.** Read them in `README.md` under "Two
correctness rules worth knowing" before touching `SizeTieredCompactionPolicy`,
`SegmentMerger` or sequence generation. Both have tests named after the bug
they prevent; if one of those tests starts failing, the change is wrong, not
the test.

**Compaction must stay streaming.** `SegmentMerger::merge()` holds one entry
per input run. Any change that collects entries into an array before yielding
them turns a background job into an out-of-memory crash on a large level.

**The core may not import Illuminate.** Everything outside `src/Laravel` is
plain PHP and is tested without booting an application. If a change needs a
framework service, it belongs behind a contract with the adapter in
`src/Laravel`.

## Adding a driver

A driver in this repository needs the same test coverage as the existing ones:
round-tripping writes, surviving a restart, and keeping deleted keys deleted
through compaction. If it is specific to your infrastructure, register it from
your own application with `Lsm::extendSegments()` instead — that is what the
extension point is for.

## Reporting a bug

Include the store configuration, the driver, and the output of
`php artisan lsm:stats --json`. A failing test is worth more than a
description.
