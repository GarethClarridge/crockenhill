<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A short talk approved or published before 2026-09-24 was approved as a
 * children's talk: until the `short_talk` rename, that section type *was* the
 * audience call, and a person made it at approval. Publication now refuses a
 * short talk with no confirmed type, so without this an approved talk waiting
 * to publish would be stranded, and every published one would sit in the review
 * queue for a type nobody can change.
 *
 * The record names its origin (`source`) and carries no user, because none chose
 * it through the new control. An approval whose signature matched the row gets
 * the signature the confirmed type now produces, so it stays approved. The
 * payload is frozen as it stood on 2026-09-24, as in the restamp migration.
 */
return new class extends Migration
{
    private const string Source = 'approved_as_childrens_talk';

    public function up(): void
    {
        $this->eachApprovedShortTalk(function (object $row, array $metadata): ?array {
            if (is_string($metadata['talk_type']['reviewed']['value'] ?? null)) {
                return null;
            }

            $signatureBefore = $this->signature($row, $metadata);
            $talkType = is_array($metadata['talk_type'] ?? null) ? $metadata['talk_type'] : ['proposed' => 'childrens_talk'];
            $talkType['reviewed'] = [
                'value' => 'childrens_talk',
                'user_id' => null,
                'at' => $metadata['publication']['approved_at']
                    ?? Carbon::parse($row->published_at ?? now())->toIso8601String(),
                'source' => self::Source,
            ];
            $metadata['talk_type'] = $talkType;

            if (($metadata['publication']['approved_signature'] ?? null) === $signatureBefore) {
                $metadata['publication']['approved_signature'] = $this->signature($row, $metadata);
            }

            return $metadata;
        });
    }

    public function down(): void
    {
        $this->eachApprovedShortTalk(function (object $row, array $metadata): ?array {
            if (($metadata['talk_type']['reviewed']['source'] ?? null) !== self::Source) {
                return null;
            }

            $signatureBefore = $this->signature($row, $metadata);
            unset($metadata['talk_type']['reviewed']);

            if (($metadata['publication']['approved_signature'] ?? null) === $signatureBefore) {
                $metadata['publication']['approved_signature'] = $this->signature($row, $metadata);
            }

            return $metadata;
        });
    }

    /**
     * @param  callable(object, array<string, mixed>): (array<string, mixed>|null)  $rewrite
     */
    private function eachApprovedShortTalk(callable $rewrite): void
    {
        DB::table('service_sections')
            ->select(['id', 'church_service_item_id', 'section_type', 'title', 'start_time', 'end_time', 'published_at', 'metadata'])
            ->where('section_type', 'short_talk')
            ->whereIn('publication_status', ['approved', 'published'])
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($rewrite): void {
                foreach ($rows as $row) {
                    $metadata = json_decode((string) $row->metadata, true);
                    $updated = $rewrite($row, is_array($metadata) ? $metadata : []);

                    if ($updated !== null) {
                        DB::table('service_sections')->where('id', $row->id)->update(['metadata' => json_encode($updated)]);
                    }
                }
            });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function signature(object $row, array $metadata): string
    {
        $payload = [
            'church_service_item_id' => $row->church_service_item_id === null ? null : (int) $row->church_service_item_id,
            'section_type' => $row->section_type,
            'title' => $row->title,
            'start_time' => (float) $row->start_time,
            'end_time' => (float) $row->end_time,
            'publication_speaker' => $this->publicationSpeaker($metadata),
        ];

        $talkType = $this->trimmedString($metadata['talk_type']['reviewed']['value'] ?? null);

        if ($talkType !== null) {
            $payload['talk_type'] = $talkType;
        }

        return hash('sha256', (string) json_encode($payload));
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{preacher_id: int|null, preacher_name: string, source: string}|null
     */
    private function publicationSpeaker(array $metadata): ?array
    {
        $reviewed = $metadata['talk_speaker']['reviewed'] ?? null;

        if (! is_array($reviewed)) {
            return null;
        }

        $name = $this->trimmedString($reviewed['preacher_name'] ?? null);

        if ($name === null) {
            return null;
        }

        return [
            'preacher_id' => is_numeric($reviewed['preacher_id'] ?? null) ? (int) $reviewed['preacher_id'] : null,
            'preacher_name' => $name,
            'source' => $this->trimmedString($reviewed['source'] ?? null) ?? 'manual',
        ];
    }

    private function trimmedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
};
