# Upgrading

## Backwards compatibility promise

The public API is:

- the `Lsm` facade and `LsmManager`
- every interface in `src/Contract`
- the value objects in `src/Model`
- the shape of `config/lsm.php`
- the on-disk and in-database formats of a segment

Everything else — the concrete drivers, the console commands' output, anything
under `src/Laravel` that is not listed above — may change in a minor release.

**Adding a method to a contract is a breaking change**, because implementations
outside this repository would stop satisfying it. Contracts only grow in a
major version.

## Storage format changes

A change to the segment file layout or the database schema is treated as
breaking even if no PHP signature moves, and will ship with a migration path in
this file. Segments written by an older major version are readable by the
upgrade tooling, never silently by the new engine.

## From 0.x

There is no 0.x. 1.0.0 is the first release.
