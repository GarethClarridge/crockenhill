<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Contracts\ServiceStructureInterface;
use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;

/**
 * Deterministic structure detection for tests and CI: either a fixture set by
 * the test, or a keyword-derived structure from the transcript cues so any
 * fixture transcript yields a plausible, repeatable result.
 */
class MockServiceStructureService implements ServiceStructureInterface
{
    private static ?ServiceStructure $fixtureStructure = null;

    /** @var list<ServiceStructure|\Throwable> */
    private static array $fixtureSequence = [];

    /**
     * Keyword markers checked in order; the first match types the cue.
     *
     * @var array<string, list<string>>
     */
    private const CUE_MARKERS = [
        'childrens_talk' => ['boys and girls', 'children', 'off you go to your groups'],
        'welcome' => ['welcome'],
        'notices' => ['notices', 'announcements'],
        'bible_reading' => ['our reading', 'reading this morning', 'reading from', 'the lord said'],
        'sermon' => ['turn with me', 'our passage', 'first point', 'if you have your bibles'],
        'prayer' => ['let us pray', "let's pray", 'heavenly father'],
        'song' => ['hymn', 'sing', 'praise my soul'],
    ];

    /** @var list<string> */
    private static array $lastFeedback = [];

    /**
     * Set the structure the next detection call should return.
     *
     * Pass null to restore keyword derivation. Tests that set a fixture must
     * reset it (tearDown) because the state is static.
     */
    public static function useStructure(?ServiceStructure $structure): void
    {
        self::$fixtureStructure = $structure;
        self::$fixtureSequence = [];
        self::$lastFeedback = [];
    }

    /**
     * The feedback notes the most recent detection call received — lets tests
     * assert a retry actually carried its correction to the detector.
     *
     * @return list<string>
     */
    public static function lastFeedback(): array
    {
        return self::$lastFeedback;
    }

    /**
     * Queue structures for successive detection calls (retry tests): each call
     * consumes the next; the final one repeats once the queue is exhausted.
     * Reset with useStructure(null) in tearDown, as for a single fixture.
     */
    public static function useStructureSequence(ServiceStructure ...$structures): void
    {
        self::$fixtureStructure = null;
        self::$fixtureSequence = array_values($structures);
    }

    /**
     * Return the given structure on the first detection call, then throw on the next —
     * models a transient detector error (OpenAI timeout, malformed JSON) during a retry.
     * Reset with useStructure(null) in tearDown, as for a single fixture.
     */
    public static function useStructureThenThrow(ServiceStructure $structure, \Throwable $error): void
    {
        self::$fixtureStructure = null;
        self::$fixtureSequence = [$structure, $error];
        self::$lastFeedback = [];
    }

    public function detect(
        ChurchServiceTranscript $transcript,
        array $oosItems,
        ?string $processingId = null,
        array $feedback = [],
    ): ServiceStructure {
        self::$lastFeedback = $feedback;

        if (self::$fixtureSequence !== []) {
            $next = count(self::$fixtureSequence) > 1
                ? array_shift(self::$fixtureSequence)
                : self::$fixtureSequence[0];

            if ($next instanceof \Throwable) {
                throw $next;
            }

            return $next;
        }

        if (self::$fixtureStructure instanceof ServiceStructure) {
            return self::$fixtureStructure;
        }

        return $this->deriveFromTranscript($transcript, $oosItems);
    }

    /**
     * @param  array<int, array{id: int, position: int, type: string, title: ?string, song_id: ?int}>  $oosItems
     */
    private function deriveFromTranscript(ChurchServiceTranscript $transcript, array $oosItems): ServiceStructure
    {
        $sections = [];
        $currentType = null;
        $currentStart = 0.0;
        $currentEnd = 0.0;

        foreach ($transcript->cues as $cue) {
            $cueType = $this->typeForCue($cue['text']) ?? $currentType ?? ServiceSectionType::Other;

            if ($cueType !== $currentType) {
                if ($currentType !== null) {
                    $sections[] = [$currentType, $currentStart, $currentEnd];
                }

                $currentType = $cueType;
                $currentStart = $cue['start'];
            }

            $currentEnd = $cue['end'];
        }

        if ($currentType !== null) {
            $sections[] = [$currentType, $currentStart, $currentEnd];
        }

        $availableItems = $oosItems;

        return ServiceStructure::fromSections(
            array_map(
                function (array $section) use (&$availableItems, $transcript): ServiceStructureSection {
                    [$type, $start, $end] = $section;

                    return new ServiceStructureSection(
                        type: $type,
                        title: $type->label(),
                        startTime: $start,
                        endTime: $end,
                        confidence: 0.9,
                        oosItemId: $this->claimOosItem($availableItems, $type),
                        songTitle: $type === ServiceSectionType::Song
                            ? trim(explode('.', $transcript->sliceText($start, $end))[0])
                            : null,
                        readingReference: null,
                        notes: ['Mock structure derived from transcript markers.'],
                    );
                },
                $sections
            ),
            ['Mock service structure detection.'],
            'mock',
        );
    }

    private function typeForCue(string $text): ?ServiceSectionType
    {
        $lower = strtolower($text);

        foreach (self::CUE_MARKERS as $type => $markers) {
            foreach ($markers as $marker) {
                if (str_contains($lower, $marker)) {
                    return ServiceSectionType::from($type);
                }
            }
        }

        return null;
    }

    /**
     * Claim the first unused OoS item whose semantic type matches, mirroring
     * the "each id at most once" rule the real detector is prompted with.
     *
     * @param  array<int, array{id: int, position: int, type: string, title: ?string, song_id: ?int}>  $availableItems
     */
    private function claimOosItem(array &$availableItems, ServiceSectionType $type): ?int
    {
        foreach ($availableItems as $index => $item) {
            if ($item['type'] === $type->value) {
                unset($availableItems[$index]);

                return $item['id'];
            }
        }

        return null;
    }
}
