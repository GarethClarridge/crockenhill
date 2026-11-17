<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\ServiceSectionType;
use App\Models\ServiceSection;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use Illuminate\Support\Facades\Storage;

class ExtractedSectionMediaChecker
{
    /**
     * Whether the extracted assets a section is published from are present: a talk's video and
     * audio, a song's video. Songs are never cut to audio, so asking for one refused every song
     * held for review (1363's "Rejoice", 2026-10-22) and let a split or merged song keep its clip.
     * A publication handler passes its own audio rule; otherwise the section's type decides.
     */
    public function hasExtractedMedia(ServiceSection $section, ?bool $audioRequired = null): bool
    {
        $audioRequired ??= $section->section_type !== ServiceSectionType::Song;
        $videoPath = $section->extracted_video_path;
        $audioPath = $audioRequired ? $section->extracted_audio_path : null;

        if (! is_string($videoPath) || $videoPath === ''
            || ($audioRequired && (! is_string($audioPath) || $audioPath === ''))) {
            return false;
        }

        $read = static function () use ($section, $videoPath, $audioPath): bool {
            $disk = Storage::disk($section->extractedAssetDisk());

            return $disk->exists($videoPath) && ($audioPath === null || $disk->exists($audioPath));
        };
        $context = $section->processingLog->historicStagingContext();

        return $context === null ? $read() : app(HistoricStagingContextRegistry::class)->within($context, $read);
    }
}
