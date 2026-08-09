<?php

declare(strict_types=1);

namespace Lsm\Source;

use Generator;
use Lsm\Contract\OperationParserInterface;
use Lsm\Contract\OperationSourceInterface;
use Lsm\Model\Operation;

/**
 * Reads operations from any open stream, one line at a time.
 *
 * Knows about lines and nothing about formats or file systems, so the same
 * class serves a local file, a stream from a cloud disk and php://memory.
 */
final readonly class StreamOperationSource implements OperationSourceInterface
{
    /**
     * @param callable(): resource $open called once per iteration so the
     *                                   source can be traversed more than once
     */
    public function __construct(
        private mixed $open,
        private OperationParserInterface $parser,
        private string $label,
    ) {}

    /**
     * @return Generator<int, Operation>
     */
    public function operations(): iterable
    {
        $stream = ($this->open)();
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
        return $this->label;
    }
}
