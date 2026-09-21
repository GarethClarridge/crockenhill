<?php

declare(strict_types=1);

namespace App\Support;

use App\Actions\FlagIncompleteSermonEvidence;
use App\Actions\FlagSectionTruncatedBySource;
use App\Actions\FlagSermonAudioLengthMismatch;
use App\Actions\FlagSermonPartsNotExtracted;
use App\Actions\FlagSermonTextPredatesEvidence;
use App\Data\DetectorEntry;
use App\Data\SuspectTranscriptBlock;
use App\Enums\DetectorSeverity;
use App\Enums\DetectorStatus;
use App\Enums\DetectorSurface;
use App\Enums\DetectorUnit;
use App\Services\ChurchService\SectionPublication\SongLoopedTranscript;
use App\Services\ChurchService\SectionPublication\SongLyricsOutsideSection;
use App\Services\ChurchService\SectionPublication\SongPublicationBoundaryEvidenceService;
use App\Services\ChurchService\SectionPublication\SongPublicationReviewPolicy;
use App\Services\DetectorEvaluation\SongBoundaryEvidenceSignals;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\Media\Audio\ServiceTranscriptRepetitionScreen;
use App\Services\Media\Video\SermonVideoQualityAssessmentService;
use RuntimeException;

/**
 * The machine-readable form of §4.3a's defect-class table: which detectors
 * exist, what they emit, how badly their class hurts a reader, and which of this
 * plan's cases are their regression fixtures.
 *
 * **Why this is code and not just the table.** §4.3a requires every class found
 * to have a tested response or a recorded decision, and a markdown table cannot
 * be held to that — it drifts the moment a flag is renamed or a screen gains a
 * reason. Here the signal names are taken from the emitting classes' own
 * constants wherever those exist, so a rename is a compile-time concern rather
 * than a silently stale row. The paired test walks
 * {@see ServiceStructureValidator}'s and {@see SuspectTranscriptBlock}'s
 * constants and fails when one is not catalogued, which is what stops a new
 * detector shipping without an evaluation entry.
 *
 * Two vocabularies are still string literals because their emitting code has no
 * constants to borrow: {@see SermonVideoQualityAssessmentService}'s reasons and
 * {@see SongPublicationReviewPolicy}'s objection kinds. Those are pinned by the
 * paired test instead, as is the song boundary vocabulary — which cannot be
 * derived from its source at all, because the service builds a reason for every
 * observation and only those carrying `risk => true` are ever emitted.
 *
 * **Scope since 2026-09-21.** Every row of the plan's class table is here, not
 * only the promoted detectors, because the table gained its `detector_id`
 * column and the two are bound by a test. Most entries therefore emit nothing:
 * they are classes fixed at source, ruled on, prototyped or still open. They
 * carry no surface and no signals, and {@see DetectorEntry} refuses one that
 * does, because every adapter keys off the surface and an entry that kept one
 * would be read as a live detector.
 *
 * So this catalogue now answers both "what can be evaluated" — {@see evaluable()}
 * — and "what classes exist", which are deliberately different questions.
 */
class DetectorCatalogue
{
    public const Version = 1;

    /** @var array<string, DetectorEntry>|null */
    private static ?array $entries = null;

    /**
     * @return array<string, DetectorEntry>
     */
    public static function all(): array
    {
        return self::$entries ??= self::build();
    }

    public static function find(string $id): ?DetectorEntry
    {
        return self::all()[$id] ?? null;
    }

    /**
     * @return list<DetectorEntry>
     */
    public static function promoted(): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (DetectorEntry $entry): bool => $entry->status === DetectorStatus::Promoted,
        ));
    }

    /**
     * @return list<DetectorEntry>
     */
    public static function evaluable(): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (DetectorEntry $entry): bool => $entry->isEvaluable(),
        ));
    }

    /**
     * The entry that owns one signal on one surface.
     *
     * Signals are unique per surface, never globally: the same spelling on two
     * surfaces is two different claims.
     */
    public static function forSignal(DetectorSurface $surface, string $signal): ?DetectorEntry
    {
        foreach (self::all() as $entry) {
            if ($entry->surface === $surface && $entry->emits($signal)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Every signal this catalogue claims on one surface.
     *
     * @return list<string>
     */
    public static function signalsFor(DetectorSurface $surface): array
    {
        $signals = [];

        foreach (self::all() as $entry) {
            if ($entry->surface === $surface) {
                $signals = [...$signals, ...$entry->signals];
            }
        }

        sort($signals);

        return $signals;
    }

    /**
     * @return array<string, DetectorEntry>
     */
    private static function build(): array
    {
        $entries = [];
        $claimed = [];

        foreach (self::entries() as $entry) {
            if (isset($entries[$entry->id])) {
                throw new RuntimeException("Duplicate detector id [{$entry->id}] in the catalogue.");
            }

            // Only emitting entries can collide: a fixed, ruled-on or unbuilt
            // class names no surface and no signals, so it claims nothing.
            $surface = $entry->surface;

            if ($surface !== null) {
                foreach ($entry->signals as $signal) {
                    $key = $surface->value.'::'.$signal;

                    if (isset($claimed[$key])) {
                        throw new RuntimeException(
                            "Signal [{$signal}] on surface [{$surface->value}] is claimed by both "
                            ."[{$claimed[$key]}] and [{$entry->id}]."
                        );
                    }

                    $claimed[$key] = $entry->id;
                }
            }

            $entries[$entry->id] = $entry;
        }

        return $entries;
    }

    /**
     * @return list<DetectorEntry>
     */
    private static function entries(): array
    {
        return [
            ...self::transcriptEntries(),
            ...self::structureEntries(),
            ...self::mediaEvidenceEntries(),
            ...self::songEntries(),
            ...self::songBoundaryEntries(),
            ...self::videoEntries(),
            // Classes from §4.3a's table that emit nothing: fixed at source,
            // ruled on, prototyped or still open. They carry no surface and no
            // signals, and exist so that a class with no detector is
            // distinguishable from one nobody has looked at.
            ...self::extractionClassEntries(),
            ...self::transcriptClassEntries(),
            ...self::structureClassEntries(),
            ...self::songClassEntries(),
            ...self::scriptureClassEntries(),
            ...self::mediaQualityClassEntries(),
            ...self::membershipClassEntries(),
            ...self::releaseClassEntries(),
        ];
    }

    /**
     * @return list<DetectorEntry>
     */
    private static function transcriptEntries(): array
    {
        return [
            new DetectorEntry(
                id: 'transcript-repeated-phrase-loop',
                surface: DetectorSurface::SuspectTranscriptBlock,
                signals: [SuspectTranscriptBlock::REASON_REPEATED_PHRASE],
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::PublishedWrongContent,
                unit: DetectorUnit::Minute,
                summary: 'A phrase repeated verbatim past the point speech explains it, so the saved text claims words the audio did not produce.',
                owningClass: ServiceTranscriptRepetitionScreen::class,
                regressionCases: ['run 929', 'run 1008', 'run 1187', 'run 1068', 'run 1317', 'run 1347'],
            ),
            new DetectorEntry(
                id: 'transcript-implausible-density',
                surface: DetectorSurface::SuspectTranscriptBlock,
                signals: [SuspectTranscriptBlock::REASON_IMPLAUSIBLE_DENSITY],
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::PublishedWrongContent,
                unit: DetectorUnit::Minute,
                summary: 'Sustained words per minute beyond what a speaker can produce, which marks decoded text rather than speech.',
                owningClass: ServiceTranscriptRepetitionScreen::class,
            ),
            new DetectorEntry(
                id: 'transcript-sparse-cadence',
                surface: DetectorSurface::SuspectTranscriptBlock,
                signals: [SuspectTranscriptBlock::REASON_SPARSE_CADENCE],
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::PublishedWrongContent,
                unit: DetectorUnit::Minute,
                summary: 'One short cue on each 30-second decoder boundary inside otherwise dense speech: the decoder emitted a placeholder while real speech was lost.',
                owningClass: ServiceTranscriptRepetitionScreen::class,
                regressionCases: ['run 1112 §3739', 'run 1278 §3490'],
            ),
        ];
    }

    /**
     * @return list<DetectorEntry>
     */
    private static function structureEntries(): array
    {
        return [
            new DetectorEntry(
                id: 'structure-macro-section',
                surface: DetectorSurface::SectionReviewFlag,
                signals: [ServiceStructureValidator::FLAG_MACRO_SECTION],
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::ContentLost,
                unit: DetectorUnit::Section,
                summary: "A section long enough to have swallowed its neighbour, the class behind song sections taking the sermon's opening words.",
                owningClass: ServiceStructureValidator::class,
                regressionCases: ['run 1007', 'run 1217', 'run 1340'],
            ),
            new DetectorEntry(
                id: 'structure-micro-section',
                surface: DetectorSurface::SectionReviewFlag,
                signals: [ServiceStructureValidator::FLAG_MICRO_SECTION],
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::WrongMetadata,
                unit: DetectorUnit::Section,
                summary: 'A section too short to be the item it claims to be.',
                owningClass: ServiceStructureValidator::class,
            ),
            new DetectorEntry(
                id: 'structure-low-confidence',
                surface: DetectorSurface::SectionReviewFlag,
                signals: [ServiceStructureValidator::FLAG_LOW_CONFIDENCE],
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::WrongMetadata,
                unit: DetectorUnit::Section,
                summary: 'The detector reported low confidence in its own typing of the section.',
                owningClass: ServiceStructureValidator::class,
            ),
            new DetectorEntry(
                id: 'structure-sermon-boundary-material-risk',
                surface: DetectorSurface::SectionReviewFlag,
                signals: [ServiceStructureValidator::FLAG_SERMON_BOUNDARY_MATERIAL_RISK],
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::ContentLost,
                unit: DetectorUnit::Section,
                summary: "Material sits close enough to the sermon's boundary that the cut may take or lose real preaching.",
                owningClass: ServiceStructureValidator::class,
            ),
            new DetectorEntry(
                id: 'structure-sermon-interruption-merged',
                surface: DetectorSurface::SectionReviewFlag,
                signals: [ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED],
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::ContentLost,
                unit: DetectorUnit::Section,
                summary: 'An interruption inside the sermon was merged away, so the span may carry material that is not preaching.',
                owningClass: ServiceStructureValidator::class,
            ),
            new DetectorEntry(
                id: 'structure-unidentified-singing',
                surface: DetectorSurface::SectionReviewFlag,
                signals: [ServiceStructureValidator::FLAG_UNIDENTIFIED_SINGING],
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::ContentLost,
                unit: DetectorUnit::Section,
                summary: 'Singing was heard where no song item accounts for it, so a performed song may have no section at all.',
                owningClass: ServiceStructureValidator::class,
                regressionCases: ['run 944'],
            ),
            new DetectorEntry(
                id: 'structure-song-widened-to-sustained-sound',
                surface: DetectorSurface::SectionReviewFlag,
                signals: [ServiceStructureValidator::FLAG_SONG_WIDENED_TO_SUSTAINED_SOUND],
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::ContentLost,
                unit: DetectorUnit::Section,
                summary: 'A song section was widened to the sustained sound around it, recovering singing the transcript could not see.',
                owningClass: ServiceStructureValidator::class,
                regressionCases: ['run 965', 'run 1109'],
            ),
            new DetectorEntry(
                id: 'structure-section-reads-as-sung',
                surface: DetectorSurface::SectionReviewFlag,
                signals: [ServiceStructureValidator::FLAG_SECTION_READS_AS_SUNG],
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::ContentLost,
                unit: DetectorUnit::Section,
                summary: 'A section typed reading, prayer or other reads as sung text, so a song may be published as something else.',
                owningClass: ServiceStructureValidator::class,
                regressionCases: ['run 1014 §1301'],
            ),
            new DetectorEntry(
                id: 'structure-missing-preached-reading',
                surface: DetectorSurface::SectionReviewFlag,
                signals: [ServiceStructureValidator::FLAG_MISSING_PREACHED_READING],
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::WrongMetadata,
                unit: DetectorUnit::Section,
                summary: 'No reading section pairs with the sermon, and the sermon named no passage of its own.',
                owningClass: ServiceStructureValidator::class,
            ),
            new DetectorEntry(
                id: 'structure-benediction-suspect',
                surface: DetectorSurface::SectionReviewFlag,
                signals: [ServiceStructureValidator::FLAG_BENEDICTION_SUSPECT],
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::WrongMetadata,
                unit: DetectorUnit::Section,
                summary: 'A short closing reading that is probably a benediction read verbatim rather than a Scripture reading.',
                owningClass: ServiceStructureValidator::class,
            ),
            // The four OoS anchoring flags are deliberately four entries rather
            // than one. They share a severity and a cause — detected structure
            // disagreeing with the order of service — but a bundle is scored as
            // a unit, so a weak member passes on the strength of the others and
            // its own recall is never visible. One entry per flag costs nothing
            // and keeps each measurable.
            new DetectorEntry(
                id: 'structure-oos-cross-type-inversion',
                surface: DetectorSurface::SectionReviewFlag,
                signals: [ServiceStructureValidator::FLAG_OOS_CROSS_TYPE_INVERSION],
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::WrongMetadata,
                unit: DetectorUnit::Section,
                summary: 'Sections of different types anchor to order-of-service items in inverted order, questioning which item a section belongs to.',
                owningClass: ServiceStructureValidator::class,
            ),
            new DetectorEntry(
                id: 'structure-oos-same-type-inversion',
                surface: DetectorSurface::SectionReviewFlag,
                signals: [ServiceStructureValidator::FLAG_OOS_SAME_TYPE_INVERSION],
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::WrongMetadata,
                unit: DetectorUnit::Section,
                summary: 'Two sections of the same type anchor to their items in inverted order, so a clip may carry the neighbouring item\'s identity.',
                owningClass: ServiceStructureValidator::class,
            ),
            new DetectorEntry(
                id: 'structure-oos-type-unresolved',
                surface: DetectorSurface::SectionReviewFlag,
                signals: [ServiceStructureValidator::FLAG_OOS_TYPE_UNRESOLVED],
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::WrongMetadata,
                unit: DetectorUnit::Section,
                summary: 'The order of service names an item whose type could not be resolved against the detected structure.',
                owningClass: ServiceStructureValidator::class,
            ),
            new DetectorEntry(
                id: 'structure-oos-structure-mismatch',
                surface: DetectorSurface::SectionReviewFlag,
                signals: [ServiceStructureValidator::FLAG_OOS_STRUCTURE_MISMATCH],
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::WrongMetadata,
                unit: DetectorUnit::Section,
                summary: 'The detected section count or ordering does not match the order of service.',
                owningClass: ServiceStructureValidator::class,
            ),
        ];
    }

    /**
     * Holds raised by the `Flag*` actions rather than by the structure detector.
     *
     * These share {@see DetectorSurface::SectionReviewFlag} with the validator's
     * flags — they are written to the same `review_flags` array on the same
     * section — but they are a different kind of claim, and
     * {@see FlagIncompleteSermonEvidence}'s docblock says why: a validator flag
     * describes the detector's confidence in a boundary and is re-derived from
     * the banked structure, while these describe the *recording behind* the
     * boundary and must survive a recompute.
     *
     * They went uncatalogued until 2026-09-21 because the guard test walked
     * {@see ServiceStructureValidator}'s constants alone, and these live on
     * their own action classes. Five promoted detectors were therefore holding
     * sections in production while the harness reported full coverage of a set
     * that excluded them — the exact failure that test exists to prevent,
     * reached through a door it was not watching.
     *
     * @return list<DetectorEntry>
     */
    private static function mediaEvidenceEntries(): array
    {
        return [
            new DetectorEntry(
                id: 'sermon-evidence-incomplete',
                surface: DetectorSurface::SectionReviewFlag,
                signals: [FlagIncompleteSermonEvidence::FLAG],
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::ContentLost,
                unit: DetectorUnit::Sermon,
                summary: "Too much of the sermon's delivered span is blind for the saved text to be taken as an account of what was preached.",
                owningClass: FlagIncompleteSermonEvidence::class,
            ),
            new DetectorEntry(
                id: 'section-truncated-by-source',
                surface: DetectorSurface::SectionReviewFlag,
                signals: [FlagSectionTruncatedBySource::FLAG],
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::ContentLost,
                unit: DetectorUnit::Section,
                summary: 'The recording stopped before the section did, so what survives inside the clamped bound may not be publishable.',
                owningClass: FlagSectionTruncatedBySource::class,
                regressionCases: ['§3704'],
            ),
            new DetectorEntry(
                id: 'sermon-audio-length-mismatch',
                surface: DetectorSurface::SectionReviewFlag,
                signals: [FlagSermonAudioLengthMismatch::FLAG],
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::ContentLost,
                unit: DetectorUnit::Sermon,
                summary: "The sermon MP3 is not the whole audio track of its video, the shape behind twelve MP3s that lost their closing words.",
                owningClass: FlagSermonAudioLengthMismatch::class,
            ),
            new DetectorEntry(
                id: 'sermon-parts-not-extracted',
                surface: DetectorSurface::SectionReviewFlag,
                signals: [FlagSermonPartsNotExtracted::FLAG],
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::ContentLost,
                unit: DetectorUnit::Sermon,
                summary: 'Stored media covers fewer parts than the extraction plan now names, so a marked continuation was never cut in.',
                owningClass: FlagSermonPartsNotExtracted::class,
            ),
            new DetectorEntry(
                id: 'sermon-text-predates-evidence',
                surface: DetectorSurface::SectionReviewFlag,
                signals: [FlagSermonTextPredatesEvidence::FLAG],
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::PublishedWrongContent,
                unit: DetectorUnit::Sermon,
                summary: 'The saved text was sliced from a transcript the run no longer holds, so a reader still sees loops recovery has already removed.',
                owningClass: FlagSermonTextPredatesEvidence::class,
                regressionCases: ['P8-Q1'],
            ),
        ];
    }

    /**
     * @return list<DetectorEntry>
     */
    private static function songEntries(): array
    {
        return [
            new DetectorEntry(
                id: 'song-identity-from-suspect-transcript',
                surface: DetectorSurface::SectionReviewFlag,
                signals: [SongCatalogueTitlePolicy::FLAG_IDENTITY_UNVERIFIED_FROM_SUSPECT_TRANSCRIPT],
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::PublishedWrongContent,
                unit: DetectorUnit::Section,
                summary: 'A song title taken from transcript text that a suspect block overlaps, so the lyrics that named it may never have been sung.',
                owningClass: SongCatalogueTitlePolicy::class,
            ),
            new DetectorEntry(
                id: 'song-title-marker-mismatch',
                surface: DetectorSurface::SectionReviewFlag,
                signals: [ServiceStructureValidator::FLAG_SONG_TITLE_MARKER_MISMATCH],
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::WrongMetadata,
                unit: DetectorUnit::Section,
                summary: 'The song title and the marker text in the transcript disagree.',
                owningClass: ServiceStructureValidator::class,
            ),
            new DetectorEntry(
                id: 'song-unresolved-multiple-songs',
                surface: DetectorSurface::SongPublicationReview,
                signals: ['unresolved_multiple_songs'],
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::PublishedWrongContent,
                unit: DetectorUnit::Section,
                summary: 'One clip contains more than one song and the section is assigned to a single identity.',
                owningClass: SongPublicationReviewPolicy::class,
                regressionCases: ['§3869 run 1304 SongVideo 371', '§1276 video 172'],
            ),
            new DetectorEntry(
                id: 'song-short-clip',
                surface: DetectorSurface::SongPublicationReview,
                signals: ['short_song_clip'],
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::ContentLost,
                unit: DetectorUnit::Section,
                summary: 'A clip too short to hold the song it claims, the shape a boundary that lost verses leaves behind.',
                owningClass: SongPublicationReviewPolicy::class,
            ),
            new DetectorEntry(
                id: 'song-inferred-match',
                surface: DetectorSurface::SongPublicationReview,
                signals: ['inferred_song_match'],
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::WrongMetadata,
                unit: DetectorUnit::Section,
                summary: 'The song identity was inferred rather than confirmed against an order-of-service item.',
                owningClass: SongPublicationReviewPolicy::class,
            ),
            new DetectorEntry(
                id: 'song-adjacent-same-song',
                surface: DetectorSurface::SongPublicationReview,
                signals: ['adjacent_same_song'],
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::WrongMetadata,
                unit: DetectorUnit::Section,
                summary: 'A neighbouring section claims the same song, so one performance may have been published twice or bound to the wrong item.',
                owningClass: SongPublicationReviewPolicy::class,
                regressionCases: ['run 1337 §4275 video 410'],
            ),
            new DetectorEntry(
                id: 'song-unlocated-adjacent',
                surface: DetectorSurface::SongPublicationReview,
                signals: ['unlocated_adjacent_song'],
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::WrongMetadata,
                unit: DetectorUnit::Section,
                summary: 'An order-of-service song next to this one has no section of its own, so this clip may hold both.',
                owningClass: SongPublicationReviewPolicy::class,
            ),
            new DetectorEntry(
                id: 'song-uncorroborated-partial-recording',
                surface: DetectorSurface::SongPublicationReview,
                signals: ['uncorroborated_partial_recording'],
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::WrongMetadata,
                unit: DetectorUnit::Section,
                summary: 'A partial recording cannot corroborate song membership, so its songs carry no independent evidence.',
                owningClass: SongPublicationReviewPolicy::class,
            ),
        ];
    }

    /**
     * The risks the boundary evidence pass records about a song cut.
     *
     * Catalogued 2026-09-21, having been written by production and read by no
     * adapter since the surface was built. `song_looped_transcript` is §4.3a's
     * song-loop class over 226 sections, so the omission was not a corner.
     *
     * **Only kinds that actually reach `risks` are here, and that had to be
     * measured rather than grepped.** `SongPublicationBoundaryEvidenceService`
     * builds a `reason` for every boundary observation it makes, but the caller
     * appends one to `risks` only when that observation carries
     * `'risk' => true`. Four kinds a source search finds —
     * `song_boundary_unobservable_gap`, `song_boundary_looped_gap`,
     * `song_boundary_without_rms_corroboration` and
     * `song_boundary_spoken_framing_below_floor` — are returned on
     * `'risk' => false` paths, so they are recorded as *evidence* for a decision
     * to keep the clip and never emitted as findings. Cataloguing them would
     * have invented four detectors that cannot fire, and the harness would have
     * reported perfect recall for each. A census of the stored corpus is what
     * exposed this: it returned five kinds, two of which no source grep had
     * found, and none of the four.
     *
     * The two `song_boundary_evidence_*` kinds are one entry at S4: they record
     * the pass being unable to look, which belongs in the unassessable column
     * rather than in a recall denominator. Neither has fired on this corpus.
     *
     * @return list<DetectorEntry>
     */
    private static function songBoundaryEntries(): array
    {
        return [
            new DetectorEntry(
                id: 'song-looped-transcript',
                surface: DetectorSurface::SongBoundaryEvidence,
                signals: [SongLoopedTranscript::RISK_KIND],
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::PublishedWrongContent,
                unit: DetectorUnit::Section,
                summary: "The clip's transcript repeats phrases its bound song does not contain, so the text describes a performance that did not happen.",
                owningClass: SongLoopedTranscript::class,
                regressionCases: ['§1216', '§1862', '§3750'],
            ),
            new DetectorEntry(
                id: 'song-lyrics-outside-section',
                surface: DetectorSurface::SongBoundaryEvidence,
                signals: [SongLyricsOutsideSection::RISK_KIND],
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::ContentLost,
                unit: DetectorUnit::Section,
                summary: "Lyrics of this song are sung outside the section's bounds, so the cut lost part of the performance it claims.",
                owningClass: SongLyricsOutsideSection::class,
            ),
            new DetectorEntry(
                id: 'song-boundary-spoken-framing',
                surface: DetectorSurface::SongBoundaryEvidence,
                signals: ['song_boundary_spoken_framing'],
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::ContentLost,
                unit: DetectorUnit::Section,
                summary: 'Speech precedes the singing inside the clip, so the cut opens on the item before the song rather than on the song.',
                owningClass: SongPublicationBoundaryEvidenceService::class,
            ),
            new DetectorEntry(
                id: 'song-boundary-spoken-framing-exceeds-limit',
                surface: DetectorSurface::SongBoundaryEvidence,
                signals: ['song_boundary_spoken_framing_exceeds_limit'],
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::PublishedWrongContent,
                unit: DetectorUnit::Section,
                summary: 'So much speech precedes the singing that the clip is more of the preceding item than of the song it is published as.',
                owningClass: SongPublicationBoundaryEvidenceService::class,
            ),
            new DetectorEntry(
                id: 'song-boundary-trailing-content',
                surface: DetectorSurface::SongBoundaryEvidence,
                signals: ['song_boundary_trailing_content'],
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::PublishedWrongContent,
                unit: DetectorUnit::Section,
                summary: 'Material continues past the end of the song, so the clip may carry the next item as part of this one.',
                owningClass: SongPublicationBoundaryEvidenceService::class,
            ),
            new DetectorEntry(
                id: 'song-boundary-evidence-unavailable',
                surface: DetectorSurface::SongBoundaryEvidence,
                signals: SongBoundaryEvidenceSignals::UNASSESSABLE_KINDS,
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::TechnicalQuality,
                unit: DetectorUnit::Section,
                summary: 'The transcript or RMS input the boundary pass needed was missing or unreadable, so this section was never assessed.',
                owningClass: SongPublicationBoundaryEvidenceService::class,
            ),
        ];
    }

    /**
     * Classes found in the extraction and repair path.
     *
     * All but one are fixes: the pipeline was made to refuse, park or report
     * rather than taught to notice afterwards, which is the right answer when
     * the defect is the pipeline's own behaviour rather than a property of a
     * recording.
         *
     * @return list<DetectorEntry>
     */
    private static function extractionClassEntries(): array
    {
        return [
            new DetectorEntry(
                id: 'extraction-recut-of-held-sermon',
                surface: null,
                signals: [],
                status: DetectorStatus::FixedAtSource,
                severity: DetectorSeverity::ContentLost,
                unit: DetectorUnit::Sermon,
                summary: 'A full re-run re-cut a sermon whose section was content-held, discarding the repair the hold was protecting, and reported success.',
                regressionCases: ['run 1314 §3992'],
                decision: 'Fixed at source 2026-09-17 (`283a6cd90`): extraction parks a run whose sermon section is held and no authority names it, so the re-cut cannot happen silently. 1314 was then repaired through `--held-section`.',
            ),
            new DetectorEntry(
                id: 'extraction-held-section-repair-unreachable',
                surface: null,
                signals: [],
                status: DetectorStatus::FixedAtSource,
                severity: DetectorSeverity::ContentLost,
                unit: DetectorUnit::Sermon,
                summary: 'A content-held sermon could not reach its own bounded repair, and the dry run concealed the refusal by reporting a fallback plan instead.',
                regressionCases: ['run 1209 §2572'],
                decision: 'Fixed at source 2026-09-17 (`6c7d33539`): `--held-section` separates bounded repair authority from release acceptance, and the dry run validates the plan that would actually execute. Content holds are not globally exempted from boundary checks.',
            ),
            new DetectorEntry(
                id: 'extraction-rms-fallback-with-disqualified-section',
                surface: null,
                signals: [],
                status: DetectorStatus::DecidedNotToDetect,
                severity: DetectorSeverity::ContentLost,
                unit: DetectorUnit::Sermon,
                summary: 'The sermon was cut from a dominant RMS block while a disqualified, unheld sermon section existed, so the cut and the section disagree.',
                regressionCases: ['run 1007', 'run 1041', 'run 1217'],
                decision: 'Ruled 2026-09-17: all three cuts are right and the sections are wrong, so there is no defect here to detect. Parking stays scoped to content holds; widening it to disqualified sections would park correct extractions.',
            ),
            new DetectorEntry(
                id: 'detection-unplaced-hold-refusal-discarded',
                surface: null,
                signals: [],
                status: DetectorStatus::Unbuilt,
                severity: DetectorSeverity::WrongMetadata,
                unit: DetectorUnit::Run,
                summary: 'The detection job retried a refusal it should not have retried, and the refused section list was not kept, so nobody can see what it declined to place.',
                regressionCases: ['run 1314 §3994'],
            ),
            new DetectorEntry(
                id: 'staging-held-candidates-not-promoted',
                surface: null,
                signals: [],
                status: DetectorStatus::Unbuilt,
                severity: DetectorSeverity::ContentLost,
                unit: DetectorUnit::Section,
                summary: 'Held section candidates exist only on the staging volume, so retiring staging would destroy the evidence a held section is waiting on.',
                regressionCases: ['run 1221 §2718', 'run 1221 §2719', 'run 1221 §2721'],
            ),
        ];
    }

    /**
     * Transcript classes with no detector of their own.
     *
     * Both are about text that reads as fluent speech, which is exactly what the
     * repetition screens cannot see. §4.3a's H10b comparison is the only
     * mechanised view either of them has.
         *
     * @return list<DetectorEntry>
     */
    private static function transcriptClassEntries(): array
    {
        return [
            new DetectorEntry(
                id: 'transcript-context-carried-drift',
                surface: null,
                signals: [],
                status: DetectorStatus::FixedAtSource,
                severity: DetectorSeverity::PublishedWrongContent,
                unit: DetectorUnit::Minute,
                summary: 'The decoder carried its own earlier output forward as context and locked into it, returning whole sermons as one-word segments or as repeated lines replacing real speech.',
                regressionCases: ['run 1314', 'run 1343', 'run 1258', 'run 980'],
                decision: 'Fixed at source 2026-09-17: `LocalWhisperDecoding` sends `max_context=0` with the initial prompt re-sent per window, and seven services lost both faults with timings unchanged. The fix prevents recurrence but repairs nothing already decoded; the historic residue is measured by H10b, not by this entry.',
            ),
            new DetectorEntry(
                id: 'transcript-meaning-changing-substitution',
                surface: null,
                signals: [],
                status: DetectorStatus::Unbuilt,
                severity: DetectorSeverity::PublishedWrongContent,
                unit: DetectorUnit::Minute,
                summary: 'A single word is replaced by another in otherwise fluent saved sermon text, changing the meaning while leaving nothing statistically odd to find.',
                regressionCases: ['run 946 §787', 'run 1030 §1418'],
            ),
        ];
    }

    /**
     * Structure classes with no detector of their own.
     *
     * @return list<DetectorEntry>
     */
    private static function structureClassEntries(): array
    {
        return [
            new DetectorEntry(
                id: 'structure-spoken-quotation-typed-as-song',
                surface: null,
                signals: [],
                status: DetectorStatus::Unbuilt,
                severity: DetectorSeverity::ContentLost,
                unit: DetectorUnit::Section,
                summary: 'A hymn quoted aloud inside the sermon was typed as singing, which cut the sermon off at the quotation.',
                regressionCases: ['run 1314 §3994', 'run 1314 §3992'],
            ),
            new DetectorEntry(
                id: 'talk-typed-other',
                surface: null,
                signals: [],
                status: DetectorStatus::Unbuilt,
                severity: DetectorSeverity::WrongMetadata,
                unit: DetectorUnit::Section,
                summary: "A children's talk was typed `other`, so it is invisible to every surface that looks for talks. Owned by the talks plan (PR5) and re-detected there, never retyped by hand.",
                regressionCases: ['run 1221 §2721', 'run 1358 §4684'],
            ),
            new DetectorEntry(
                id: 'sermon-ending-absorbed-by-song',
                surface: null,
                signals: [],
                status: DetectorStatus::DecidedNotToDetect,
                severity: DetectorSeverity::ContentLost,
                unit: DetectorUnit::Section,
                summary: "The song section following a sermon was suspected of having absorbed the sermon's closing words.",
                regressionCases: ['run 1336 §4263', 'run 1336 §4264'],
                decision: 'Ruled 2026-09-18 after source review: no preaching enters the song. What is truncated is the closing Amen, which is contained by the hold already on §4263 and does not need a detector of its own.',
            ),
            new DetectorEntry(
                id: 'structure-prompt-time-misconverted',
                surface: null,
                signals: [],
                status: DetectorStatus::FixedAtSource,
                severity: DetectorSeverity::ContentLost,
                unit: DetectorUnit::Section,
                summary: 'Boundary times written as `m:ss` in the prompt were read as seconds, placing a boundary one minute late.',
                regressionCases: ['run 1203 §2535', 'run 1183 §2391', 'run 1305 §3894'],
                decision: 'Fixed at source 2026-09-15: prompt times are emitted in seconds, so the conversion cannot happen. The wider class of semantic boundary offsets is a different question and is not closed by this.',
            ),
            new DetectorEntry(
                id: 'structure-hymn-inside-sermon-section',
                surface: null,
                signals: [],
                status: DetectorStatus::Unbuilt,
                severity: DetectorSeverity::ContentLost,
                unit: DetectorUnit::Section,
                summary: 'A hymn sits wholly inside the sermon section, which is a different shape from a non-sermon section absorbing one and is not caught by the macro-section rule.',
                regressionCases: ['#885'],
            ),
            new DetectorEntry(
                id: 'sermon-closing-prayer-dropped',
                surface: null,
                signals: [],
                status: DetectorStatus::FixedAtSource,
                severity: DetectorSeverity::ContentLost,
                unit: DetectorUnit::Sermon,
                summary: 'The closing prayer was left out of the sermon whenever it had no section of its own, so seven sermons end before the service does.',
                regressionCases: ['run 1027', 'run 981', 'run 1193', 'run 1299', 'run 1172', 'run 986', 'run 990'],
                decision: 'Fixed at source: the sermon span now extends to the next song when no section follows it. The seven recorded sermons await settled boundaries before their repairs run.',
            ),
        ];
    }

    /**
     * Song classes with no detector of their own.
     *
     * @return list<DetectorEntry>
     */
    private static function songClassEntries(): array
    {
        return [
            new DetectorEntry(
                id: 'song-performed-song-unlinked',
                surface: null,
                signals: [],
                status: DetectorStatus::FixedAtSource,
                severity: DetectorSeverity::WrongMetadata,
                unit: DetectorUnit::Section,
                summary: 'A performed song carried no linked song identity, so the clip was published with nothing naming what was sung.',
                regressionCases: ['run 1221 §2722'],
                decision: 'Fixed at source 2026-09-18 (`ecfa94d31`) for the deterministic class: identity flows through the order-of-service item and 55 of 66 bound automatically. The 11 that remain are adjudications of ambiguous bindings, not recurrences of this defect.',
            ),
            new DetectorEntry(
                id: 'song-title-hint-fuzzy-misbinding',
                surface: null,
                signals: [],
                status: DetectorStatus::FixedAtSource,
                severity: DetectorSeverity::PublishedWrongContent,
                unit: DetectorUnit::Section,
                summary: 'A fuzzy title hint bound a clip to the wrong catalogue song, so 25 clips were published as songs that were not sung.',
                regressionCases: ['§4156', '§2304', '§2718', '§2683'],
                decision: 'Fixed at source 2026-09-16: the hint resolves against the catalogue first and only falls back to lyrics when containment leaves no single answer. The three fallback rows are deliberately left for individual adjudication, because the resolver refusing to choose is the correct behaviour there.',
            ),
            new DetectorEntry(
                id: 'oos-item-written-from-wrong-song',
                surface: null,
                signals: [],
                status: DetectorStatus::Unbuilt,
                severity: DetectorSeverity::WrongMetadata,
                unit: DetectorUnit::Section,
                summary: 'Livestream-sourced order-of-service items were written from a song binding that has since been corrected, so the item and the section now disagree.',
            ),
            new DetectorEntry(
                id: 'song-clip-audio-upsampled',
                surface: null,
                signals: [],
                status: DetectorStatus::FixedAtSource,
                severity: DetectorSeverity::TechnicalQuality,
                unit: DetectorUnit::Section,
                summary: 'Song clip audio was upsampled to 96 kHz and re-encoded at 128 kbps, degrading 245 clips for no gain.',
                regressionCases: ['245 of 464 clips'],
                decision: 'Fixed at source: the source sample rate and bitrate are preserved through `enhanceVideo`. The stored clips are not repaired by the fix, and their post-publication comparison and regeneration remain owed.',
            ),
            new DetectorEntry(
                id: 'song-section-without-a-song',
                surface: null,
                signals: [],
                status: DetectorStatus::Unbuilt,
                severity: DetectorSeverity::WrongMetadata,
                unit: DetectorUnit::Section,
                summary: 'A song section above the 15-second micro floor contains no song at all, so the micro-section rule cannot reach it.',
                regressionCases: ['§622', '§678'],
            ),
        ];
    }

    /**
     * Scripture reference classes.
     *
     * @return list<DetectorEntry>
     */
    private static function scriptureClassEntries(): array
    {
        return [
            new DetectorEntry(
                id: 'published-title-contradicts-content',
                surface: null,
                signals: [],
                status: DetectorStatus::Unbuilt,
                severity: DetectorSeverity::PublishedWrongContent,
                unit: DetectorUnit::Sermon,
                summary: "The published title or reference contradicts the sermon's own summary or transcript, so the page describes a sermon other than the one it carries.",
                regressionCases: ['run 881', 'run 954', 'run 844', 'run 845', 'run 850', 'run 899'],
            ),
            new DetectorEntry(
                id: 'scripture-multi-passage-truncated',
                surface: null,
                signals: [],
                status: DetectorStatus::Unbuilt,
                severity: DetectorSeverity::WrongMetadata,
                unit: DetectorUnit::Sermon,
                summary: 'A multi-passage reference is cut to its first passage when linked, so the page offers a narrower reading than the sermon preached.',
                regressionCases: ['run 1031', 'run 1159', 'run 1188', 'run 1233'],
            ),
            new DetectorEntry(
                id: 'scripture-whole-book-reference-rejected',
                surface: null,
                signals: [],
                status: DetectorStatus::FixedAtSource,
                severity: DetectorSeverity::WrongMetadata,
                unit: DetectorUnit::Sermon,
                summary: 'A whole single-chapter letter named without a chapter was rejected as a reference, so the sermon showed no passage at all.',
                regressionCases: ['run 957', 'run 1090'],
                decision: 'Fixed at source: whole-book validation accepts a single-chapter letter named without a chapter. 957 and 1090 still await reanalysis through the pipeline.',
            ),
            new DetectorEntry(
                id: 'scripture-reference-never-linked',
                surface: null,
                signals: [],
                status: DetectorStatus::Unbuilt,
                severity: DetectorSeverity::WrongMetadata,
                unit: DetectorUnit::Sermon,
                summary: 'A sermon names a passage that was never linked to a reference, so seven sermons carry a reading the site cannot resolve.',
                regressionCases: ['run 908', 'run 909', 'run 910', 'run 912', 'run 913', 'run 914', 'run 915'],
            ),
            new DetectorEntry(
                id: 'scripture-preached-reading-dropped-by-order-flag',
                surface: null,
                signals: [],
                status: DetectorStatus::FixedAtSource,
                severity: DetectorSeverity::ContentLost,
                unit: DetectorUnit::Sermon,
                summary: 'An order-only flag dropped the preached reading from the sermon media, so the reading the sermon expounds is missing from what a listener hears.',
                regressionCases: ['run 1075', 'run 1254', 'run 1286', 'run 1299'],
                decision: 'Fixed at source: a reading that matches the sermon is retained despite an order-only flag. Four replans remain owed on the recorded runs.',
            ),
            new DetectorEntry(
                id: 'scripture-verse-in-prayer-typed-as-reading',
                surface: null,
                signals: [],
                status: DetectorStatus::Unbuilt,
                severity: DetectorSeverity::WrongMetadata,
                unit: DetectorUnit::Section,
                summary: 'A verse quoted inside a prayer was typed as a Bible reading, so a prayer is published as Scripture.',
                regressionCases: ['run 1043 §1528'],
            ),
            new DetectorEntry(
                id: 'sermon-page-names-wrong-reading',
                surface: null,
                signals: [],
                status: DetectorStatus::FixedAtSource,
                severity: DetectorSeverity::WrongMetadata,
                unit: DetectorUnit::Sermon,
                summary: "The sermon page named the service's first reading rather than the sermon's own, on 157 of 438 sermons.",
                regressionCases: ['157 of 438 sermons'],
                decision: 'Fixed at source: reading selection follows the extraction span and the matching reading rather than service order. No media re-run is needed, because only the page selection was wrong.',
            ),
        ];
    }

    /**
     * Media and recording quality classes with no detector of their own.
     *
     * @return list<DetectorEntry>
     */
    private static function mediaQualityClassEntries(): array
    {
        return [
            new DetectorEntry(
                id: 'audio-dropout-inside-talk',
                surface: null,
                signals: [],
                status: DetectorStatus::Prototype,
                severity: DetectorSeverity::ContentLost,
                unit: DetectorUnit::Section,
                summary: 'A stretch of at least 15 seconds at or below -80 dB inside a talk, where the source itself carried no audio. Contained rather than reconstructed.',
            ),
            new DetectorEntry(
                id: 'video-stream-copy-keyframe-lead-in',
                surface: null,
                signals: [],
                status: DetectorStatus::FixedAtSource,
                severity: DetectorSeverity::TechnicalQuality,
                unit: DetectorUnit::Section,
                summary: 'A stream copy began at the previous keyframe, so the picture starts after its audio or the audio carries the end of the preceding item.',
                regressionCases: ['12 sermons over 3 s', '89 frozen song openings', '23 song clips with lead-in audio'],
                decision: 'Fixed at source 2026-09-15: the smart cut seeks on input rather than output and counts frames, and `fda414eb2` corrects the source frame. Canary and corpus re-runs remain owed.',
            ),
            new DetectorEntry(
                id: 'video-discredited-verdict-unreassessable',
                surface: null,
                signals: [],
                status: DetectorStatus::Unbuilt,
                severity: DetectorSeverity::ContentLost,
                unit: DetectorUnit::Sermon,
                summary: 'A video quality verdict that source review has discredited survives because the evidence it rested on can no longer be read where the output lives.',
                regressionCases: ['run 862'],
            ),
            new DetectorEntry(
                id: 'video-verdict-without-run-evidence',
                surface: null,
                signals: [],
                status: DetectorStatus::FixedAtSource,
                severity: DetectorSeverity::WrongMetadata,
                unit: DetectorUnit::Sermon,
                summary: 'Thirteen quality verdicts were written with no evidence from the run they judged, so the verdict could not be checked against anything.',
                regressionCases: ['13 verdicts'],
                decision: "Fixed at source and verified: verdicts carry their owning run's evidence, and the thirteen were reconciled. Closed.",
            ),
        ];
    }

    /**
     * Membership classes: which recordings are services at all.
     *
     * @return list<DetectorEntry>
     */
    private static function membershipClassEntries(): array
    {
        return [
            new DetectorEntry(
                id: 'identity-duplicate-date-pair',
                surface: null,
                signals: [],
                status: DetectorStatus::Unbuilt,
                severity: DetectorSeverity::PublishedWrongContent,
                unit: DetectorUnit::Run,
                summary: 'Two runs claim the same occasion and neither has been ruled the master, so five sermons pass the release gate while their identity is still disputed. Contained by §4.4, not by a detector.',
            ),
            new DetectorEntry(
                id: 'membership-rehearsal-imported-as-service',
                surface: null,
                signals: [],
                status: DetectorStatus::Unbuilt,
                severity: DetectorSeverity::PublishedWrongContent,
                unit: DetectorUnit::Run,
                summary: "A Saturday rehearsal take of Sunday's sermon was imported as a service of its own, duplicating the sermon under the wrong date.",
                regressionCases: ['run 1043', 'run 1089'],
            ),
            new DetectorEntry(
                id: 'membership-missing-occasion',
                surface: null,
                signals: [],
                status: DetectorStatus::Unbuilt,
                severity: DetectorSeverity::WrongMetadata,
                unit: DetectorUnit::Run,
                summary: 'A non-Sunday occasion carries no `occasion`, so a funeral or holiday club is indistinguishable from a Sunday service.',
                regressionCases: ['run 1051', 'run 1098', 'run 1144'],
            ),
            new DetectorEntry(
                id: 'membership-silent-source',
                surface: null,
                signals: [],
                status: DetectorStatus::FixedAtSource,
                severity: DetectorSeverity::TechnicalQuality,
                unit: DetectorUnit::Run,
                summary: 'A source with no audio at all produced a run that could never yield anything.',
                regressionCases: ['run 955'],
                decision: 'Fixed at source and applied: silent sources are excluded from historic membership, so the run is not attempted rather than failing late.',
            ),
        ];
    }

    /**
     * Release and consumer-surface classes.
     *
     * @return list<DetectorEntry>
     */
    private static function releaseClassEntries(): array
    {
        return [
            new DetectorEntry(
                id: 'release-sermon-query-ignores-asset-disk',
                surface: null,
                signals: [],
                status: DetectorStatus::FixedAtSource,
                severity: DetectorSeverity::ContentLost,
                unit: DetectorUnit::Sermon,
                summary: "The public sermon query resolved media through config rather than the row's own `asset_disk`, so listings, browse, service lists and the podcast feed could point at a disk the file is not on.",
                decision: 'Fixed at source: the query selects `asset_disk` and refuses when the column is missing, so a row that omits it fails loudly instead of resolving to the wrong disk. No media re-run is needed.',
            ),
            new DetectorEntry(
                id: 'release-media-file-missing',
                surface: null,
                signals: [],
                status: DetectorStatus::Unbuilt,
                severity: DetectorSeverity::ContentLost,
                unit: DetectorUnit::Sermon,
                summary: 'A quarantined sermon records a media file that does not exist, so the release gate is reasoning about bytes that are gone.',
                regressionCases: ['sermon 857'],
            ),
            new DetectorEntry(
                id: 'release-url-while-archive-disabled',
                surface: null,
                signals: [],
                status: DetectorStatus::FixedAtSource,
                severity: DetectorSeverity::WrongMetadata,
                unit: DetectorUnit::Run,
                summary: 'A service URL was offered while the service archive was disabled, so a link existed to a page that should not have been reachable.',
                decision: 'Fixed at source: no URL is offered while `public_from` is null. No media re-run is needed.',
            ),
        ];
    }

    /**
     * @return list<DetectorEntry>
     */
    private static function videoEntries(): array
    {
        return [
            new DetectorEntry(
                id: 'video-dead-picture',
                surface: DetectorSurface::VideoQualityVerdict,
                signals: ['frozen_frames', 'mostly_black', 'partially_frozen', 'partially_black'],
                status: DetectorStatus::Promoted,
                severity: DetectorSeverity::ContentLost,
                unit: DetectorUnit::Sermon,
                summary: 'Frozen or black picture measured over six windows; coverage decides whether the recording is rejected or held for review.',
                owningClass: SermonVideoQualityAssessmentService::class,
                regressionCases: ['run 926', 'run 930', 'run 941', 'run 975', 'run 1276', 'run 1189', 'run 1230'],
            ),
        ];
    }
}
