<?php

declare(strict_types=1);

namespace Lsm\Source;

use Lsm\Contract\OperationParserInterface;
use Lsm\Contract\OperationSourceInterface;
use Lsm\Exception\UnreadableSourceException;
use Lsm\Model\Operation;

/**
 * A workload file on the local file system.
 *
 * A thin adapter over StreamOperationSource: it exists so that callers can say
 * "this path" without also having to say how a path becomes a stream.
 */
final readonly class FileOperationSource implements OperationSourceInterface
{
    public function __construct(
        private string $path,
        private OperationParserInterface $parser,
    ) {}

    /**
     * @return iterable<int, Operation>
     */
    public function operations(): iterable
    {
        if (!is_file($this->path) || !is_readable($this->path)) {
            throw UnreadableSourceException::missingFile($this->path);
        }

        $path = $this->path;

        $source = new StreamOperationSource(
            static function () use ($path) {
                $handle = fopen($path, 'rb');

                if ($handle === false) {
                    throw UnreadableSourceException::missingFile($path);
                }

                return $handle;
            },
            $this->parser,
            $this->describe(),
        );

        yield from $source->operations();
    }

    public function describe(): string
    {
        return basename($this->path);
    }
}
