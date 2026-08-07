<?php

declare(strict_types=1);

namespace Lsm\Exception;

use Throwable;

/**
 * Marker contract implemented by every exception this package throws.
 *
 * Callers can catch LsmExceptionInterface to isolate storage-engine failures
 * without coupling themselves to concrete exception classes.
 */
interface LsmExceptionInterface extends Throwable {}
