<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Services\Sermon\SermonExtractionPlanResolver;

/**
 * One sermon preached in parts, around songs, that a draw typed as several sermons (ruling D21).
 *
 * Every draw on runs 1073 and 1268 agreed: Jonah in two parts either side of a hymn, Genesis 1
 * in three. {@see ServiceStructureValidator} allows one sermon section, so every draw failed with
 * `multiple_sermons` and both runs published only part of the sermon (operator, 2026-10-14: join
 * them). Across all banked draws on 2026-10-16 the failure appears on seven runs; on the other
 * five only a minority of draws split, and the ensemble's vote still decides those.
 *
 * The longest part stays the sermon and the others become `other` sections carrying the
 * sentence {@see SermonContinuationScreen} reads, so {@see SermonExtractionPlanResolver} cuts
 * every part into one sermon. That is the mechanism the detector's own continuation notes
 * already use; nothing downstream learns a second way to join a sermon.
 */
final class SplitSermonParts
{
    public const NOTE = 'Part of the main sermon, preached around an intervening item; the draw typed it a second sermon section, so the parts are joined (D21).';

    public function join(ServiceStructure $structure): ServiceStructure
    {
        $sermons = $structure->sectionsOfType(ServiceSectionType::Sermon);

        if (count($sermons) < 2) {
            return $structure;
        }

        $primary = $sermons[0];

        foreach ($sermons as $sermon) {
            if ($sermon->duration() > $primary->duration()) {
                $primary = $sermon;
            }
        }

        $oosItemId = $primary->oosItemId ?? array_values(array_filter(array_map(
            static fn (ServiceStructureSection $sermon): ?int => $sermon->oosItemId,
            $sermons,
        )))[0] ?? null;

        $sections = array_map(
            fn (ServiceStructureSection $section): ServiceStructureSection => match (true) {
                $section === $primary => $this->withOosItem($section, $oosItemId),
                $section->type === ServiceSectionType::Sermon => $this->asPart($section),
                default => $section,
            },
            $structure->sections,
        );

        return new ServiceStructure(
            $sections,
            $structure->notes,
            $structure->model,
            $structure->summary,
            $structure->notices,
            $structure->chapterMarkers,
            $structure->sermonAbsence,
        );
    }

    private function asPart(ServiceStructureSection $section): ServiceStructureSection
    {
        return new ServiceStructureSection(
            type: ServiceSectionType::Other,
            title: $section->title,
            startTime: $section->startTime,
            endTime: $section->endTime,
            confidence: $section->confidence,
            oosItemId: null,
            songTitle: null,
            readingReference: $section->readingReference,
            sermonReference: $section->sermonReference,
            notes: [...$section->notes, self::NOTE],
            reviewFlags: $section->reviewFlags,
            snapDeltas: $section->snapDeltas,
            summary: $section->summary,
        );
    }

    private function withOosItem(ServiceStructureSection $section, ?int $oosItemId): ServiceStructureSection
    {
        if ($section->oosItemId === $oosItemId) {
            return $section;
        }

        return new ServiceStructureSection(
            type: $section->type,
            title: $section->title,
            startTime: $section->startTime,
            endTime: $section->endTime,
            confidence: $section->confidence,
            oosItemId: $oosItemId,
            songTitle: $section->songTitle,
            readingReference: $section->readingReference,
            sermonReference: $section->sermonReference,
            notes: $section->notes,
            reviewFlags: $section->reviewFlags,
            snapDeltas: $section->snapDeltas,
            summary: $section->summary,
            talkType: $section->talkType,
        );
    }
}
