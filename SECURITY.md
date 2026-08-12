# Security Policy

## Supported versions

The latest major version receives security fixes.

## Reporting a vulnerability

Please do not open a public issue. Email the maintainers with a description,
the affected version, and a reproduction if you have one. You will get an
acknowledgement within a few days.

## Notes on stored data

Values are stored as written — this package does not encrypt them. Segment
files are readable by anyone with access to the storage path, and rows in
`lsm_entries` by anyone with database access. Encrypt sensitive values before
calling `put()`, and treat the storage path with the same care as any other
data directory.
