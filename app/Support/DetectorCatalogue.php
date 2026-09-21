<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\DetectorEntry;
use App\Data\SuspectTranscriptBlock;
use App\Enums\DetectorSeverity;
use App\Enums\DetectorStatus;
use App\Enums\DetectorSurface;
use App\Enums\DetectorUnit;
use App\Services\ChurchService\SectionPublication\SongPublicationReviewPolicy;
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
 * paired test instead.
 *
 * **Scope today.** Only promoted detectors are listed. The unbuilt and
 * decided-not-to-detect rows join when the plan's table gains its `detector_id`
 * column; until then this catalogue answers "what can be evaluated", not "what
 * classes exist".
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

            foreach ($entry->signals as $signal) {
                $key = $entry->surface->value.'::'.$signal;

                if (isset($claimed[$key])) {
                    throw new RuntimeException(
                        "Signal [{$signal}] on surface [{$entry->surface->value}] is claimed by both "
                        ."[{$claimed[$key]}] and [{$entry->id}]."
                    );
                }

                $claimed[$key] = $entry->id;
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
            ...self::songEntries(),
            ...self::videoEntries(),
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
