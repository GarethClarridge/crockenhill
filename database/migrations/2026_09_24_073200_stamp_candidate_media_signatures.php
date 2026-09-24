<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Candidate media used to be stamped with the classification signature, which
 * also carries a short talk's confirmed speaker and type. Confirming either made
 * the candidate look stale, so the next preparation re-cut it from source, or
 * failed where the source is gone. Candidates are now stamped with a media
 * signature of what the cut depends on (handler type and span).
 *
 * A stamp moves to the media signature only when it matches the row's cut as it
 * stands: the classification hash of the row now, or of the row before its
 * speaker or type was confirmed, or under the pre-rename `childrens_talk` name.
 * Any other stamp was stale for a real reason (a moved boundary, a retitle) and
 * is left for re-cutting, as it would have been.
 *
 * Both payloads are frozen as they stood on 2026-09-24.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->eachStampedCandidate(function (object $row, array $metadata, array $stamp): ?array {
            $signature = $stamp['classification_signature'] ?? null;

            if (! is_string($signature) || ! in_array($signature, $this->classificationSignatures($row, $metadata), true)) {
                return null;
            }

            unset($stamp['classification_signature']);
            $stamp['media_signature'] = $this->mediaSignature($row);

            return $stamp;
        });
    }

    public function down(): void
    {
        $this->eachStampedCandidate(function (object $row, array $metadata, array $stamp): ?array {
            if (($stamp['media_signature'] ?? null) !== $this->mediaSignature($row)) {
                return null;
            }

            unset($stamp['media_signature']);
            $stamp['classification_signature'] = $this->classificationSignatures($row, $metadata)[0];

            return $stamp;
        });
    }

    /**
     * @param  callable(object, array<string, mixed>, array<string, mixed>): (array<string, mixed>|null)  $rewrite
     */
    private function eachStampedCandidate(callable $rewrite): void
    {
        DB::table('service_sections')
            ->select(['id', 'church_service_item_id', 'section_type', 'title', 'start_time', 'end_time', 'metadata'])
            ->whereNotNull('metadata->publication_candidate_extraction')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($rewrite): void {
                foreach ($rows as $row) {
                    $metadata = json_decode((string) $row->metadata, true);
                    $stamp = is_array($metadata) ? ($metadata['publication_candidate_extraction'] ?? null) : null;

                    if (! is_array($stamp)) {
                        continue;
                    }

                    $updated = $rewrite($row, $metadata, $stamp);

                    if ($updated !== null) {
                        $metadata['publication_candidate_extraction'] = $updated;
                        DB::table('service_sections')->where('id', $row->id)->update(['metadata' => json_encode($metadata)]);
                    }
                }
            });
    }

    private function mediaSignature(object $row): string
    {
        return hash('sha256', (string) json_encode([
            'section_type' => $row->section_type,
            'start_time' => (float) $row->start_time,
            'end_time' => (float) $row->end_time,
        ]));
    }

    /**
     * Every classification hash under which this row's current cut could have
     * been stamped; the first is the hash of the row exactly as it stands.
     *
     * @param  array<string, mixed>  $metadata
     * @return list<string>
     */
    private function classificationSignatures(object $row, array $metadata): array
    {
        $base = [
            'church_service_item_id' => $row->church_service_item_id === null ? null : (int) $row->church_service_item_id,
            'section_type' => $row->section_type,
            'title' => $row->title,
            'start_time' => (float) $row->start_time,
            'end_time' => (float) $row->end_time,
        ];

        if ($row->section_type !== 'short_talk') {
            return [hash('sha256', (string) json_encode($base))];
        }

        $speaker = $this->publicationSpeaker($metadata);
        $talkType = $this->trimmedString($metadata['talk_type']['reviewed']['value'] ?? null);
        $signatures = [];

        foreach (['short_talk', 'childrens_talk'] as $sectionType) {
            foreach (array_unique([$speaker, null], SORT_REGULAR) as $speakerVariant) {
                foreach (array_unique([$talkType, null]) as $talkTypeVariant) {
                    $payload = $base;
                    $payload['section_type'] = $sectionType;
                    $payload['publication_speaker'] = $speakerVariant;

                    if ($talkTypeVariant !== null) {
                        $payload['talk_type'] = $talkTypeVariant;
                    }

                    $signatures[] = hash('sha256', (string) json_encode($payload));
                }
            }
        }

        return $signatures;
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
