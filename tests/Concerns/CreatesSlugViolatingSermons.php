<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Sermon;
use Illuminate\Support\Facades\DB;

/**
 * Helpers for reproducing the production state where a sermon carries a blank
 * slug.
 *
 * The `sermons_slug_format_check` CHECK constraint was added inside a try/catch
 * that silently skips when pre-existing rows already violate it
 * (database/migrations/2026_04_15_102525_add_slug_check_constraints_to_tables.php),
 * so production runs constraint-free and still holds malformed-slug rows. A
 * fresh test database always gets the constraint, so the only way to recreate
 * the bad row is to drop the constraint, write the row, and leave it dropped
 * for the rest of the (RefreshDatabase-isolated) test.
 */
trait CreatesSlugViolatingSermons
{
    /**
     * Persist a sermon with an empty slug, bypassing the slug-format constraint.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function createSermonWithBlankSlug(array $attributes = []): Sermon
    {
        $sermon = Sermon::factory()->create($attributes);

        $this->withoutSermonSlugConstraint();

        DB::table('sermons')->where('id', $sermon->id)->update(['slug' => '']);

        return $sermon->fresh();
    }

    /**
     * Drop the slug-format CHECK constraint so blank/malformed slugs can be
     * written, mirroring production's constraint-free state. No-op outside MySQL.
     */
    protected function withoutSermonSlugConstraint(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE sermons DROP CHECK sermons_slug_format_check');
    }
}
