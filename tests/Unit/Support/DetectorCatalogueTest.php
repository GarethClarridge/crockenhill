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
use App\Services\DetectorEvaluation\SectionReviewFlagSignals;
use App\Services\DetectorEvaluation\SongBoundaryEvidenceSignals;
use App\Services\DetectorEvaluation\SongPublicationReviewSignals;
use App\Services\DetectorEvaluation\SuspectTranscriptBlockSignals;
use App\Services\DetectorEvaluation\VideoQualityVerdictSignals;
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
     *
     * `song-boundary-evidence-unavailable` is the second: its two kinds differ
     * only in *why* the pass could not look — a missing input or an unreadable
     * one — and both mean the same thing to the harness, which is that this
     * section was never assessed. Scoring them apart would imply the difference
     * changes what the evidence is worth.
     */
    public function test_only_allowlisted_entries_bundle_several_signals(): void
    {
        $mayBundle = ['video-dead-picture', 'song-boundary-evidence-unavailable'];

        foreach (DetectorCatalogue::all() as $entry) {
            if (in_array($entry->id, $mayBundle, true) || ! $entry->status->emitsSignals()) {
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
     * The same guard again, for the holds that do not come from the validator.
     *
     * `test_every_structure_validator_flag_is_catalogued` walks one class's
     * constants, and on 2026-09-21 that turned out to be the whole weakness: the
     * `Flag*` actions write to the same `review_flags` array on the same
     * section, but they declare their flag on themselves, so five promoted
     * detectors held sections in production while the catalogue had never heard
     * of them. A guard aimed at one emitter only guards one emitter.
     *
     * This walks the directory instead of a hand-kept list, so a sixth action
     * cannot ship uncatalogued by being left off a list nobody remembers to
     * update.
     */
    public function test_every_flag_action_writes_a_catalogued_review_flag(): void
    {
        $catalogued = DetectorCatalogue::signalsFor(DetectorSurface::SectionReviewFlag);
        $checked = 0;

        // Resolved from this file rather than through `app_path()`: this is a
        // plain PHPUnit test with no container booted.
        $actions = dirname(__DIR__, 3).'/app/Actions/Flag*.php';

        foreach (glob($actions) ?: [] as $path) {
            $class = 'App\\Actions\\'.basename($path, '.php');

            if (! class_exists($class) || ! defined($class.'::FLAG')) {
                continue;
            }

            /** @var string $flag */
            $flag = constant($class.'::FLAG');
            $checked++;

            if (in_array($flag, self::CONSEQUENCE_FLAGS, true)) {
                continue;
            }

            $this->assertContains(
                $flag,
                $catalogued,
                "{$class}::FLAG ({$flag}) holds sections in production but no detector entry claims it. "
                .'Add it to DetectorCatalogue, or record it as a consequence flag with the reason.'
            );
        }

        $this->assertGreaterThan(1, $checked, 'Expected to find several Flag* actions to check.');
    }

    /**
     * The boundary evidence vocabulary, pinned the way the video reasons are.
     *
     * Two things make this list worth pinning rather than deriving. The service
     * builds most kinds as inline literals, so there are no constants to walk;
     * and a source search over-reports badly, because the service returns a
     * `reason` for every boundary observation while the caller promotes one to
     * `risks` only when the observation carries `risk => true`. Four
     * grep-visible kinds never emit at all. The list below is what a census of
     * the stored corpus actually found, plus the two unassessable kinds that are
     * appended as literals on the no-inputs path.
     */
    public function test_the_song_boundary_evidence_vocabulary_is_pinned(): void
    {
        $this->assertSame(
            [
                'song_boundary_evidence_unavailable',
                'song_boundary_evidence_unreadable',
                'song_boundary_spoken_framing',
                'song_boundary_spoken_framing_exceeds_limit',
                'song_boundary_trailing_content',
                'song_looped_transcript',
                'song_lyrics_outside_section',
            ],
            DetectorCatalogue::signalsFor(DetectorSurface::SongBoundaryEvidence),
        );
    }

    /**
     * The kinds a source search finds that the surface never actually emits.
     *
     * Cataloguing one would invent a detector that cannot fire, and the harness
     * would then report perfect recall for it forever — a worse outcome than
     * leaving it out, because it looks like coverage. If one of these is ever
     * promoted to a real risk, this test fails and the catalogue gains an entry
     * deliberately.
     */
    public function test_non_emitting_boundary_reasons_are_not_catalogued(): void
    {
        $catalogued = DetectorCatalogue::signalsFor(DetectorSurface::SongBoundaryEvidence);

        foreach ([
            'song_boundary_unobservable_gap',
            'song_boundary_looped_gap',
            'song_boundary_without_rms_corroboration',
            'song_boundary_spoken_framing_below_floor',
        ] as $reason) {
            $this->assertNotContains(
                $reason,
                $catalogued,
                "[{$reason}] is returned on a risk => false path, so it is evidence for keeping a clip, not a finding."
            );
        }
    }

    /**
     * Every surface the harness models must have an adapter that reads it.
     *
     * The gap this closes was not a missing entry but a missing *reader*: the
     * plan listed song boundary evidence as a surface from the start, and the
     * catalogue could have carried entries for it for months while no adapter
     * ever turned them into signals. A surface with no adapter reports zero
     * findings, which is indistinguishable from a clean corpus.
     */
    public function test_every_surface_has_an_adapter(): void
    {
        $adapters = [
            DetectorSurface::SectionReviewFlag->value => SectionReviewFlagSignals::class,
            DetectorSurface::SuspectTranscriptBlock->value => SuspectTranscriptBlockSignals::class,
            DetectorSurface::VideoQualityVerdict->value => VideoQualityVerdictSignals::class,
            DetectorSurface::SongPublicationReview->value => SongPublicationReviewSignals::class,
            DetectorSurface::SongBoundaryEvidence->value => SongBoundaryEvidenceSignals::class,
        ];

        foreach (DetectorSurface::cases() as $surface) {
            $this->assertArrayHasKey(
                $surface->value,
                $adapters,
                "DetectorSurface::{$surface->name} has no adapter, so everything catalogued on it reads as no findings."
            );
            $this->assertTrue(class_exists($adapters[$surface->value]));
        }
    }

    /**
     * A flag that restates another detector's finding rather than making one.
     *
     * `transcript_repetition_suspect` is raised by
     * {@see \App\Actions\FlagSuspectTranscriptRepetition} from the suspect
     * blocks the transcript screens already emitted, so it carries no claim of
     * its own. Cataloguing it as a detector would score the transcript screens
     * twice — once on their blocks and once on the hold those blocks produce —
     * and inflate their apparent coverage.
     *
     * @var list<string>
     */
    private const CONSEQUENCE_FLAGS = ['transcript_repetition_suspect'];

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
