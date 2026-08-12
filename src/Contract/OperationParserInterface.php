<?php

declare(strict_types=1);

namespace Lsm\Contract;

use Lsm\Exception\MalformedOperationException;
use Lsm\Model\Operation;

/**
 * Turns one line of an import file into an operation.
 *
 * Separating the format from the reader means a JSON Lines file on the local
 * disk, in S3 or in a string all share one parser, and a new format is one
 * small class.
 */
interface OperationParserInterface
{
    /**
     * @param int $line the 1-based line number, for error messages
     *
     * @return Operation|null null for a line that should be skipped, such as a
     *                        blank line or a header row
     *
     * @throws MalformedOperationException
     */
    public function parse(string $contents, int $line): ?Operation;
}
