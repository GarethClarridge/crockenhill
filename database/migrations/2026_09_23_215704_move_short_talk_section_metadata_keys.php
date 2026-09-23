<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Talks plan §4.5 step 3: every section migrated to `short_talk` was detected
 * under the children's-cue rule, so it carries an honest `childrens_talk`
 * proposal; and the speaker, boundary and review-flag names drop "children's".
 *
 * Snapshots of earlier rows (`previous_section`, `derived_from_section_type`)
 * and banked detector output are evidence of what was said then, and are left alone.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const KEYS = [
        'childrens_talk_speaker' => 'talk_speaker',
        'childrens_talk_boundary' => 'short_talk_boundary',
    ];

    /** @var array<string, string> */
    private const FLAG_PREFIXES = [
        'childrens_talk_speaker' => 'talk_speaker',
        'ambiguous_childrens_talk' => 'ambiguous_short_talk',
        'inferred_childrens_talk' => 'inferred_short_talk',
    ];

    public function up(): void
    {
        $this->rewrite(self::KEYS, self::FLAG_PREFIXES, addProposal: true);
    }

    public function down(): void
    {
        $this->rewrite(array_flip(self::KEYS), array_flip(self::FLAG_PREFIXES), addProposal: false);
    }

    /**
     * @param  array<string, string>  $keys
     * @param  array<string, string>  $flagPrefixes
     */
    private function rewrite(array $keys, array $flagPrefixes, bool $addProposal): void
    {
        DB::table('service_sections')
            ->select(['id', 'section_type', 'metadata'])
            ->whereNotNull('metadata')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($keys, $flagPrefixes, $addProposal): void {
                foreach ($rows as $row) {
                    $metadata = json_decode((string) $row->metadata, true);

                    if (! is_array($metadata)) {
                        continue;
                    }

                    $updated = $this->rewriteMetadata($metadata, $keys, $flagPrefixes);

                    if ($row->section_type === 'short_talk') {
                        if ($addProposal && ! array_key_exists('talk_type', $updated)) {
                            $updated['talk_type'] = ['proposed' => 'childrens_talk'];
                        }

                        if (! $addProposal) {
                            unset($updated['talk_type']);
                        }
                    }

                    if ($updated !== $metadata) {
                        DB::table('service_sections')
                            ->where('id', $row->id)
                            ->update(['metadata' => json_encode($updated)]);
                    }
                }
            });
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, string>  $keys
     * @param  array<string, string>  $flagPrefixes
     * @return array<string, mixed>
     */
    private function rewriteMetadata(array $metadata, array $keys, array $flagPrefixes): array
    {
        foreach ($keys as $from => $to) {
            if (array_key_exists($from, $metadata)) {
                $metadata[$to] = $metadata[$from];
                unset($metadata[$from]);
            }
        }

        if (is_array($metadata['review_flags'] ?? null)) {
            $metadata['review_flags'] = array_map(
                fn (mixed $flag): mixed => is_string($flag) ? $this->renameFlag($flag, $flagPrefixes) : $flag,
                $metadata['review_flags'],
            );
        }

        if (is_string($metadata['review_reason'] ?? null)) {
            $metadata['review_reason'] = $this->renameFlag($metadata['review_reason'], $flagPrefixes);
        }

        return $metadata;
    }

    /**
     * @param  array<string, string>  $flagPrefixes
     */
    private function renameFlag(string $flag, array $flagPrefixes): string
    {
        foreach ($flagPrefixes as $from => $to) {
            if (str_starts_with($flag, $from)) {
                return $to.substr($flag, strlen($from));
            }
        }

        return $flag;
    }
};
