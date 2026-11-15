<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\SeedResidueException;
use App\Models\Sermon;

/**
 * The values that `SermonSeeder` and `PreacherSeeder` write and nothing real does.
 *
 * Production was never seeded; the local database was, twice, and real
 * recordings were later attached to seeded sermon rows that kept their sample
 * title, slug, reference and series. This class answers "does this carry a
 * seeded value?" for sermon rows or bundle publications, preachers, aliases and
 * processing ids. Mark Drury and Visiting Speaker are real names in real use
 * and are deliberately not part of the signature.
 *
 * A sermon is keyed by seeded identity (date, service, content type) because a
 * real sermon may legitimately share a title or reference with a sample on
 * another date. `SeedSignatureTest` runs the seeders and fails when their data
 * and this signature differ.
 */
final class SeedSignature
{
    public const PROCESSING_ID = 'seed-prodigal-son-processing';

    public const ALIAS = 'guest speaker';

    public const SERVICE_DATE = '2024-11-24';

    public const SERVICE_ITEM_POSITION = 2;

    public const SERVICE_ITEM_TYPE = 'bibles';

    public const SERVICE_ITEM_TITLE = 'Luke 15:1-10';

    /** @var list<string> */
    private const FIELDS = ['title', 'slug', 'reference', 'series', 'points'];

    /** @var list<string> */
    private const DISTINCTIVE_FIELDS = ['title', 'slug', 'series', 'points'];

    /**
     * Seeded sermon values keyed by `date|service|content_type`.
     *
     * @return array<string, array{title: string, slug: string, reference: string, series?: string, points?: string, preacher_source: string}>
     */
    public static function sermons(): array
    {
        return [
            self::identity('2024-12-15', 'morning', 'sermon') => [
                'title' => 'The Birth of Our Saviour',
                'slug' => 'the-birth-of-our-saviour',
                'reference' => 'Luke 2:1-20',
                'series' => 'Christmas Messages',
                'points' => '1. The historical reality\n2. The divine purpose\n3. The eternal significance',
                'preacher_source' => 'manual',
            ],
            self::identity('2024-12-08', 'morning', 'sermon') => [
                'title' => 'Walking in the Light',
                'slug' => 'walking-in-the-light',
                'reference' => '1 John 1:5-10',
                'series' => '1 John',
                'points' => '1. God is light\n2. Fellowship with God\n3. Confession and forgiveness',
                'preacher_source' => 'manual',
            ],
            self::identity('2024-12-01', 'other', 'sermon') => [
                'title' => 'Special Service Example',
                'slug' => 'special-service-example',
                'reference' => 'Psalm 100',
                'series' => 'Special Events',
                'points' => '1. Praise\n2. Thanksgiving',
                'preacher_source' => 'default',
            ],
            self::identity('2024-12-15', 'morning', 'childrens_talk') => [
                'title' => "Who is Jesus? (Children's Talk)",
                'slug' => 'childrens-talk-who-is-jesus',
                'reference' => 'Mark 1:1-11',
                'preacher_source' => 'manual',
            ],
            self::identity('2024-12-08', 'morning', 'childrens_talk') => [
                'title' => "God Made the World (Children's Talk)",
                'slug' => 'childrens-talk-god-made-the-world',
                'reference' => 'Genesis 1:1',
                'preacher_source' => 'manual',
            ],
            self::identity('2024-12-01', 'morning', 'childrens_talk') => [
                'title' => "The Good Shepherd (Children's Talk)",
                'slug' => 'childrens-talk-the-good-shepherd',
                'reference' => 'John 10:11-18',
                'preacher_source' => 'manual',
            ],
            self::identity(self::SERVICE_DATE, 'morning', 'sermon') => [
                'title' => 'The Prodigal Son',
                'slug' => 'the-prodigal-son',
                'reference' => 'Luke 15:11-32',
                'series' => 'Parables of Jesus',
                'points' => '1. The son who wandered\n2. The father who waited\n3. The grace that restores',
                'preacher_source' => 'manual',
            ],
        ];
    }

    /**
     * The seeded values for one identity, or null when nothing was seeded there.
     *
     * @return array{title: string, slug: string, reference: string, series?: string, points?: string, preacher_source: string}|null
     */
    public static function seededValues(string $date, string $service, string $contentType): ?array
    {
        return self::sermons()[self::identity($date, $service, $contentType)] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function seededDates(): array
    {
        return array_values(array_unique(array_map(
            static fn (string $identity): string => explode('|', $identity)[0],
            array_keys(self::sermons()),
        )));
    }

    public static function identity(string $date, string $service, string $contentType): string
    {
        return $date.'|'.$service.'|'.$contentType;
    }

    /**
     * Preachers only the seeder creates (preachers 2 to 5 locally).
     *
     * @return list<string>
     */
    public static function preacherNames(): array
    {
        return ['John Smith', 'David Johnson', 'Michael Brown', 'Paul Wilson'];
    }

    public static function isSeededPreacherName(?string $name): bool
    {
        return is_string($name) && in_array(trim($name), self::preacherNames(), true);
    }

    public static function isSeededAlias(?string $alias): bool
    {
        return is_string($alias) && mb_strtolower(trim($alias)) === self::ALIAS;
    }

    public static function isSeededProcessingId(?string $processingId): bool
    {
        return $processingId === self::PROCESSING_ID;
    }

    public static function isSeededServiceItem(string $serviceDate, string $service, mixed $position, ?string $type, ?string $title): bool
    {
        return $serviceDate === self::SERVICE_DATE
            && $service === 'morning'
            && (int) $position === self::SERVICE_ITEM_POSITION
            && $type === self::SERVICE_ITEM_TYPE
            && $title === self::SERVICE_ITEM_TITLE;
    }

    /**
     * The fields of a sermon that still equal their seeded value, as
     * field => seeded value. Empty unless the row is a seeded one: a title,
     * slug, series or points value equal to the seed for that identity. A
     * reference alone proves nothing, since real sermons share references.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    public static function matches(string $date, string $service, string $contentType, array $values): array
    {
        $seeded = self::sermons()[self::identity($date, $service, $contentType)] ?? null;

        if ($seeded === null) {
            return [];
        }

        $matched = [];

        foreach (self::FIELDS as $field) {
            if (isset($seeded[$field]) && self::normalise($field, $values[$field] ?? null) === $seeded[$field]) {
                $matched[$field] = $seeded[$field];
            }
        }

        if (array_intersect(array_keys($matched), self::DISTINCTIVE_FIELDS) === []) {
            return [];
        }

        return $matched;
    }

    /**
     * @return array<string, string>
     */
    public static function matchesSermon(Sermon $sermon): array
    {
        return self::matches(
            $sermon->date->toDateString(),
            (string) $sermon->service?->value,
            $sermon->content_type->value,
            $sermon->getAttributes(),
        );
    }

    /**
     * Human-readable findings for one bundle publication, including its preacher
     * payload and aliases.
     *
     * @param  array<string, mixed>  $publication
     * @return list<string>
     */
    public static function publicationFindings(array $publication): array
    {
        $date = (string) ($publication['date'] ?? '');
        $service = (string) ($publication['service'] ?? '');
        $contentType = (string) ($publication['content_type'] ?? '');
        $findings = [];

        foreach (self::matches($date, $service, $contentType, $publication) as $field => $value) {
            $findings[] = "sermon {$date} {$service} {$contentType} field {$field} carries the seeded value '{$value}'";
        }

        $preacher = $publication['preacher'] ?? null;

        if (is_array($preacher)) {
            foreach (['name', 'slug'] as $key) {
                $value = $preacher[$key] ?? null;

                if (is_string($value) && (self::isSeededPreacherName($value) || self::isSeededPreacherName(self::nameFromSlug($value)))) {
                    $findings[] = "preacher {$key} '{$value}' is a seeded preacher";
                }
            }

            foreach ((array) ($preacher['aliases'] ?? []) as $alias) {
                if (self::isSeededAlias(is_string($alias) ? $alias : null)) {
                    $findings[] = "preacher alias '{$alias}' is the seeded alias";
                }
            }
        }

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $publication
     *
     * @throws SeedResidueException
     */
    public static function assertPublicationClean(array $publication, string $where): void
    {
        $findings = self::publicationFindings($publication);

        if ($findings !== []) {
            throw new SeedResidueException("{$where} refused: ".implode('; ', $findings).'. Run sermons:clear-seeded-residue first.');
        }
    }

    /**
     * @param  array<string, mixed>  $mediaGraph
     *
     * @throws SeedResidueException
     */
    public static function assertMediaGraphClean(array $mediaGraph, string $where): void
    {
        $processingId = $mediaGraph['run']['processing_id'] ?? $mediaGraph['processing_key'] ?? null;

        if (self::isSeededProcessingId(is_string($processingId) ? $processingId : null)) {
            throw new SeedResidueException("{$where} refused: processing run '{$processingId}' is the seeded sample run.");
        }

        foreach ($mediaGraph['publications'] ?? [] as $publication) {
            if (is_array($publication)) {
                self::assertPublicationClean($publication, $where);
            }
        }
    }

    private static function nameFromSlug(string $slug): string
    {
        return ucwords(str_replace('-', ' ', $slug));
    }

    private static function normalise(string $field, mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        if ($field === 'points' && str_starts_with($value, '"')) {
            $decoded = json_decode($value);

            $value = is_string($decoded) ? $decoded : $value;
        }

        return trim($value);
    }
}
