<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Data\DetectorEntry;
use App\Data\SuspectTranscriptBlock;
use App\Enums\DetectorStatus;
use App\Enums\DetectorSurface;
use App\Enums\DetectorUnit;
use App\Services\ChurchService\SectionPublication\SongPublicationReviewPolicy;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Support\DetectorCatalogue;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class DetectorCatalogueTest extends TestCase
{
    /**
     * The guard that stops a new structure flag shipping without an evaluation
     * entry.
     *
     * §4.3a requires every defect class to have a tested response or a recorded
     * decision. A flag the validator emits but the catalogue has never heard of
     * would be neither: it would hold sections in production while the harness
     * reported full coverage of a set that silently excluded it.
     */
    public function test_every_structure_validator_flag_is_catalogued(): void
    {
        $catalogued = DetectorCatalogue::signalsFor(DetectorSurface::SectionReviewFlag);

        foreach ($this->constantsWithPrefix(ServiceStructureValidator::class, 'FLAG_') as $name => $flag) {
            $this->assertContains(
                $flag,
                $catalogued,
                "ServiceStructureValidator::{$name} ({$flag}) emits a review flag that no detector entry claims. "
                .'Add it to DetectorCatalogue, with the severity its class warrants.'
            );
        }
    }

    /**
     * The same guard for the transcript screens, whose reasons are constants on
     * the block rather than on the screen that produces them.
     */
    public function test_every_suspect_transcript_reason_is_catalogued(): void
    {
        $catalogued = DetectorCatalogue::signalsFor(DetectorSurface::SuspectTranscriptBlock);

        foreach ($this->constantsWithPrefix(SuspectTranscriptBlock::class, 'REASON_') as $name => $reason) {
            $this->assertContains(
                $reason,
                $catalogued,
                "SuspectTranscriptBlock::{$name} ({$reason}) is emitted but not catalogued."
            );
        }
    }

    /**
     * {@see SongPublicationReviewPolicy} writes its objection kinds as inline
     * literals, so there are no constants to walk. Reading them back out of the
     * policy's own source is not elegant, but it is the only form of this guard
     * that keeps working when someone adds a seventh kind — a hardcoded list
     * here would simply agree with itself forever.
     */
    public function test_every_song_publication_review_kind_is_catalogued(): void
    {
        $source = file_get_contents((new ReflectionClass(SongPublicationReviewPolicy::class))->getFileName());

        $this->assertIsString($source);
        $this->assertGreaterThan(
            0,
            preg_match_all("/'kind' => '([a-z_]+)'/", $source, $matches),
            "Expected SongPublicationReviewPolicy to declare objection kinds as 'kind' => '…'.",
        );

        $kinds = array_unique($matches[1]);
        $catalogued = DetectorCatalogue::signalsFor(DetectorSurface::SongPublicationReview);

        foreach ($kinds as $kind) {
            $this->assertContains(
                $kind,
                $catalogued,
                "SongPublicationReviewPolicy emits [{$kind}] but no detector entry claims it."
            );
        }
    }

    /**
     * The video quality reasons have neither constants nor a single literal
     * site, so this pins them as a contract: if the assessment service starts
     * using a different vocabulary, this list is where the mismatch surfaces.
     */
    public function test_the_video_quality_reason_vocabulary_is_pinned(): void
    {
        $this->assertSame(
            ['frozen_frames', 'mostly_black', 'partially_black', 'partially_frozen'],
            DetectorCatalogue::signalsFor(DetectorSurface::VideoQualityVerdict),
        );
    }

    public function test_every_promoted_detector_names_a_class_that_exists(): void
    {
        foreach (DetectorCatalogue::promoted() as $entry) {
            $this->assertNotNull($entry->owningClass, "Promoted detector [{$entry->id}] names no class.");
            $this->assertTrue(
                class_exists($entry->owningClass),
                "Promoted detector [{$entry->id}] names [{$entry->owningClass}], which does not exist."
            );
        }
    }

    public function test_it_resolves_a_signal_to_its_owning_detector(): void
    {
        $entry = DetectorCatalogue::forSignal(
            DetectorSurface::SuspectTranscriptBlock,
            SuspectTranscriptBlock::REASON_SPARSE_CADENCE,
        );

        $this->assertInstanceOf(DetectorEntry::class, $entry);
        $this->assertSame('transcript-sparse-cadence', $entry->id);
    }

    /**
     * A signal name is unique only within its surface. Looking one up against
     * the wrong surface must miss rather than return a same-spelled claim from
     * somewhere else.
     */
    public function test_a_signal_does_not_resolve_across_surfaces(): void
    {
        $this->assertNull(DetectorCatalogue::forSignal(
            DetectorSurface::SectionReviewFlag,
            SuspectTranscriptBlock::REASON_SPARSE_CADENCE,
        ));
    }

    public function test_every_entry_is_keyed_by_its_own_id(): void
    {
        foreach (DetectorCatalogue::all() as $key => $entry) {
            $this->assertSame($key, $entry->id);
        }
    }

    public function test_the_catalogue_currently_lists_only_promoted_detectors(): void
    {
        $statuses = array_unique(array_map(
            static fn (DetectorEntry $entry): string => $entry->status->value,
            array_values(DetectorCatalogue::all()),
        ));

        $this->assertSame(
            [DetectorStatus::Promoted->value],
            array_values($statuses),
            'Unbuilt and decided-not-to-detect rows join when the plan table gains its detector_id column.'
        );
    }

    /**
     * A bundled entry is scored as one, so a weak member passes on the strength
     * of its siblings and its own recall is never visible. Bundling therefore
     * has to be a deliberate act rather than a default, and this allowlist is
     * where that deliberation is recorded.
     *
     * `video-dead-picture` is the one legitimate case: its four reasons are
     * outcome bands of a single coverage measurement — how much of the recording
     * is frozen or black — not four independent checks, so splitting them would
     * invent detectors that do not exist. Everything else is one signal per
     * entry, including the four OoS anchoring flags and the two adjacent-song
     * checks, which were split precisely because they are separate questions.
     */
    public function test_only_allowlisted_entries_bundle_several_signals(): void
    {
        $mayBundle = ['video-dead-picture'];

        foreach (DetectorCatalogue::all() as $entry) {
            if (in_array($entry->id, $mayBundle, true)) {
                continue;
            }

            $this->assertCount(
                1,
                $entry->signals,
                "Detector [{$entry->id}] bundles ".count($entry->signals).' signals. A bundle hides a weak '
                .'member behind its strong ones; split it, or add it to this allowlist with the reason.'
            );
        }
    }

    public function test_transcript_detectors_are_counted_in_minutes(): void
    {
        foreach (DetectorCatalogue::all() as $entry) {
            if ($entry->surface !== DetectorSurface::SuspectTranscriptBlock) {
                continue;
            }

            $this->assertSame(
                DetectorUnit::Minute,
                $entry->unit,
                "Detector [{$entry->id}] reports spans, and H10's window sampling bounds defective minutes "
                .'rather than defective runs, so counting it per run claims a precision the sampling cannot support.'
            );
        }
    }

    public function test_the_video_detector_is_counted_in_sermons(): void
    {
        $entry = DetectorCatalogue::find('video-dead-picture');

        $this->assertNotNull($entry);
        $this->assertSame(DetectorUnit::Sermon, $entry->unit);
    }

    /**
     * @return array<string, string>
     */
    private function constantsWithPrefix(string $class, string $prefix): array
    {
        $constants = (new ReflectionClass($class))->getConstants();
        $matching = [];

        foreach ($constants as $name => $value) {
            if (str_starts_with($name, $prefix) && is_string($value)) {
                $matching[$name] = $value;
            }
        }

        $this->assertNotEmpty($matching, "Expected {$class} to declare {$prefix}* constants.");

        return $matching;
    }
}
