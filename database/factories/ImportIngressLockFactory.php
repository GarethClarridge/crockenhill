<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ImportIngressLock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportIngressLock>
 */
class ImportIngressLockFactory extends Factory
{
    protected $model = ImportIngressLock::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'operation_id' => 'historic-import-'.fake()->unique()->numerify('######'),
            'reason' => 'Historic archive import window',
            'blocked_by' => 'operator@crockenhill.test',
            'blocked_at' => now(),
            'released_at' => null,
            'is_active' => 1,
        ];
    }

    /** A window that has already been reopened. */
    public function released(): self
    {
        return $this->state(fn (): array => [
            'released_at' => now(),
            'is_active' => null,
        ]);
    }
}
