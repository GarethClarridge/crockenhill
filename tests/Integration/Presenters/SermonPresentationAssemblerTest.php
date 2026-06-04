<?php

declare(strict_types=1);

namespace Tests\Integration\Presenters;

use App\Models\Sermon;
use App\Presenters\SermonPresentationAssembler;
use App\Presenters\SermonViewPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonPresentationAssemblerTest extends TestCase
{
    use RefreshDatabase;

    private SermonViewPresenter $presenter;

    private SermonPresentationAssembler $assembler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2025-03-15 12:00:00'));

        Storage::fake('public');
        Config::set('media-processing.storage.sermon_disk', 'public');
        Config::set('thumbnail-generation.storage.disk', 'public');
        Config::set('media-processing.storage.transcript_disk', 'public');

        $this->presenter = app(SermonViewPresenter::class);
        $this->assembler = new SermonPresentationAssembler;
    }

    #[Test]
    public function for_api_produces_the_expected_key_set(): void
    {
        $sermon = Sermon::factory()->create();

        $this->assertSame([
            'audio_url',
            'display_reference',
            'duration_iso8601',
            'formatted_duration',
            'human_date',
            'preacher_image_url',
            'preacher_name',
            'preacher_url',
            'series_url',
            'thumbnail_url',
            'video_url',
        ], array_keys($this->assembler->forApi($this->presenter, $sermon)));
    }

    #[Test]
    public function for_list_produces_the_expected_key_set(): void
    {
        $sermon = Sermon::factory()->create();

        $this->assertSame([
            'audio_url',
            'canonical_url',
            'card_thumbnail_url',
            'date_iso',
            'date_string',
            'display_reference',
            'duration_iso8601',
            'formatted_duration',
            'has_transcript',
            'human_date',
            'plain_thumbnail_url',
            'preacher_image_url',
            'preacher_name',
            'preacher_url',
            'public_url',
            'series_url',
            'service_label',
            'thumbnail_url',
            'transcript_url',
            'video_url',
        ], array_keys($this->assembler->forList($this->presenter, $sermon)));
    }

    #[Test]
    public function for_full_adds_transcript_and_outline_to_the_list_shape(): void
    {
        $sermon = Sermon::factory()->create();

        $full = $this->assembler->forFull($this->presenter, $sermon);

        // forFull is the list shape plus the three single-view extras.
        $extraKeys = array_diff(array_keys($full), array_keys($this->assembler->forList($this->presenter, $sermon)));

        $this->assertSame(['transcript', 'plain_text_outline'], array_values($extraKeys));
    }

    #[Test]
    public function assembler_output_matches_the_presenter_delegations(): void
    {
        $sermon = Sermon::factory()->create();

        // The presenter's present* methods delegate to the assembler, so the
        // assembled arrays must equal what the presenter returns.
        $this->assertSame($this->presenter->presentForApi($sermon), $this->assembler->forApi($this->presenter, $sermon));
        $this->assertSame($this->presenter->presentForList($sermon), $this->assembler->forList($this->presenter, $sermon));
        $this->assertSame($this->presenter->present($sermon), $this->assembler->forFull($this->presenter, $sermon));
    }
}
