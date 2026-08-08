<?php

declare(strict_types=1);

namespace Lsm\Model;

use Lsm\Exception\MalformedOperationException;

enum OperationType: string
{
    case Put = 'put';
    case Delete = 'delete';
    case Get = 'get';

    /**
     * @throws MalformedOperationException
     */
    public static function fromInput(string $raw): self
    {
        return self::tryFrom(strtolower(trim($raw)))
            ?? throw MalformedOperationException::unknownType($raw);
    }

    public function requiresValue(): bool
    {
        return $this === self::Put;
    }
}
