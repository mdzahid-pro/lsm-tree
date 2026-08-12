<?php

declare(strict_types=1);

namespace Lsm\Runtime;

use Lsm\Contract\KeyValueStoreInterface;
use Lsm\Contract\OperationSourceInterface;
use Lsm\Model\Operation;
use Lsm\Model\OperationType;
use Lsm\Model\RunSummary;

/**
 * Applies a workload to a store.
 *
 * It knows nothing about files, levels or frameworks — only that operations
 * arrive and have to be executed. The optional progress callback is what lets
 * a console command draw a bar without the runner importing a console.
 */
final readonly class OperationRunner
{
    /**
     * @param (callable(int, Operation): void)|null $onProgress receives the
     *                                                          1-based index
     */
    public function run(
        OperationSourceInterface $source,
        KeyValueStoreInterface $store,
        ?callable $onProgress = null,
    ): RunSummary {
        $counts = [
            OperationType::Put->value => 0,
            OperationType::Delete->value => 0,
            OperationType::Get->value => 0,
        ];

        /** @var array<string, string|null> $reads */
        $reads = [];
        $index = 0;

        foreach ($source->operations() as $operation) {
            $index++;
            $counts[$operation->type->value]++;

            if ($operation->type === OperationType::Get) {
                $reads[$operation->key] = $store->get($operation->key);
            } elseif ($operation->type === OperationType::Delete) {
                $store->delete($operation->key);
            } else {
                $store->put($operation->key, (string) $operation->value);
            }

            if ($onProgress !== null) {
                $onProgress($index, $operation);
            }
        }

        return new RunSummary(
            $counts[OperationType::Put->value],
            $counts[OperationType::Delete->value],
            $counts[OperationType::Get->value],
            $reads,
        );
    }
}
