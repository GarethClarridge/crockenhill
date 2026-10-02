<?php

declare(strict_types=1);

use App\Models\ServiceSection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The classification signature hashes the section type, so renaming
 * `childrens_talk` to `short_talk` made every short talk's stored candidate and
 * approval signature look stale: the next candidate preparation would have
 * re-cut 175 sections' media for a change of name, and some sources are gone.
 *
 * Only a signature that is exactly the old-name hash of the row as it stands is
 * re-stamped; one that was already stale for a real reason stays stale.
 *
 * The payload is frozen here as it stood on 2026-09-24 rather than read from
 * {@see ServiceSection::classificationSignaturePayload()}, so the
 * migration computes the same hashes whenever it runs, whatever the model says by then.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('service_sections')
            ->select(['id', 'church_service_item_id', 'section_type', 'title', 'start_time', 'end_time', 'metadata'])
            ->where('section_type', 'short_talk')
            ->whereNotNull('metadata')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $this->restamp($row);
                }
            });
    }

    public function down(): void
    {
        // Irreversible by design: the old-name hash is only meaningful while the
        // column still says `childrens_talk`, which the earlier migration's own
        // down() restores; a stale stamp would then re-cut media, as before.
    }

    private function restamp(object $row): void
    {
        $metadata = json_decode((string) $row->metadata, true);

        if (! is_array($metadata)) {
            return;
        }

        $oldSignature = $this->signature($row, $metadata, 'childrens_talk');
        $currentSignature = $this->signature($row, $metadata, 'short_talk');
        $changed = false;

        if (($metadata['publication_candidate_extraction']['classification_signature'] ?? null) === $oldSignature) {
            $metadata['publication_candidate_extraction']['classification_signature'] = $currentSignature;
            $changed = true;
        }

        if (($metadata['publication']['approved_signature'] ?? null) === $oldSignature) {
            $metadata['publication']['approved_signature'] = $currentSignature;
            $changed = true;
        }

        if ($changed) {
            DB::table('service_sections')->where('id', $row->id)->update(['metadata' => json_encode($metadata)]);
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function signature(object $row, array $metadata, string $sectionType): string
    {
        $payload = [
            'church_service_item_id' => $row->church_service_item_id === null ? null : (int) $row->church_service_item_id,
            'section_type' => $sectionType,
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
