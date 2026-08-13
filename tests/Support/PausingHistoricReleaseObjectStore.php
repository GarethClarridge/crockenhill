<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\HistoricReleaseObjectStore;
use App\Data\HistoricReleaseObject;

/**
 * A real object store with a pause in it.
 *
 * HIR7's races are windows between two operations, so they are reproduced by
 * running a competing writer *inside* one of them rather than by threads. The
 * delegate is the real implementation throughout, so a case still exercises the
 * genuine conditional create rather than a fake that agrees with the test.
 *
 * `beforeCreate` runs after the destination has been inspected and found absent,
 * and before the conditional create — the exact window in which two attempts
 * both believe they are the object's creator. `afterCreate` runs once this
 * attempt owns the bytes but has not committed, which is where compensation
 * decides what it may take back.
 *
 * The hooks fire once. A pause that fired on every call would stall the very
 * assertions the competitor makes.
 */
final class PausingHistoricReleaseObjectStore implements HistoricReleaseObjectStore
{
    /** @var callable|null */
    private $beforeCreate;

    /** @var callable|null */
    private $afterCreate;

    private bool $fired = false;

    public function __construct(
        private readonly HistoricReleaseObjectStore $delegate,
        ?callable $beforeCreate = null,
        ?callable $afterCreate = null,
    ) {
        $this->beforeCreate = $beforeCreate;
        $this->afterCreate = $afterCreate;
    }

    public function inspect(string $disk, string $path): ?HistoricReleaseObject
    {
        return $this->delegate->inspect($disk, $path);
    }

    public function createIfAbsent(string $disk, string $path, mixed $stream): HistoricReleaseObject
    {
        $this->fire($this->beforeCreate);

        $object = $this->delegate->createIfAbsent($disk, $path, $stream);

        $this->fire($this->afterCreate);

        return $object;
    }

    public function verify(string $disk, string $path, int $size, string $sha256): HistoricReleaseObject
    {
        return $this->delegate->verify($disk, $path, $size, $sha256);
    }

    public function supportsExactVersionDelete(string $disk): bool
    {
        return $this->delegate->supportsExactVersionDelete($disk);
    }

    public function deleteExactVersion(HistoricReleaseObject $object): void
    {
        $this->delegate->deleteExactVersion($object);
    }

    private function fire(?callable $hook): void
    {
        if ($hook === null || $this->fired) {
            return;
        }

        $this->fired = true;
        $hook();
    }
}
