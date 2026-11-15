<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Data\TalkTypeInput;

/**
 * Closed positive forms produce rule evidence; unrecognised language remains for semantic assessment.
 * Quotes are verbatim. No IDs, baseline proposals, speaker identities or audit provenance are read.
 *
 * @phpstan-type Citation array{source: string, quote: string}
 * @phpstan-type SeriesFinding array{source: string, quote: string, series: string}
 * @phpstan-type Facts array{
 *   series: list<SeriesFinding>, series_mentions: list<Citation>, testimony: list<Citation>,
 *   family_slot: list<Citation>, children: list<Citation>, teaching: list<Citation>, application: list<Citation>,
 *   ambiguous_declarations: list<Citation>,
 *   non_address: list<Citation>, main_address: list<Citation>,
 *   complete: bool, limitations: list<string>, conflicts: list<string>,
 *   service: ?string, testimony_whole: bool, non_address_whole: bool,
 *   role_features: array{full_length_calibration_seconds: float, full_length_elsewhere: bool,
 *     separate_main_address: bool, current_duration: ?float, longest_other_sermon: ?float}
 * }
 */
final class TalkTypeEvidenceExtractor
{
    /** Study calibration, never a cut limit or a substitute for the section's role. */
    public const FULL_LENGTH_CALIBRATION_SECONDS = 962.665;

    private const SERIES = '(Catechism|Hero(?:es)?[ -]of[ -]Faith|Bible[ -]Characters?)';

    /** @return Facts */
    public function extract(TalkTypeInput $input): array
    {
        $evidence = $input->evidence;
        $text = self::text($evidence['text'] ?? null);
        $sentences = preg_split('/(?<=[.!?])\s+/u', $text, flags: PREG_SPLIT_NO_EMPTY) ?: [];
        $item = is_array($evidence['item'] ?? null) ? $evidence['item'] : [];
        $outline = is_array($evidence['outline'] ?? null) ? array_values($evidence['outline']) : [];
        $index = $evidence['section_index'] ?? null;
        $current = is_int($index) && is_array($outline[$index] ?? null) ? $outline[$index] : [];
        $transcript = is_array($evidence['transcript'] ?? null) ? $evidence['transcript'] : [];
        $series = $seriesMentions = $testimony = $familySlot = $children = $teaching = $application = $ambiguousDeclarations = $mainAddress = $nonAddress = [];
        $limitations = $conflicts = [];
        $trustedItem = ($item['deleted'] ?? true) === false && ($item['detection_only'] ?? true) === false
            && (($item['has_normalized_evidence'] ?? false) === true || in_array($item['source'] ?? null, ['email', 'openlp', 'manual'], true));
        $sourceTitle = self::text($item['source_title'] ?? null);
        $titleSeries = $this->titleSeries(self::text($item['title'] ?? null));
        $sourceSeries = $this->titleSeries($sourceTitle);
        $itemConflict = $sourceTitle !== '' && $titleSeries !== null && $sourceSeries !== $titleSeries;
        if ($itemConflict) {
            $conflicts[] = 'bound_title_source_disagree';
        }
        foreach (['title', 'source_title', 'series'] as $field) {
            $value = self::text($item[$field] ?? null);
            $found = $this->titleSeries($value);
            if ($found !== null && $trustedItem && ! $itemConflict && ! in_array($item['type'] ?? null, ['notice', 'notices', 'song'], true)) {
                $series[] = ['source' => 'item.'.$field, 'quote' => $value, 'series' => $found];
            }
            if ($this->mentionsSeries($value)) {
                $seriesMentions[] = ['source' => 'item.'.$field, 'quote' => $value];
            }
        }
        $slotTitle = self::text($item['title'] ?? null);
        if ($trustedItem && preg_match('/^(?:Family|Children[’\x27]s) (?:Talk|Slot)(?:\s*[:–-]\s*[^.!?]+)?$/iu', $slotTitle) === 1
            && ($sourceTitle === '' || preg_match('/^(?:Family|Children[’\x27]s) (?:Talk|Slot)(?:\s*[:–-]\s*[^.!?]+)?$/iu', $sourceTitle) === 1)) {
            $familySlot[] = ['source' => 'item.title', 'quote' => $slotTitle];
        }
        foreach (['title', 'series'] as $field) {
            $value = self::text($evidence[$field] ?? null);
            if ($this->mentionsSeries($value)) {
                $seriesMentions[] = ['source' => $field, 'quote' => $value];
            }
        }
        $quotedSpeech = preg_match('/["“”]/u', $text) === 1;
        $testimonyFrame = $conversion = $faithPresent = false;
        $testimonyWhole = $sentences !== [] && ! $quotedSpeech;
        $nonAddressWhole = $sentences !== [] && ! $quotedSpeech;
        foreach ($sentences as $sentenceIndex => $sentence) {
            $quote = ['source' => 'text.sentence.'.$sentenceIndex, 'quote' => $sentence];
            $clause = rtrim(trim($sentence), '.!?');
            if ($this->mentionsSeries($sentence)) {
                $seriesMentions[] = $quote;
                if (! $quotedSpeech && preg_match('/^(?:Today we (?:continue|begin)|This (?:morning|evening) we (?:continue|begin)) our '.self::SERIES.' series$/iu', $clause, $match) === 1) {
                    $series[] = $quote + ['series' => $this->canonicalSeries($match[1])];
                } elseif (preg_match('/^(?:Today|This (?:is|was|morning|evening|section|talk)|We (?:are|will|continue|begin))\b/iu', $clause) === 1) {
                    $ambiguousDeclarations[] = $quote;
                }
            }
            if (! $quotedSpeech && preg_match('/^This is (?:our|the) family (?:talk|slot)$/iu', $clause) === 1) {
                $familySlot[] = $quote;
            } elseif (preg_match('/\bfamily (?:talk|slot)\b/iu', $sentence) === 1) {
                $ambiguousDeclarations[] = $quote;
            }
            if (! $quotedSpeech && $sentenceIndex === 0 && preg_match('/^(?:Boys and girls|Children), (?:listen carefully|come and sit here|let us learn together)$/iu', $clause) === 1) {
                $children[] = $quote;
            }
            if (! $quotedSpeech && preg_match('/^(?:Jesus teaches us to|This passage teaches us to) [\p{L} ,’\x27-]+$/iu', $clause) === 1) {
                $teaching[] = $quote;
            }
            if (! $quotedSpeech && preg_match('/^(?:So we (?:should|must)|Therefore let us) [\p{L} ,’\x27-]+$/iu', $clause) === 1) {
                $application[] = $quote;
            }
            if (! $quotedSpeech && preg_match('/^This is (?:our|the) main address(?: for (?:this|the) service)?$/iu', $clause) === 1) {
                $mainAddress[] = $quote;
            }
            $frame = preg_match('/^(?:This is my testimony|Let me tell you how I (?:became a Christian|came to faith))$/iu', $clause) === 1;
            $converted = preg_match('/^I (?:came to (?:trust|follow)|put my (?:faith|trust) in) (?:Jesus|Christ)$/iu', $clause) === 1;
            $present = preg_match('/^Now I (?:follow|trust|serve) (?:Jesus|Christ)$/iu', $clause) === 1;
            $past = preg_match('/^I (?:once (?:rejected|ignored) (?:Jesus|Christ)|grew up without (?:faith|hope))$/iu', $clause) === 1;
            $testimonyFrame = $testimonyFrame || ($sentenceIndex === 0 && $frame);
            $conversion = $conversion || $converted;
            $faithPresent = $faithPresent || $present;
            $testimonyWhole = $testimonyWhole && ($frame || $converted || $present || $past);
            if ($frame || $converted || $present || preg_match('/\b(?:testimony|my faith|became a Christian|my conversion)\b/iu', $sentence) === 1) {
                $testimony[] = $quote;
            }
            $function = preg_match('/^(?:This is (?:a notice|only an introduction to (?:the next song|the reading))|Children, (?:you may|please) go to your groups)$/iu', $clause) === 1;
            $logistics = preg_match('/^(?:The (?:lunch|meeting|rehearsal) is at (?:noon|[0-9: ]+(?:am|pm)) in the (?:hall|church)|Please sign up at the desk|Thank you)$/iu', $clause) === 1;
            $nonAddressWhole = $nonAddressWhole && ($function || $logistics);
            if ($function || $logistics) {
                $nonAddress[] = $quote;
            }
        }
        foreach (['title', 'source_title'] as $field) {
            $value = self::text($item[$field] ?? null);
            if (preg_match('/\btestimony\b/iu', $value) === 1) {
                $testimony[] = ['source' => 'item.'.$field, 'quote' => $value];
            }
        }
        $title = self::text($evidence['title'] ?? null);
        if (preg_match('/\btestimony\b/iu', $title) === 1) {
            $testimony[] = ['source' => 'title', 'quote' => $title];
        }
        $role = self::text($current['role'] ?? null);
        if ($role === 'main_address') {
            $mainAddress[] = ['source' => 'outline.current.role', 'quote' => $role];
        }
        if ($role === 'family_slot') {
            $familySlot[] = ['source' => 'outline.current.role', 'quote' => $role];
        }
        $longest = null;
        $separateMain = false;
        foreach ($outline as $otherIndex => $entry) {
            if (! is_array($entry)) {
                $limitations[] = 'invalid_outline';

                continue;
            }
            $entryStart = self::number($entry['start'] ?? null);
            $entryEnd = self::number($entry['end'] ?? null);
            if ($entryStart === null || $entryEnd === null || $entryStart < 0 || $entryEnd <= $entryStart) {
                $limitations[] = 'invalid_outline';
            }
            if ($otherIndex === $index) {
                continue;
            }
            if (($entry['section_type'] ?? null) === 'sermon' || ($entry['role'] ?? null) === 'main_address') {
                $separateMain = true;
                $duration = self::number($entry['duration'] ?? null);
                if ($duration !== null) {
                    $longest = max($longest ?? 0, $duration);
                }
            }
        }
        $start = self::number($current['start'] ?? null);
        $end = self::number($current['end'] ?? null);
        if ($start === null || $end === null || $start < 0 || $end <= $start || ($current['section_type'] ?? null) !== 'short_talk') {
            $limitations[] = 'missing_or_invalid_current_outline';
        }
        $windows = $transcript['unobservable_windows'] ?? null;
        if (! is_array($windows)) {
            $limitations[] = 'unknown_observability';
        } else {
            foreach ($windows as $window) {
                if (! is_array($window) || self::number($window['start'] ?? null) === null || self::number($window['end'] ?? null) === null) {
                    $limitations[] = 'unknown_observability';
                } elseif ($start === null || $end === null || ($window['start'] < $end && $window['end'] > $start)) {
                    $limitations[] = 'unobservable_speech';
                }
            }
        }
        if ($text === '' || ($transcript['status'] ?? null) !== 'available' || ($transcript['excerpt_only'] ?? true) !== false) {
            $limitations[] = 'full_text_unavailable';
        }
        if (($transcript['boundary_overlap'] ?? true) !== false) {
            $limitations[] = 'overlapping_boundary_cues';
        }
        if (($evidence['identity_conflict'] ?? false) === true) {
            $conflicts[] = 'conflicting_service_identity';
        }
        if ($ambiguousDeclarations !== [] && ($series !== [] || $familySlot !== [])) {
            $conflicts[] = 'ambiguous_current_declaration';
        }
        if (count(array_unique(array_column($series, 'series'))) > 1) {
            $conflicts[] = 'conflicting_current_series';
        }
        if ($mainAddress !== [] && ($separateMain || $series !== [] || $familySlot !== [])) {
            $conflicts[] = 'conflicting_address_roles';
        }

        return ['series' => $series, 'series_mentions' => $seriesMentions, 'testimony' => $testimony,
            'family_slot' => $familySlot, 'children' => $children, 'teaching' => $teaching, 'application' => $application,
            'ambiguous_declarations' => $ambiguousDeclarations,
            'non_address' => $nonAddress, 'main_address' => $mainAddress,
            'complete' => $limitations === [], 'limitations' => array_values(array_unique($limitations)), 'conflicts' => $conflicts,
            'service' => is_string($evidence['service'] ?? null) ? $evidence['service'] : null,
            'testimony_whole' => $testimonyWhole && $testimonyFrame && $conversion && $faithPresent,
            'non_address_whole' => $nonAddressWhole && $nonAddress !== [] && preg_match('/^(?:This is|Children,)/iu', $text) === 1,
            'role_features' => ['full_length_calibration_seconds' => self::FULL_LENGTH_CALIBRATION_SECONDS,
                'full_length_elsewhere' => $longest !== null && $longest >= self::FULL_LENGTH_CALIBRATION_SECONDS,
                'separate_main_address' => $separateMain, 'current_duration' => self::number($current['duration'] ?? null), 'longest_other_sermon' => $longest]];
    }

    private function titleSeries(string $title): ?string
    {
        if (preg_match('/^'.self::SERIES.'(?: (?:talk|lesson))?(?:\s*[:–-]\s*[^.!?]+)?$/iu', $title, $match) !== 1) {
            return null;
        }

        return $this->canonicalSeries($match[1]);
    }

    private function canonicalSeries(string $value): string
    {
        return match (true) {
            preg_match('/^catechism$/iu', $value) === 1 => 'Catechism',
            preg_match('/^hero/iu', $value) === 1 => 'Heroes of Faith',
            default => 'Bible Characters',
        };
    }

    private function mentionsSeries(string $value): bool
    {
        return preg_match('/\b'.self::SERIES.'\b/iu', $value) === 1;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private static function number(mixed $value): ?float
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) ? (float) $value : null;
    }
}
