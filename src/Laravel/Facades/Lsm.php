<?php

declare(strict_types=1);

namespace Lsm\Laravel\Facades;

use Closure;
use Illuminate\Support\Facades\Facade;
use Lsm\Laravel\LsmManager;
use Lsm\LsmTree;
use Lsm\Model\Statistics;

/**
 * @method static LsmTree      store(?string $name = null)
 * @method static string       getDefaultStore()
 * @method static void         setDefaultStore(string $name)
 * @method static list<string> stores()
 * @method static LsmManager   extendSegments(string $driver, Closure $resolver)
 * @method static LsmManager   extendWal(string $driver, Closure $resolver)
 * @method static void         purge(?string $name = null)
 * @method static void         put(string $key, string $value)
 * @method static void         delete(string $key)
 * @method static string|null  get(string $key)
 * @method static string       getOrFail(string $key)
 * @method static bool         has(string $key)
 * @method static void         flush()
 * @method static void         compact()
 * @method static int          recover()
 * @method static Statistics   statistics()
 *
 * @see LsmManager
 */
final class Lsm extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LsmManager::class;
    }
}
