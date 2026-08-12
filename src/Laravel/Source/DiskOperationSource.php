<?php

declare(strict_types=1);

namespace Lsm\Laravel\Source;

use Generator;
use Illuminate\Contracts\Filesystem\Filesystem;
use Lsm\Contract\OperationParserInterface;
use Lsm\Contract\OperationSourceInterface;
use Lsm\Exception\UnreadableSourceException;
use Lsm\Model\Operation;

/**
 * Reads a workload from any configured Laravel disk, including remote ones.
 *
 * Streams rather than downloads: a multi-gigabyte import from S3 costs one
 * open connection and a line buffer.
 */
final readonly class DiskOperationSource implements OperationSourceInterface
{
    public function __construct(
        private Filesystem $disk,
        private string $path,
        private OperationParserInterface $parser,
    ) {
    }

    /**
     * @return Generator<int, Operation>
     */
    public function operations(): iterable
    {
        $stream = $this->disk->readStream($this->path);

        if (!is_resource($stream)) {
            throw UnreadableSourceException::missingFile($this->path);
        }

        $line = 0;

        try {
            while (($contents = fgets($stream)) !== false) {
                $line++;
                $operation = $this->parser->parse($contents, $line);

                if ($operation !== null) {
                    yield $operation;
                }
            }
        } finally {
            fclose($stream);
        }
    }

    public function describe(): string
    {
        return $this->path;
    }
}
