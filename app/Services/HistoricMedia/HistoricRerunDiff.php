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
 * live hold that is absent afterwards, a run the re-run superseded, a section that leaves review
 * or loses its media, or a sermon or song video that becomes public is listed under `attention`:
 * those are the changes that silently lose containment or custody. A section published into quarantine (every video
 * and sermon showing it still quarantined) is the historic path's designed outcome and only a
 * change; a run that extraction parked for its held sermon is pending its re-cut.
 *
 * After a detection round that deferred its media (plan §4.0), a lost clip or review is what the
 * round is expected to leave: no clip is cut and the review a clip decides is not yet made. Those
 * are listed under `pending` instead, and the frozen commit's extraction, diffed against the same
 * snapshot, applies the full checks. Holds are content, not media, and are never deferred.
 */
final class HistoricRerunDiff
{
    /** Seconds a boundary may move before the section counts as re-spanned; below this is snapping noise. */
    private const SPAN_TOLERANCE_SECONDS = 0.5;

    /** The publication state that puts a sermon or song video in public view. */
    private const PUBLIC = 'published';

    /** The manual-review reason extraction records when it parks a run for its held sermon. */
    private const PARKED_FOR_HELD_SERMON = 'sermon_section_content_held';

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>|null  $after  null when the run could not be captured afterwards
     * @return array{changes: list<array<string, mixed>>, attention: list<string>, pending: list<string>}
     */
    public function compare(array $before, ?array $after): array
    {
        if ($after === null) {
            return [
                'changes' => [['kind' => 'run_missing']],
                'attention' => ['run could not be captured after the re-run'],
                'pending' => [],
            ];
        }

        $changes = [];
        $attention = [];

        foreach (['status', 'current_step', 'superseded', 'transcript_sha256', 'sermon_absence', 'extraction_plan'] as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $changes[] = ['kind' => 'run_'.$field.'_changed', 'before' => $before[$field] ?? null, 'after' => $after[$field] ?? null];
            }
        }

        $pending = [];

        // A run that was already unfinished and was not reached is no change; one the re-run
        // leaves unfinished is (run 935, failed and superseded since 08-27, is the first case).
        // Extraction parking a run for its held sermon is containment working, with a named
        // repair, so it is pending rather than a failed re-run.
        if (($after['status'] ?? null) !== 'completed' && ($after['status'] ?? null) !== ($before['status'] ?? null)) {
            if (($after['manual_review_reason'] ?? null) === self::PARKED_FOR_HELD_SERMON) {
                $pending[] = 'run parked for its held sermon; re-cut it with sermons:re-extract --held-section once its span is checked';
            } else {
                $attention[] = sprintf('run is %s after the re-run', (string) ($after['status'] ?? 'unknown'));
            }
        }

        // Every later round refuses a superseded run, so it drops out of the re-run unnoticed
        // unless the diff stops on it (936, handed to a failed sibling in canary 3).
        if (($after['superseded'] ?? false) === true && ($before['superseded'] ?? false) !== true) {
            $attention[] = 'run was superseded by the re-run';
        }

        if (! $this->spansEqual($before['sermon_span'] ?? null, $after['sermon_span'] ?? null)) {
            $changes[] = ['kind' => 'sermon_span_moved', 'before' => $before['sermon_span'] ?? null, 'after' => $after['sermon_span'] ?? null];
        }

        $changes = [...$changes, ...$this->sermonChanges($before['sermon'] ?? null, $after['sermon'] ?? null)];

        if (($after['sermon']['publication_state'] ?? null) === self::PUBLIC && ($before['sermon']['publication_state'] ?? null) !== self::PUBLIC) {
            $attention[] = sprintf('sermon %d became public', $after['sermon']['id']);
        }

        // A state check, not a change check: enrichment is queued rather than awaited, so
        // this diff is where a reference that never got its passage is noticed (908–915).
        $sermon = $after['sermon'] ?? null;

        if (is_array($sermon) && filled($sermon['reference'] ?? null) && ($sermon['scripture_passage_id'] ?? null) === null) {
            $attention[] = sprintf('sermon %d names %s but no passage is linked', $sermon['id'], $sermon['reference']);
        }

        [$sectionChanges, $sectionAttention, $mediaCustody] = $this->sectionChanges(
            $this->sections($before),
            $this->sections($after),
        );
        [$holdChanges, $holdAttention] = $this->holdChanges($this->sections($before), $this->sections($after));
        $mediaDeferred = ($after['media_deferred'] ?? false) === true;

        return [
            'changes' => [...$changes, ...$sectionChanges, ...$holdChanges],
            'attention' => [...$attention, ...$sectionAttention, ...($mediaDeferred ? [] : $mediaCustody), ...$holdAttention],
            'pending' => [...$pending, ...($mediaDeferred ? $mediaCustody : [])],
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
     * @return array{0: list<array<string, mixed>>, 1: list<string>, 2: list<string>} changes,
     *                                                                                attention, and the media custody a detection round defers
     */
    private function sectionChanges(array $before, array $after): array
    {
        $changes = [];
        $attention = [];
        $mediaCustody = [];
        [$pairs, $removed, $added] = $this->pair($before, $after);

        foreach ($removed as $section) {
            $changes[] = ['kind' => 'section_removed', 'section' => $this->label($section)];

            if (($section['media']['signature'] ?? null) !== null) {
                $mediaCustody[] = sprintf('%s had extracted media and is gone', $this->label($section));
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

            // Only between captures that both record it: a snapshot taken before the verdict was
            // captured would otherwise report every song as changed.
            if (array_key_exists('song_review', $old) && array_key_exists('song_review', $new) && $old['song_review'] !== $new['song_review']) {
                $changes[] = ['kind' => 'section_song_review_changed', 'section' => $label, 'before' => $old['song_review'], 'after' => $new['song_review']];
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
                $mediaCustody[] = sprintf('%s lost its extracted media', $label);
            } elseif ($oldMedia !== $newMedia || ($old['media']['extracted_at'] ?? null) !== ($new['media']['extracted_at'] ?? null)) {
                $changes[] = ['kind' => 'section_media_recut', 'section' => $label];
            }

            if ($old['song_videos'] !== $new['song_videos']) {
                $changes[] = ['kind' => 'section_song_videos_changed', 'section' => $label, 'before' => $old['song_videos'], 'after' => $new['song_videos']];
            }

            $wasPublic = array_column(array_filter($old['song_videos'], static fn (array $video): bool => $video['publication_state'] === self::PUBLIC), 'id');

            foreach ($new['song_videos'] as $video) {
                if ($video['publication_state'] === self::PUBLIC && ! in_array($video['id'], $wasPublic, true)) {
                    $attention[] = sprintf('%s song video %d became public', $label, $video['id']);
                }
            }

            if ($this->inReview($old) && ! $this->inReview($new)) {
                $mediaCustody[] = sprintf('%s left review (now %s)', $label, $new['publication_status']);
            }

            if ($old['publication_status'] !== 'published' && $new['publication_status'] === 'published') {
                if ($this->quarantinedOnly($new)) {
                    $changes[] = ['kind' => 'section_published_into_quarantine', 'section' => $label];
                } elseif (($new['published_sermon_state'] ?? null) === self::PUBLIC) {
                    $attention[] = sprintf('%s became public', $label);
                } elseif ($new['song_videos'] === [] && ($new['published_sermon_state'] ?? null) === null) {
                    $attention[] = sprintf('%s became published with nothing recording where it is shown', $label);
                }
            }
        }

        return [$changes, $attention, $mediaCustody];
    }

    /**
     * Whether a section is still waiting on a person. Song review is a publication state
     * (`pending_approval`, with `song_publication_review` reasons), not `needs_manual_review`,
     * which only the boundary-evidence backfill ever set on a song.
     *
     * @param  array<string, mixed>  $section
     */
    private function inReview(array $section): bool
    {
        return $section['needs_manual_review'] === true || $section['publication_status'] === 'pending_approval';
    }

    /**
     * Published into quarantine, the historic path's designed outcome pending §4.5: everything
     * that shows the section exists and none of it is public.
     *
     * @param  array<string, mixed>  $section
     */
    private function quarantinedOnly(array $section): bool
    {
        $states = array_column($section['song_videos'], 'publication_state');

        if (($section['published_sermon_state'] ?? null) !== null) {
            $states[] = $section['published_sermon_state'];
        }

        return $states !== [] && ! in_array(self::PUBLIC, $states, true);
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
