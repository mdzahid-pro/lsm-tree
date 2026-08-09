<?php

declare(strict_types=1);

namespace Lsm\Parser;

use Lsm\Contract\OperationParserInterface;
use Lsm\Exception\UnreadableSourceException;

/**
 * Chooses a parser from a file extension.
 *
 * The one place that knows which format maps to which class. Adding a format
 * means writing a parser and adding a single line here.
 */
final readonly class ParserFactory
{
    private const array EXTENSIONS = ['jsonl', 'ndjson', 'json', 'csv', 'tsv'];

    public function forPath(string $path): OperationParserInterface
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $label = basename($path);

        return match ($extension) {
            'jsonl', 'ndjson', 'json' => new JsonLineParser($label),
            'csv' => new CsvLineParser(',', $label),
            'tsv' => new CsvLineParser("\t", $label),
            default => throw UnreadableSourceException::unsupportedFormat($path, self::EXTENSIONS),
        };
    }

    /**
     * @return list<string>
     */
    public function supportedExtensions(): array
    {
        return self::EXTENSIONS;
    }
}
