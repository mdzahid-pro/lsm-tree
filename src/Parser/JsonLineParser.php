<?php

declare(strict_types=1);

namespace Lsm\Parser;

use JsonException;
use Lsm\Contract\OperationParserInterface;
use Lsm\Exception\MalformedOperationException;
use Lsm\Model\Operation;
use Lsm\Model\OperationType;

/**
 * One JSON object per line: {"type":"put","key":"a","value":"1"}.
 *
 * Line-delimited rather than one big array so that a multi-gigabyte import
 * streams instead of being decoded into memory all at once.
 */
final readonly class JsonLineParser implements OperationParserInterface
{
    public function __construct(private string $label = 'json') {}

    public function parse(string $contents, int $line): ?Operation
    {
        $contents = trim($contents);

        if ($contents === '') {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw MalformedOperationException::atLine($this->label, $line, $exception->getMessage());
        }

        if (!is_array($decoded) || !isset($decoded['type'], $decoded['key'])) {
            throw MalformedOperationException::atLine($this->label, $line, 'expected a "type" and a "key".');
        }

        $value = $decoded['value'] ?? null;

        return new Operation(
            OperationType::fromInput((string) $decoded['type']),
            (string) $decoded['key'],
            is_scalar($value) ? (string) $value : null,
        );
    }
}
