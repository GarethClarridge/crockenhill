<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Sermon;
use Illuminate\Support\Facades\DB;

/**
 * Helpers for reproducing the production state where a sermon carries a blank
 * slug.
 *
 * The historical migration that introduced `sermons_slug_format_check`
 * tolerated pre-existing invalid rows. The canonical constraint now lives in
 * database/schema/mysql-schema.sql, while production can still hold the older
 * constraint-free state. A fresh test database always gets the constraint, so
 * the test drops it before recreating the malformed row.
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
