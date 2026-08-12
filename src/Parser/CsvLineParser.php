<?php

declare(strict_types=1);

namespace Lsm\Parser;

use Lsm\Contract\OperationParserInterface;
use Lsm\Exception\MalformedOperationException;
use Lsm\Model\Operation;
use Lsm\Model\OperationType;

/**
 * Three columns: type,key,value. A "type,key,value" header row is tolerated
 * and skipped, because spreadsheets add one whether you want it or not.
 */
final readonly class CsvLineParser implements OperationParserInterface
{
    public function __construct(
        private string $delimiter = ',',
        private string $label = 'csv',
    ) {}

    public function parse(string $contents, int $line): ?Operation
    {
        $contents = rtrim($contents, "\r\n");

        if (trim($contents) === '') {
            return null;
        }

        $columns = str_getcsv($contents, $this->delimiter, '"', '');

        // str_getcsv() returns a non-empty list for the non-blank input that
        // reaches this line, so only a null first column is possible here.
        if ($columns[0] === null) {
            return null;
        }

        $type = strtolower(trim((string) $columns[0]));

        if ($type === 'type') {
            return null;
        }

        if (!isset($columns[1]) || trim((string) $columns[1]) === '') {
            throw MalformedOperationException::atLine($this->label, $line, 'the key column is empty.');
        }

        $value = $columns[2] ?? null;

        // An unknown type or a valueless put is reported by the model without
        // any idea which line it came from. A failed import is only actionable
        // if it names the offending line, so borrow this one.
        try {
            return new Operation(
                OperationType::fromInput($type),
                trim((string) $columns[1]),
                $value === null || $value === '' ? null : (string) $value,
            );
        } catch (MalformedOperationException $exception) {
            throw MalformedOperationException::atLine($this->label, $line, $exception->getMessage());
        }
    }
}
