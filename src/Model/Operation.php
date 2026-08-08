<?php

declare(strict_types=1);

namespace Lsm\Model;

use Lsm\Exception\InvalidKeyException;
use Lsm\Exception\MalformedOperationException;

/**
 * One instruction from the workload: put, delete or get.
 *
 * Immutable, self-validating and free of any I/O — every source produces this
 * one shape, so the runner never learns where the workload came from.
 */
final readonly class Operation
{
    public function __construct(
        public OperationType $type,
        public string $key,
        public ?string $value = null,
    ) {
        if ($key === '') {
            throw InvalidKeyException::empty();
        }

        if ($type->requiresValue() && $value === null) {
            throw new MalformedOperationException('A "put" operation requires a value.');
        }
    }

    public static function put(string $key, string $value): self
    {
        return new self(OperationType::Put, $key, $value);
    }

    public static function delete(string $key): self
    {
        return new self(OperationType::Delete, $key);
    }

    public static function get(string $key): self
    {
        return new self(OperationType::Get, $key);
    }
}
