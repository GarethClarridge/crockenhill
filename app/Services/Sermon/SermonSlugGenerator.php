<?php

declare(strict_types=1);

namespace App\Services\Sermon;

use App\Models\Sermon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Generates sermon slugs and decides when an existing slug may be regenerated.
 *
 * A sermon's slug is its public URL, so it is written once at creation and then
 * treated as immutable. The one exception is the window in which the pipeline
 * replaces a placeholder title with the AI-generated one: the record is not yet
 * publicised, so the URL can still be improved. {@see isDerivedFrom()} is the
 * gate for that window.
 */
class SermonSlugGenerator
{
    /**
     * Build a unique slug for a title, appending a numeric suffix on collision.
     *
     * @param  int|null  $excludeSermonId  Sermon to ignore when checking uniqueness,
     *                                     so re-slugging a record never collides with itself.
     */
    public function generate(string $title, ?int $excludeSermonId = null): string
    {
        $baseSlug = Str::slug($title);
        $slug = $baseSlug;
        $counter = 1;

        $query = Sermon::query()
            ->when($excludeSermonId, fn (Builder $builder): Builder => $builder->where('id', '!=', $excludeSermonId));

        while ($query->clone()->where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    /**
     * Determine whether a slug is still the machine-generated form of a title.
     *
     * Mirrors the "only auto-update an untouched slug" rule the admin form uses
     * (see SermonFormData::updatedTitle()), so a slug someone edited by hand is
     * never silently rewritten.
     */
    public function isDerivedFrom(string $slug, string $title): bool
    {
        $baseSlug = Str::slug($title);

        if ($baseSlug === '') {
            return false;
        }

        return preg_match('/^'.preg_quote($baseSlug, '/').'(?:-\d+)?$/', $slug) === 1;
    }

    /**
     * Determine whether a slug came from one of the pipeline's placeholder titles.
     *
     * Matches the slugified forms of the two titles SermonCreationService emits
     * when no real title is available yet — "Evening Sermon - January 16, 2022"
     * and "Sermon - January 16, 2022" — plus any collision suffix.
     */
    public function isPlaceholderSlug(string $slug): bool
    {
        return preg_match(
            '/^(?:(?:morning|evening|other)-)?sermon-[a-z]+-\d{1,2}-\d{4}(?:-\d+)?$/',
            $slug
        ) === 1;
    }
}
