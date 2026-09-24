<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

/**
 * What changed between two captures of the same run, and which changes need a person.
 *
 * Sections are paired by how much their spans overlap, never by `section_order` or id. Sync
 * matches rows by order and recreates them, so an insert renumbers every later section; pairing
 * by position would compare each shifted section with its neighbour and report a false change on
 * every one (the sound-stage recompute nearly wrote 19 of 31 findings to the wrong section that
 * way, 2026-09-21).
 *
 * Holds are compared across the whole run, keyed by what they claim, because a hold follows its
 * content through a re-run and may land on a different section than the one it started on. A
 * live hold that is absent afterwards, or a section that leaves review or loses its media, is
 * listed under `attention`: those are the changes that silently lose containment or custody.
 */
final class HistoricRerunDiff
{
    /** Seconds a boundary may move before the section counts as re-spanned; below this is snapping noise. */
    private const SPAN_TOLERANCE_SECONDS = 0.5;

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>|null  $after  null when the run could not be captured afterwards
     * @return array{changes: list<array<string, mixed>>, attention: list<string>}
     */
    public function compare(array $before, ?array $after): array
    {
        if ($after === null) {
            return [
                'changes' => [['kind' => 'run_missing']],
                'attention' => ['run could not be captured after the re-run'],
            ];
        }

        $changes = [];
        $attention = [];

        foreach (['status', 'current_step', 'superseded', 'transcript_sha256', 'sermon_absence', 'extraction_plan'] as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $changes[] = ['kind' => 'run_'.$field.'_changed', 'before' => $before[$field] ?? null, 'after' => $after[$field] ?? null];
            }
        }

        // A run that was already unfinished and was not reached is no change; one the re-run
        // leaves unfinished is (run 935, failed and superseded since 08-27, is the first case).
        if (($after['status'] ?? null) !== 'completed' && ($after['status'] ?? null) !== ($before['status'] ?? null)) {
            $attention[] = sprintf('run is %s after the re-run', (string) ($after['status'] ?? 'unknown'));
        }

        if (! $this->spansEqual($before['sermon_span'] ?? null, $after['sermon_span'] ?? null)) {
            $changes[] = ['kind' => 'sermon_span_moved', 'before' => $before['sermon_span'] ?? null, 'after' => $after['sermon_span'] ?? null];
        }

        $changes = [...$changes, ...$this->sermonChanges($before['sermon'] ?? null, $after['sermon'] ?? null)];

        [$sectionChanges, $sectionAttention] = $this->sectionChanges(
            $this->sections($before),
            $this->sections($after),
        );
        [$holdChanges, $holdAttention] = $this->holdChanges($this->sections($before), $this->sections($after));

        return [
            'changes' => [...$changes, ...$sectionChanges, ...$holdChanges],
            'attention' => [...$attention, ...$sectionAttention, ...$holdAttention],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @return list<array<string, mixed>>
     */
    private function sermonChanges(?array $before, ?array $after): array
    {
        if ($before === null || $after === null) {
            return $before === $after ? [] : [['kind' => 'sermon_link_changed', 'before' => $before['id'] ?? null, 'after' => $after['id'] ?? null]];
        }

        $changes = [];

        foreach ($before as $field => $value) {
            $changed = $field === 'segment'
                ? ! $this->spansEqual($value, $after[$field] ?? null)
                : $value !== ($after[$field] ?? null);

            if ($changed) {
                $changes[] = ['kind' => 'sermon_'.$field.'_changed', 'sermon_id' => $before['id'], 'before' => $value, 'after' => $after[$field] ?? null];
            }
        }

        return $changes;
    }

    /**
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function sectionChanges(array $before, array $after): array
    {
        $changes = [];
        $attention = [];
        [$pairs, $removed, $added] = $this->pair($before, $after);

        foreach ($removed as $section) {
            $changes[] = ['kind' => 'section_removed', 'section' => $this->label($section)];

            if (($section['media']['signature'] ?? null) !== null) {
                $attention[] = sprintf('%s had extracted media and is gone', $this->label($section));
            }
        }

        foreach ($added as $section) {
            $changes[] = ['kind' => 'section_added', 'section' => $this->label($section)];
        }

        foreach ($pairs as [$old, $new]) {
            $label = $this->label($old).' → '.$this->label($new);

            foreach (['type' => 'section_retyped', 'title' => 'section_retitled', 'item_id' => 'section_item_rebound', 'song_id' => 'section_song_rebound', 'song_match_type' => 'section_song_match_changed', 'lyric_identity' => 'section_lyric_identity_changed', 'proposed_talk_type' => 'section_talk_type_proposed', 'confirmed_talk_type' => 'section_talk_type_confirmation_changed', 'needs_manual_review' => 'section_review_changed', 'publication_status' => 'section_publication_changed'] as $field => $kind) {
                if (($old[$field] ?? null) !== ($new[$field] ?? null)) {
                    $changes[] = ['kind' => $kind, 'section' => $label, 'before' => $old[$field] ?? null, 'after' => $new[$field] ?? null];
                }
            }

            if (! $this->spansEqual(['start' => $old['start'], 'end' => $old['end']], ['start' => $new['start'], 'end' => $new['end']])) {
                $changes[] = ['kind' => 'section_span_moved', 'section' => $label, 'before' => [$old['start'], $old['end']], 'after' => [$new['start'], $new['end']]];
            }

            foreach (array_diff($new['review_flags'], $old['review_flags']) as $flag) {
                $changes[] = ['kind' => 'section_flag_added', 'flag' => $flag, 'section' => $label];
            }

            foreach (array_diff($old['review_flags'], $new['review_flags']) as $flag) {
                $changes[] = ['kind' => 'section_flag_removed', 'flag' => $flag, 'section' => $label];
            }

            $oldMedia = $old['media']['signature'] ?? null;
            $newMedia = $new['media']['signature'] ?? null;

            if ($oldMedia !== null && $newMedia === null) {
                $changes[] = ['kind' => 'section_media_lost', 'section' => $label];
                $attention[] = sprintf('%s lost its extracted media', $label);
            } elseif ($oldMedia !== $newMedia || ($old['media']['extracted_at'] ?? null) !== ($new['media']['extracted_at'] ?? null)) {
                $changes[] = ['kind' => 'section_media_recut', 'section' => $label];
            }

            if ($old['song_videos'] !== $new['song_videos']) {
                $changes[] = ['kind' => 'section_song_videos_changed', 'section' => $label, 'before' => $old['song_videos'], 'after' => $new['song_videos']];
            }

            if ($old['needs_manual_review'] === true && $new['needs_manual_review'] === false) {
                $attention[] = sprintf('%s left manual review', $label);
            }

            if ($old['publication_status'] !== 'published' && $new['publication_status'] === 'published') {
                $attention[] = sprintf('%s became published', $label);
            }
        }

        return [$changes, $attention];
    }

    /**
     * Holds keyed by (found_by, reason) across the whole run.
     *
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function holdChanges(array $before, array $after): array
    {
        $beforeHolds = $this->holdIndex($before);
        $afterHolds = $this->holdIndex($after);
        $changes = [];
        $attention = [];

        foreach ($beforeHolds as $key => $hold) {
            if (! $hold['live']) {
                continue;
            }

            $next = $afterHolds[$key] ?? null;

            if ($next === null) {
                $changes[] = ['kind' => 'hold_dropped', 'found_by' => $hold['found_by'], 'reason' => $hold['reason'], 'section' => $hold['section']];
                $attention[] = sprintf('live %s hold on %s is gone: %s', $hold['found_by'] ?? 'unrecorded', $hold['section'], $hold['reason']);
            } elseif (! $next['live']) {
                $changes[] = ['kind' => 'hold_cleared', 'found_by' => $hold['found_by'], 'reason' => $hold['reason'], 'section' => $next['section']];
            } elseif ($next['section'] !== $hold['section']) {
                $changes[] = ['kind' => 'hold_carried', 'found_by' => $hold['found_by'], 'reason' => $hold['reason'], 'before' => $hold['section'], 'after' => $next['section']];
            }
        }

        foreach ($afterHolds as $key => $hold) {
            if ($hold['live'] && ! ($beforeHolds[$key]['live'] ?? false)) {
                $changes[] = ['kind' => 'hold_raised', 'found_by' => $hold['found_by'], 'reason' => $hold['reason'], 'section' => $hold['section']];
            }
        }

        return [$changes, $attention];
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return array<string, array{found_by: string|null, reason: string, live: bool, section: string}>
     */
    private function holdIndex(array $sections): array
    {
        $index = [];

        foreach ($sections as $section) {
            foreach ((array) ($section['holds'] ?? []) as $hold) {
                $key = json_encode([$hold['found_by'] ?? null, $hold['reason'] ?? ''], JSON_THROW_ON_ERROR);
                $entry = ['found_by' => $hold['found_by'] ?? null, 'reason' => (string) ($hold['reason'] ?? ''), 'live' => (bool) ($hold['live'] ?? false), 'section' => $this->label($section)];

                // A live copy wins over a cleared one, so a hold that is live anywhere counts as held.
                if (! isset($index[$key]) || (! $index[$key]['live'] && $entry['live'])) {
                    $index[$key] = $entry;
                }
            }
        }

        return $index;
    }

    /**
     * Greedy pairing by overlap over union: the closest pairs are taken first, so a section that
     * was split pairs with its larger half and the smaller half reads as added.
     *
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     * @return array{0: list<array{0: array<string, mixed>, 1: array<string, mixed>}>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>}
     */
    private function pair(array $before, array $after): array
    {
        $candidates = [];

        foreach ($before as $i => $old) {
            foreach ($after as $j => $new) {
                $overlap = min($old['end'], $new['end']) - max($old['start'], $new['start']);

                if ($overlap <= 0) {
                    continue;
                }

                $union = max($old['end'], $new['end']) - min($old['start'], $new['start']);
                $candidates[] = [$overlap / $union, $old['type'] === $new['type'] ? 1 : 0, $i, $j];
            }
        }

        usort($candidates, static fn (array $a, array $b): int => [$b[0], $b[1], $a[2], $a[3]] <=> [$a[0], $a[1], $b[2], $b[3]]);

        $pairs = [];
        $usedBefore = [];
        $usedAfter = [];

        foreach ($candidates as [, , $i, $j]) {
            if (isset($usedBefore[$i]) || isset($usedAfter[$j])) {
                continue;
            }

            $usedBefore[$i] = true;
            $usedAfter[$j] = true;
            $pairs[] = [$before[$i], $after[$j]];
        }

        usort($pairs, static fn (array $a, array $b): int => $a[0]['start'] <=> $b[0]['start']);

        return [
            $pairs,
            array_values(array_diff_key($before, $usedBefore)),
            array_values(array_diff_key($after, $usedAfter)),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<array<string, mixed>>
     */
    private function sections(array $state): array
    {
        return array_values(array_filter((array) ($state['sections'] ?? []), 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $section
     */
    private function label(array $section): string
    {
        return sprintf('§%s %s %.1f–%.1f', $section['id'] ?? '?', $section['type'] ?? '?', $section['start'] ?? 0, $section['end'] ?? 0);
    }

    private function spansEqual(mixed $before, mixed $after): bool
    {
        if (! is_array($before) || ! is_array($after)) {
            return $before === $after;
        }

        return abs((float) $before['start'] - (float) $after['start']) <= self::SPAN_TOLERANCE_SECONDS
            && abs((float) $before['end'] - (float) $after['end']) <= self::SPAN_TOLERANCE_SECONDS;
    }
}
