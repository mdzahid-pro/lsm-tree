<?php

declare(strict_types=1);

namespace Lsm\Laravel\Lock;

use Illuminate\Contracts\Cache\Lock as LaravelLock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException as LaravelLockTimeoutException;
use Lsm\Contract\LockInterface;
use Lsm\Exception\LockTimeoutException;

/**
 * Cross-process exclusivity backed by Laravel's atomic locks.
 *
 * Only maintenance is serialised — flushing and compacting. Reads and buffered
 * writes stay lock-free, so the common path pays nothing.
 *
 * The cache store must support atomic locks: Redis, Memcached, DynamoDB or the
 * database driver. The file and array drivers do not, and configuring one of
 * those gives you a lock that does not lock.
 */
final readonly class CacheLock implements LockInterface
{
    public function __construct(
        private LockProvider $provider,
        private string $store,
        private int $holdSeconds = 60,
        private int $waitSeconds = 10,
    ) {
    }

    public function run(callable $work): mixed
    {
        /** @var LaravelLock $lock */
        $lock = $this->provider->lock($this->name(), $this->holdSeconds);

        try {
            return $lock->block($this->waitSeconds, $work);
        } catch (LaravelLockTimeoutException) {
            throw LockTimeoutException::forStore($this->store, $this->waitSeconds);
        }
    }

    private function name(): string
    {
        return sprintf('lsm:maintenance:%s', $this->store);
    }
}
