# LSM Tree for Laravel

A log-structured merge tree as a Laravel package. Writes append, runs are
immutable, compaction happens in the background, and every part of the engine
is an interface you can replace.

This is the storage design behind LevelDB, RocksDB, Cassandra and ScyllaDB,
built to be readable.

```php
use Lsm\Laravel\Facades\Lsm;

Lsm::put('user:42:session', $payload);
Lsm::get('user:42:session');   // string|null
Lsm::has('user:42:session');   // bool
Lsm::delete('user:42:session');

Lsm::store('archive')->put('2026-08-12', $report);
```

---

## When this is the right tool

An LSM tree is optimised for one thing: **write throughput on a workload that
appends far more than it updates**. Event logs, audit trails, telemetry,
time-series, append-heavy caches, materialised projections.

It is the wrong tool for anything needing range scans by a secondary attribute,
joins, transactions across keys, or a query language. That is what your
database is for, and this package sits next to it rather than replacing it.

Reach for it when you are writing constantly and reading by exact key.

---

## What it looks like in practice

Four shapes that fit, and the code each turns into. Keys and values are both
strings — anything structured goes through `json_encode` on the way in.

### An audit trail nobody edits

Every action appends, nothing is ever updated, and a read is always "show me
this one event".

```php
Lsm::store('audit')->put(
    "order:{$order->id}:{$event->id}",
    json_encode(['actor' => $user->id, 'action' => 'refunded', 'cents' => 4200]),
);

$event = json_decode(Lsm::store('audit')->getOrFail("order:{$order->id}:{$event->id}"), true);
```

`getOrFail()` throws `KeyNotFoundException` instead of returning null, which is
what you want when a missing audit record is a bug rather than a branch.

Asking "every event for this order" is asking the wrong store. Keep that index
in your database and the payloads here.

### Session or device state under write pressure

Written on nearly every request, read by exact id, deleted on logout. Depend on
the narrow contract so the rest of the app cannot compact a level by accident.

```php
final readonly class SessionRepository
{
    public function __construct(private KeyValueStoreInterface $store) {}

    public function save(string $id, array $payload): void
    {
        $this->store->put("session:{$id}", json_encode($payload));
    }

    public function load(string $id): ?array
    {
        $raw = $this->store->get("session:{$id}");

        return $raw === null ? null : json_decode($raw, true);
    }

    public function forget(string $id): void
    {
        $this->store->delete("session:{$id}");   // writes a tombstone
    }
}
```

```php
// A service provider
$this->app->bind(KeyValueStoreInterface::class, fn () => Lsm::store('sessions'));
```

### Telemetry you ingest in bulk

A nightly dump lands on a disk and gets streamed in. The file is read line by
line, so a file larger than memory is routine.

```bash
php artisan lsm:import readings-2026-08-12.jsonl --store=telemetry --disk=s3 --flush
```

Put the timestamp in the key — `device:42:2026-08-12T10:00` — and each reading
is a direct lookup. This still is not a range scan: you can fetch a known
minute, not "every reading last Tuesday".

### A projection you rebuild rather than migrate

Read models are derived data. When the shape changes you do not write a
migration, you replay the events and swap the store.

```php
Event::listen(OrderShipped::class, function ($e) {
    Lsm::store('projections')->put("order:{$e->orderId}", json_encode($e->snapshot()));
});
```

Because runs are immutable, a rebuild is append-only too — it competes with
nothing and no reader sees a half-built view.

### Which driver each of these wants

| Scenario | Driver | Why |
|---|---|---|
| Audit trail, many app servers | `database` | More than one process writes |
| Sessions, one queue worker | `file` | Single writer, local disk, fastest |
| Telemetry import | `file` or `database` | Follows wherever the reads happen |
| Projections rebuilt offline | `memory` then swap | Nothing durable until it is ready |

---

## Installation

```bash
composer require mdzahid-pro/lsm-tree
php artisan lsm:install
```

`lsm:install` publishes `config/lsm.php` and, if you want the database driver,
the migrations. Then:

```bash
php artisan migrate
```

Requires PHP 8.3+ and Laravel 12 or 13.

---

## How it works

Five sentences, then the diagram.

1. Writes go into a small sorted buffer in RAM — the **mem-table**.
2. When it fills, the buffer is sealed into an immutable sorted **run**.
3. A run is never edited. Not once.
4. A delete writes a **tombstone**: a marker saying the key is gone.
5. Reads consult the newest source first and stop at the first copy found.

```
       put / delete
            │
            ▼
     ┌─────────────┐
     │  mem-table  │  sorted, in RAM, small
     └──────┬──────┘
            │ full → seal
            ▼
  L0  [run] [run] [run]     newest, small
            │ too many → merge
            ▼
  L1  [    bigger run    ]
            │
            ▼
  L2  [    biggest run    ]  bottom: tombstones collected here
```

Every entry carries a **sequence number**. When one key appears in two places,
the higher sequence wins. That is the only conflict rule in the system — the
rest follows from it.

Reads are guarded three ways before touching data, cheapest first: a Bloom
filter, then the run's key range, then a binary search or an indexed query.

---

## Configuration

`config/lsm.php` defines any number of independent stores.

```php
'stores' => [
    'primary' => [
        'segments' => ['driver' => 'database'],
        'wal'      => ['driver' => 'database'],
        'memtable' => ['max_entries' => 2000],
        'compaction' => ['max_runs_per_level' => 4, 'bottom_level' => 2],
        'filter'   => ['enabled' => true, 'bits_per_key' => 10],
        'lock'     => ['enabled' => true],
        'events'   => true,
    ],
],
```

### Segment drivers

| Driver | Where runs live | Use it when |
|---|---|---|
| `memory` | Process RAM | Tests, experiments |
| `file` | Sorted files + atomic manifest | One writer, local disk, maximum speed |
| `database` | Two tables, transactional compaction | More than one machine writes |

The `file` driver needs a **real local path**. Object stores have no atomic
rename and no cheap seek; use `database` there, or write an adapter.

### The knobs that actually matter

**`memtable.max_entries`** — how much is buffered before a flush. Larger means
fewer, bigger runs and less compaction, at the cost of memory and a longer
replay after a crash.

**`compaction.max_runs_per_level`** — lower means faster reads and more write
amplification. Four is a reasonable middle.

**`compaction.bottom_level`** — the deepest level. Merges there happen in place
and are the only ones allowed to discard tombstones.

**`filter.bits_per_key`** — ten gives roughly a one percent false-positive rate.
A false positive costs one wasted lookup and never a wrong answer.

---

## Operating it

### Schedule compaction

Compaction is what keeps reads fast. Leaving it entirely to the write path
means whichever unlucky request fills the buffer also pays to merge a level.

```php
// routes/console.php
Schedule::command('lsm:compact --all')->everyFiveMinutes();
Schedule::command('lsm:prune --all')->weekly();   // file driver only
```

Or push it onto a queue:

```php
Schedule::command('lsm:compact --all --queue')->everyMinute();
```

`RunCompaction` is unique by store, so a backed-up queue will not stack
duplicate jobs.

### Watch read amplification

```bash
php artisan lsm:stats --all
```

Read amplification is the number of runs a miss has to consult. A steady climb
means compaction is not keeping up with writes: compact more often, or raise
`memtable.max_entries` so each flush produces fewer, larger runs.

### Commands

| Command | Does |
|---|---|
| `lsm:install` | Publish config and migrations |
| `lsm:stats` | Shape and health of a store (`--json` for machines) |
| `lsm:flush` | Seal the buffer into a run |
| `lsm:compact` | Merge accumulated runs (`--queue` to defer) |
| `lsm:prune` | Delete orphaned files (file driver) |
| `lsm:import` | Bulk-load JSONL/NDJSON/CSV/TSV (`--disk=s3`) |
| `lsm:get` / `lsm:put` / `lsm:forget` | Single-key operations |

All accept `--store=` and most accept `--all`.

### Events

```php
Event::listen(MemTableFlushed::class, fn ($e) => Metrics::increment('lsm.flush'));
Event::listen(CompactionCompleted::class, fn ($e) => Log::info(...));
```

Only structural events are dispatched. Per-key reads and writes are not —
dispatching an event per operation would make the listener the slowest part of
the write path. Use `logging.types` if you need that detail while debugging.

---

## Concurrency: read this before going to production

The engine has one dangerous window: the moment a run is written and the
hierarchy is rewritten. Two processes doing that at once against a shared store
will duplicate or lose runs.

**Reads and buffered writes are never locked.** Only flushing and compacting
are, and only when you switch the lock on:

```php
'lock' => ['enabled' => true, 'store' => 'redis'],
```

The cache store must support atomic locks — Redis, Memcached, DynamoDB or
database. The `file` and `array` cache drivers do not, and will silently fail
to lock anything.

Guidance by driver:

- **`memory`** — one process by definition. No lock needed.
- **`file`** — safe for a single writer. Route writes through one queue worker
  and enable the lock so scheduled compaction cannot collide with it.
- **`database`** — safe for many writers with the lock enabled. Compaction runs
  inside a transaction, so a crash leaves the tree exactly as it was.

A long-running worker should call `Lsm::purge()` periodically. Each store
caches its view of the hierarchy for speed, and a worker that never purges will
keep consulting runs another process has already merged away.

---

## Extending it

Every seam is an interface. The two you are most likely to want:

```php
// A service provider
public function boot(LsmManager $lsm): void
{
    $lsm->extendSegments('redis', function ($app, $name, $config, $filters) {
        return new RedisSegmentStore($app->make('redis'), $filters, $name);
    });

    $lsm->extendWal('kafka', fn ($app, $name, $config) => new KafkaWriteAheadLog(...));
}
```

Then `'segments' => ['driver' => 'redis']` in config. Nothing in the engine
changes.

The full set of contracts lives in `src/Contract`:

| Interface | Replace it to change |
|---|---|
| `SegmentStoreInterface` | Where runs live and how they materialise |
| `MemTableInterface` | The buffer structure (skip list, red-black tree) |
| `CompactionPolicyInterface` | When and what to merge |
| `KeyFilterFactoryInterface` | Bloom → cuckoo, ribbon, nothing |
| `WriteAheadLogInterface` | Durability |
| `LockInterface` | What exclusivity means |
| `TraceListenerInterface` | Observability |
| `OperationParserInterface` | Import formats |

### Depend on the narrow contract

```php
use Lsm\Contract\KeyValueStoreInterface;

final readonly class SessionRepository
{
    public function __construct(private KeyValueStoreInterface $store) {}
}
```

`KeyValueStoreInterface` is reads and writes only. `MaintenanceInterface` is
flush, compact, recover and statistics. Application code should see the first
and not the second — handing a controller a `compact()` button is how a request
ends up merging a level.

---

## Architecture

```
src/
├── Contract/      every seam, and nothing else
├── Model/         Entry, Segment, Statistics, TraceEvent — immutable values
├── MemTable/      the write buffer
├── Segment/       the streaming k-way merge
├── Storage/       InMemory and File segment stores
├── Compaction/    the policies
├── Filter/        packed Bloom filter and its builder
├── Wal/           durability
├── Parser/        import formats
├── Source/        where a workload comes from
├── Runtime/       composition
├── LsmTree.php    the algorithm
└── Laravel/       the only directory that imports Illuminate
```

**The core knows nothing about Laravel.** `src/Laravel` is the boundary: every
framework opinion — connections, cache locks, log channels, events, commands —
lives there. Everything above it is plain PHP you can unit-test without booting
an application, and the test suite does exactly that.

### Where the principles show up

| Principle | Where |
|---|---|
| Single responsibility | `SegmentMerger` merges. It never decides *when* — the policy does. |
| Open/closed | A new driver is one class plus one `extendSegments()` call. `LsmTree` never changes. |
| Liskov | The same tests run against the memory, file and database stores. |
| Interface segregation | Reads and maintenance are separate contracts. A filter knows one method. |
| Dependency inversion | `LsmTree` takes eight interfaces and constructs none of them. |

DRY: one `write()` for put and delete, one `Entry` for values and tombstones,
one merge routine for both flush-driven and level-driven compaction.

---

## Two correctness rules worth knowing

**Tombstones only die at the bottom.** A tombstone's job is to shadow older
copies of its key. Discard it while an unmerged run still holds one and the
deleted value comes back to life. `SizeTieredCompactionPolicy::mayDropTombstones()`
permits it in exactly one case: the bottom level merging in place, where the
inputs are every run that level has and nothing lies beneath.

**Sequence numbers must not restart.** A fresh process seeds its counter from
`highestSequence()` on the store, and `recover()` advances it past anything
replayed from the log. Skip either and two writes share a number, after which
the merge rule picks between versions arbitrarily.

Both have tests named after the bug they prevent.

---

## Testing

```bash
composer test        # phpunit
composer analyse     # phpstan level 8
composer lint        # pint
composer check       # all three
```

The engine is tested in memory, the file driver against real files, and the
database driver against SQLite through Testbench.

---

## Versioning

Semantic versioning. The public API is: the `Lsm` facade, `LsmManager`,
everything in `src/Contract`, the models in `src/Model`, and the config file
shape. Anything else may change in a minor release.

Adding a method to a contract is a breaking change and only happens in a major
version. See `UPGRADING.md`.

## License

MIT. See `LICENSE.md`.
