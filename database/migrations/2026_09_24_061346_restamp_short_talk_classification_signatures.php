<?php

declare(strict_types=1);

use App\Enums\ServiceSectionType;
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
 */
return new class extends Migration
{
    public function up(): void
    {
        ServiceSection::query()
            ->where('section_type', ServiceSectionType::ShortTalk->value)
            ->orderBy('id')
            ->chunkById(100, function ($sections): void {
                foreach ($sections as $section) {
                    $this->restamp($section);
                }
            });
    }

    public function down(): void
    {
        // Irreversible by design: the old-name hash is only meaningful while the
        // column still says `childrens_talk`, which the earlier migration's own
        // down() restores; a stale stamp would then re-cut media, as before.
    }

    private function restamp(ServiceSection $section): void
    {
        $metadata = json_decode((string) DB::table('service_sections')->where('id', $section->id)->value('metadata'), true);

        if (! is_array($metadata)) {
            return;
        }

        $payload = $section->classificationSignaturePayload();
        $payload['section_type'] = 'childrens_talk';
        $oldSignature = hash('sha256', (string) json_encode($payload));
        $currentSignature = $section->classificationSignature();
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
            DB::table('service_sections')->where('id', $section->id)->update(['metadata' => json_encode($metadata)]);
        }
    }
};
