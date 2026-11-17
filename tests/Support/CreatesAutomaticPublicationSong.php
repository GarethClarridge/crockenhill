<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\AudioProfile;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use Illuminate\Support\Facades\Storage;

trait CreatesAutomaticPublicationSong
{
    private function automaticSong(): ServiceSection
    {
        $service = ChurchService::factory()->create();
        $run = MediaProcessingLog::factory()->livestream()->create(['church_service_id' => $service->id]);
        $item = ChurchServiceItem::factory()->create(['church_service_id' => $service->id, 'song_id' => Song::factory()->create()->id]);
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::Song,
            'song_match_type' => ServiceSectionSongMatchType::Confirmed,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval,
            'needs_manual_review' => false,
            'start_time' => 100.0, 'end_time' => 300.0,
            'extracted_video_path' => 'sections/'.$run->id.'/video.mp4',
            'extracted_audio_path' => null,
            'extracted_at' => now(),
            'metadata' => ['publication_candidate_extraction' => PublicationCandidateFixture::current(AudioProfile::Music)],
        ]);
        Storage::disk('public')->put($section->extracted_video_path, 'video');
        $this->storeAutomaticSongEvidence($section);

        return $section->fresh();
    }

    /** @param list<array{start: float, end: float, text: string}>|null $cues */
    private function storeAutomaticSongEvidence(ServiceSection $section, ?array $cues = null): void
    {
        config(['media-processing.storage.transcript_disk' => 'local']);
        $run = $section->processingLog;
        $path = 'test-automatic/'.$run->id;
        Storage::disk('local')->put($path.'.json', json_encode([
            'cues' => $cues ?? [['start' => (float) $section->start_time, 'end' => (float) $section->end_time, 'text' => 'The song begins.']],
        ], JSON_THROW_ON_ERROR));
        Storage::disk('local')->put($path.'.rms', "pts_time:{$section->start_time}\nlavfi.astats.Overall.RMS_level=-20.0\npts_time:{$section->end_time}\nlavfi.astats.Overall.RMS_level=-20.0");
        $run->putServiceTranscriptPath($path.'.json');
        $run->update(['rms_log_path' => $path.'.rms']);
    }
}
