<?php

declare(strict_types=1);

namespace Tests\Unit\Presenters;

use App\Models\Sermon;
use App\Presenters\SermonPresentationAssembler;
use App\Presenters\SermonViewPresenter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonPresentationAssemblerTest extends TestCase
{
    use DatabaseTransactions;

    private SermonViewPresenter&MockInterface $presenter;

    private SermonPresentationAssembler $assembler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->presenter = Mockery::mock(SermonViewPresenter::class);
        $this->assembler = new SermonPresentationAssembler;
    }

    #[Test]
    public function for_api_returns_expected_shape(): void
    {
        $sermon = Sermon::factory()->make();

        $this->presenter->shouldReceive('audioUrl')->with($sermon)->andReturn('audio_url');
        $this->presenter->shouldReceive('displayReference')->with($sermon)->andReturn('ref');
        $this->presenter->shouldReceive('durationIso8601')->with($sermon)->andReturn('iso_duration');
        $this->presenter->shouldReceive('formattedDuration')->with($sermon)->andReturn('human_duration');
        $this->presenter->shouldReceive('humanDate')->with($sermon)->andReturn('human_date');
        $this->presenter->shouldReceive('preacherImageUrl')->with($sermon)->andReturn('preacher_img');
        $this->presenter->shouldReceive('displayPreacherName')->with($sermon)->andReturn('preacher_name');
        $this->presenter->shouldReceive('preacherUrl')->with($sermon)->andReturn('preacher_url');
        $this->presenter->shouldReceive('seriesUrl')->with($sermon)->andReturn('series_url');
        $this->presenter->shouldReceive('thumbnailUrl')->with($sermon)->andReturn('thumb_url');
        $this->presenter->shouldReceive('videoUrl')->with($sermon)->andReturn('video_url');

        $result = $this->assembler->forApi($this->presenter, $sermon);

        $this->assertSame([
            'audio_url' => 'audio_url',
            'display_reference' => 'ref',
            'duration_iso8601' => 'iso_duration',
            'formatted_duration' => 'human_duration',
            'human_date' => 'human_date',
            'preacher_image_url' => 'preacher_img',
            'preacher_name' => 'preacher_name',
            'preacher_url' => 'preacher_url',
            'series_url' => 'series_url',
            'thumbnail_url' => 'thumb_url',
            'video_url' => 'video_url',
        ], $result);
    }

    #[Test]
    public function for_list_returns_expected_shape(): void
    {
        $sermon = Sermon::factory()->make(['slug' => 'test-sermon', 'transcript_file_path' => 'transcript.txt']);

        $this->presenter->shouldReceive('formattedDates')->with($sermon)->andReturn([
            'iso' => '2024-03-10',
            'short' => '10 March 2024',
            'human' => 'March 10, 2024',
        ]);
        $this->presenter->shouldReceive('audioUrl')->with($sermon)->andReturn('audio_url');
        $this->presenter->shouldReceive('canonicalUrl')->with($sermon)->andReturn('canonical_url');
        $this->presenter->shouldReceive('cardThumbnailUrl')->with($sermon)->andReturn('card_thumb');
        $this->presenter->shouldReceive('displayReference')->with($sermon)->andReturn('ref');
        $this->presenter->shouldReceive('durationIso8601')->with($sermon)->andReturn('iso_duration');
        $this->presenter->shouldReceive('formattedDuration')->with($sermon)->andReturn('human_duration');
        $this->presenter->shouldReceive('plainThumbnailUrl')->with($sermon)->andReturn('plain_thumb');
        $this->presenter->shouldReceive('preacherImageUrl')->with($sermon)->andReturn('preacher_img');
        $this->presenter->shouldReceive('displayPreacherName')->with($sermon)->andReturn('preacher_name');
        $this->presenter->shouldReceive('preacherUrl')->with($sermon)->andReturn('preacher_url');
        $this->presenter->shouldReceive('publicUrl')->with($sermon)->andReturn('public_url');
        $this->presenter->shouldReceive('seriesUrl')->with($sermon)->andReturn('series_url');
        $this->presenter->shouldReceive('serviceLabel')->with($sermon)->andReturn('service_label');
        $this->presenter->shouldReceive('thumbnailUrl')->with($sermon)->andReturn('thumb_url');
        $this->presenter->shouldReceive('transcriptUrl')->with($sermon)->andReturn('transcript_url');
        $this->presenter->shouldReceive('videoUrl')->with($sermon)->andReturn('video_url');

        $result = $this->assembler->forList($this->presenter, $sermon);

        $this->assertSame([
            'audio_url' => 'audio_url',
            'canonical_url' => 'canonical_url',
            'card_thumbnail_url' => 'card_thumb',
            'date_iso' => '2024-03-10',
            'date_string' => '10 March 2024',
            'display_reference' => 'ref',
            'duration_iso8601' => 'iso_duration',
            'formatted_duration' => 'human_duration',
            'has_transcript' => true,
            'human_date' => 'March 10, 2024',
            'plain_thumbnail_url' => 'plain_thumb',
            'preacher_image_url' => 'preacher_img',
            'preacher_name' => 'preacher_name',
            'preacher_url' => 'preacher_url',
            'public_url' => 'public_url',
            'series_url' => 'series_url',
            'service_label' => 'service_label',
            'thumbnail_url' => 'thumb_url',
            'transcript_url' => 'transcript_url',
            'video_url' => 'video_url',
        ], $result);
    }

    #[Test]
    public function for_full_returns_expected_shape(): void
    {
        $sermon = Sermon::factory()->make();
        $listData = ['key' => 'value'];

        $this->presenter->shouldReceive('presentForList')->with($sermon)->andReturn($listData);
        $this->presenter->shouldReceive('transcript')->with($sermon)->andReturn('transcript_content');
        $this->presenter->shouldReceive('plainTextOutline')->with($sermon)->andReturn('outline_content');

        $result = $this->assembler->forFull($this->presenter, $sermon);

        $this->assertSame([
            'key' => 'value',
            'transcript' => 'transcript_content',
            'plain_text_outline' => 'outline_content',
        ], $result);
    }
}
