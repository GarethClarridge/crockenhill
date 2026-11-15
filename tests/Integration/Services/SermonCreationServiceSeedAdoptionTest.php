<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Data\SermonCreationOptions;
use App\Enums\SermonService;
use App\Enums\TalkType;
use App\Exceptions\SeedResidueException;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Sermon\SermonCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonCreationServiceSeedAdoptionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_real_recording_is_refused_rather_than_adopting_a_seeded_sermon(): void
    {
        $seeded = Sermon::factory()->create([
            'date' => '2024-12-15',
            'service' => SermonService::Morning,
            'content_type' => TalkType::Sermon,
            'title' => 'The Birth of Our Saviour',
            'slug' => 'the-birth-of-our-saviour',
            'reference' => 'Luke 2:1-20',
            'series' => 'Christmas Messages',
            'livestream_processing_id' => null,
        ]);
        $log = $this->liveRun('2024-12-15');

        try {
            app(SermonCreationService::class)->createSermon($log, SermonCreationOptions::fromLivestream($log, []));
            $this->fail('A seeded sermon was adopted.');
        } catch (SeedResidueException $exception) {
            $this->assertStringContainsString('the-birth-of-our-saviour', $exception->getMessage());
            $this->assertStringContainsString('sermons:clear-seeded-residue', $exception->getMessage());
        }

        $this->assertSame('The Birth of Our Saviour', $seeded->fresh()?->title);
        $this->assertSame(1, Sermon::query()->count());
    }

    #[Test]
    public function a_real_sermon_on_the_same_date_is_still_adopted(): void
    {
        $existing = Sermon::factory()->create([
            'date' => '2024-12-15',
            'service' => SermonService::Morning,
            'content_type' => TalkType::Sermon,
            'title' => 'God’s promises to Mary',
            'slug' => 'gods-promises-to-mary',
            'reference' => 'Luke 2:1-20',
            'series' => null,
            'livestream_processing_id' => null,
        ]);
        $log = $this->liveRun('2024-12-15');

        $sermon = app(SermonCreationService::class)->createSermon($log, SermonCreationOptions::fromLivestream($log, []));

        $this->assertSame($existing->id, $sermon->id);
        $this->assertSame('gods-promises-to-mary', $sermon->fresh()?->slug);
    }

    private function liveRun(string $date): MediaProcessingLog
    {
        return MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => $date,
            'extracted_service' => SermonService::Morning,
            'audio_file_path' => 'sermons/audio/weekly.mp3',
        ]);
    }
}
