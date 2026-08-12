<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables for the database segment store and write-ahead log.
 *
 * Only needed when a store uses the "database" driver. The columns are named
 * entry_key and entry_value rather than key and value because both of those
 * are reserved words in MySQL.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('lsm_segments', function (Blueprint $table): void {
            $table->id();
            $table->string('store', 64);
            $table->string('segment_id', 64);
            $table->unsignedTinyInteger('level');
            $table->unsignedBigInteger('entry_count');
            $table->text('min_key');
            $table->text('max_key');
            $table->unsignedBigInteger('max_sequence');

            // The packed Bloom filter, base64 encoded. Read on every lookup
            // that reaches this run, so it lives beside the metadata rather
            // than in a joined table.
            $table->longText('filter_bits')->nullable();
            $table->unsignedInteger('filter_size')->default(0);
            $table->unsignedTinyInteger('filter_hashes')->default(0);

            $table->timestamp('created_at')->nullable();

            $table->unique(['store', 'segment_id']);

            // The read path lists every run of a store newest first; this is
            // the index that makes it a single ordered scan.
            $table->index(['store', 'level', 'id']);
        });

        Schema::create('lsm_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('store', 64);
            $table->string('segment_id', 64);
            $table->string('entry_key', 191);

            // Null is a tombstone, not a missing value.
            $table->longText('entry_value')->nullable();
            $table->unsignedBigInteger('sequence');

            // A point lookup is exactly this index. Without it every read
            // degrades to a table scan of the run.
            $table->unique(['store', 'segment_id', 'entry_key']);

            // Compaction streams a run in primary-key order, which is also key
            // order because rows are inserted sorted and never updated.
            $table->index(['store', 'segment_id', 'id']);
        });

        Schema::create('lsm_wal', function (Blueprint $table): void {
            $table->id();
            $table->string('store', 64);
            $table->string('entry_key', 191);
            $table->longText('entry_value')->nullable();
            $table->unsignedBigInteger('sequence');
            $table->timestamp('created_at')->nullable();

            $table->index(['store', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lsm_wal');
        Schema::dropIfExists('lsm_entries');
        Schema::dropIfExists('lsm_segments');
    }
};
