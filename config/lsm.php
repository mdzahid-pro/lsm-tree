<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Store
    |--------------------------------------------------------------------------
    |
    | The store used when you call Lsm::put() or type-hint the engine without
    | naming one. Every other store is reached with Lsm::store('name').
    |
    */

    'default' => env('LSM_STORE', 'primary'),

    /*
    |--------------------------------------------------------------------------
    | Stores
    |--------------------------------------------------------------------------
    |
    | Each store is an independent LSM tree with its own levels, buffer and
    | maintenance schedule. Isolating unrelated workloads is usually the right
    | call: a store written to constantly should not share a compaction budget
    | with one that is written to nightly.
    |
    */

    'stores' => [

        'primary' => [

            /*
            | Where the sorted runs live.
            |
            | memory   – per process, lost on exit. Tests and experiments.
            | file     – sorted files plus an atomically replaced manifest.
            |            Requires a real local path: no S3, no NFS without
            |            locking. Fast, and the closest to a textbook LSM tree.
            | database – one row per run and one per entry, compaction wrapped
            |            in a transaction. The right default when more than one
            |            machine touches the same store.
            */
            'segments' => [
                'driver' => env('LSM_SEGMENTS_DRIVER', 'database'),

                // file driver
                'path' => storage_path('lsm/primary'),
                'index_interval' => 64,

                // database driver
                'connection' => env('LSM_CONNECTION'),
                'segments_table' => 'lsm_segments',
                'entries_table' => 'lsm_entries',
                'chunk_size' => 1000,
            ],

            /*
            | Durability for writes still sitting in the buffer.
            |
            | none     – the buffer is lost on a crash. Only for data you can
            |            rebuild from somewhere else.
            | memory   – models the ordering without any durability. Tests.
            | file     – an append-only log next to the segments. Set sync to
            |            true to survive a power cut rather than just a crash.
            | database – slower per write, and the only option that another
            |            machine can recover from.
            */
            'wal' => [
                'driver' => env('LSM_WAL_DRIVER', 'database'),
                'path' => storage_path('lsm/primary/wal.jsonl'),
                'sync' => false,
                'connection' => env('LSM_CONNECTION'),
                'table' => 'lsm_wal',
            ],

            /*
            | Replay the log into the buffer the first time this store is
            | resolved in a process. Cheap when the log is short, and the only
            | way an unclean shutdown is repaired automatically.
            */
            'recover_on_boot' => true,

            /*
            | How many entries the buffer holds before it is sealed into a run.
            |
            | Larger means fewer, bigger runs and less compaction, at the cost
            | of more memory and more to replay after a crash. A few thousand
            | suits most workloads.
            */
            'memtable' => [
                'max_entries' => (int) env('LSM_MEMTABLE_ENTRIES', 2000),
            ],

            /*
            | max_runs_per_level – how many runs a level tolerates before it
            |                      is merged. Lower means faster reads and more
            |                      write amplification.
            | bottom_level       – the deepest level. Merges there happen in
            |                      place and are the only ones allowed to
            |                      discard tombstones.
            */
            'compaction' => [
                'max_runs_per_level' => (int) env('LSM_MAX_RUNS', 4),
                'bottom_level' => (int) env('LSM_BOTTOM_LEVEL', 2),
            ],

            /*
            | The Bloom filter guarding each run. Ten bits per key gives
            | roughly a one percent false-positive rate; each false positive
            | costs one wasted lookup and never a wrong answer. Leave hashes
            | null to derive the optimal count from bits_per_key.
            */
            'filter' => [
                'enabled' => true,
                'bits_per_key' => 10,
                'hashes' => null,
            ],

            /*
            | Serialises flushing and compaction across processes.
            |
            | Turn this on whenever more than one process writes to the store.
            | Reads and buffered writes are never locked, so the common path
            | pays nothing. The cache store must support atomic locks: redis,
            | memcached, dynamodb or database. The file and array drivers do
            | not, and will silently fail to lock anything.
            */
            'lock' => [
                'enabled' => env('LSM_LOCK', true),
                'store' => env('LSM_LOCK_STORE'),
                'hold_seconds' => 60,
                'wait_seconds' => 10,
            ],

            /*
            | Dispatch MemTableFlushed and CompactionCompleted on the
            | application's event dispatcher. Per-key reads and writes are
            | never dispatched — that would make the listener the slowest part
            | of the write path.
            */
            'events' => true,

            /*
            | Engine activity as log lines. Types narrows what is written;
            | leave it as flush and compaction unless you are debugging, since
            | every single write produces an event.
            |
            | Available: write, flush, compaction, read.hit, read.miss,
            |            read.filter-skip, read.false-positive
            */
            'logging' => [
                'enabled' => false,
                'channel' => null,
                'level' => 'debug',
                'types' => ['flush', 'compaction'],
            ],
        ],

        /*
        | A second store showing the file driver and a workload that is
        | written in bulk and read rarely: a big buffer, lazy compaction and
        | no locking because only the importer writes to it.
        */
        'archive' => [
            'segments' => [
                'driver' => 'file',
                'path' => storage_path('lsm/archive'),
                'index_interval' => 128,
            ],
            'wal' => [
                'driver' => 'file',
                'path' => storage_path('lsm/archive/wal.jsonl'),
                'sync' => false,
            ],
            'recover_on_boot' => true,
            'memtable' => ['max_entries' => 10000],
            'compaction' => ['max_runs_per_level' => 6, 'bottom_level' => 3],
            'filter' => ['enabled' => true, 'bits_per_key' => 12, 'hashes' => null],
            'lock' => ['enabled' => false],
            'events' => false,
            'logging' => ['enabled' => false],
        ],

    ],

];
