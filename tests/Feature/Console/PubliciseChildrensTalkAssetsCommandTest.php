<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\SermonContentType;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The queue connection is `sync` under test, so a dispatched move runs inline
 * and these assertions cover the real storage outcome rather than the shape of
 * the queued job.
 */
class PubliciseChildrensTalkAssetsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        Storage::fake('transcripts');

        config([
            'thumbnail-generation.storage.disk' => 'public',
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.storage.transcript_disk' => 'transcripts',
        ]);
    }

    #[Test]
    public function it_reports_nothing_to_copy_when_no_talk_references_private_assets(): void
    {
        $this->talk(['audio_file_path' => 'sermons/already-public.mp3']);

        $this->artisan('media:publicise-childrens-talk-assets')
            ->expectsOutputToContain('No children\'s talks reference private assets')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_changes_nothing_without_apply(): void
    {
        Storage::disk('local')->put('private/sermons/audio.mp3', 'audio-content');
        $talk = $this->talk(['audio_file_path' => 'private/sermons/audio.mp3']);

        $this->artisan('media:publicise-childrens-talk-assets')
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);

        $this->assertSame('private/sermons/audio.mp3', $talk->fresh()?->audio_file_path);
        Storage::disk('public')->assertMissing('sermons/audio.mp3');
        Storage::disk('local')->assertExists('private/sermons/audio.mp3');
    }

    #[Test]
    public function it_copies_assets_onto_the_sermon_disk_and_keeps_the_private_source(): void
    {
        Storage::disk('local')->put('private/sermons/audio.mp3', 'audio-content');
        Storage::disk('local')->put('private/transcripts/talk.md', 'transcript');
        $talk = $this->talk([
            'audio_file_path' => 'private/sermons/audio.mp3',
            'transcript_file_path' => 'private/transcripts/talk.md',
        ]);

        $this->artisan('media:publicise-childrens-talk-assets --apply')
            ->expectsOutputToContain('APPLYING')
            ->assertExitCode(0);

        $talk->refresh();

        $this->assertSame('sermons/audio.mp3', $talk->audio_file_path);
        $this->assertSame('transcripts/talk.md', $talk->transcript_file_path);
        Storage::disk('public')->assertExists('sermons/audio.mp3');
        Storage::disk('transcripts')->assertExists('transcripts/talk.md');

        // Copy-only: the private originals remain as a byte-identical rollback.
        Storage::disk('local')->assertExists('private/sermons/audio.mp3');
        Storage::disk('local')->assertExists('private/transcripts/talk.md');
    }

    #[Test]
    public function it_refuses_a_delete_pass_while_a_talk_still_references_private_assets(): void
    {
        Storage::disk('local')->put('private/sermons/audio.mp3', 'audio-content');
        $talk = $this->talk(['audio_file_path' => 'private/sermons/audio.mp3']);

        $this->artisan('media:publicise-childrens-talk-assets --apply --delete-source')
            ->expectsOutputToContain('still reference private assets')
            ->assertExitCode(1);

        // Nothing copied and nothing deleted — the operator must copy first.
        $this->assertSame('private/sermons/audio.mp3', $talk->fresh()?->audio_file_path);
        Storage::disk('local')->assertExists('private/sermons/audio.mp3');
        Storage::disk('public')->assertMissing('sermons/audio.mp3');
    }

    #[Test]
    public function it_deletes_private_sources_on_a_later_pass_once_the_copies_are_committed(): void
    {
        Storage::disk('local')->put('private/sermons/audio.mp3', 'audio-content');
        $talk = $this->talk(['audio_file_path' => 'private/sermons/audio.mp3']);

        $this->artisan('media:publicise-childrens-talk-assets --apply')->assertExitCode(0);
        $this->artisan('media:publicise-childrens-talk-assets --apply --delete-source')->assertExitCode(0);

        $this->assertSame('sermons/audio.mp3', $talk->fresh()?->audio_file_path);
        Storage::disk('public')->assertExists('sermons/audio.mp3');
        Storage::disk('local')->assertMissing('private/sermons/audio.mp3');
    }

    #[Test]
    public function it_limits_the_run_to_the_requested_talks(): void
    {
        Storage::disk('local')->put('private/sermons/first.mp3', 'first');
        Storage::disk('local')->put('private/sermons/second.mp3', 'second');
        $first = $this->talk(['audio_file_path' => 'private/sermons/first.mp3']);
        $second = $this->talk(['audio_file_path' => 'private/sermons/second.mp3']);

        $this->artisan("media:publicise-childrens-talk-assets --apply --talk={$first->id}")
            ->assertExitCode(0);

        $this->assertSame('sermons/first.mp3', $first->fresh()?->audio_file_path);
        $this->assertSame('private/sermons/second.mp3', $second->fresh()?->audio_file_path);
        Storage::disk('public')->assertMissing('sermons/second.mp3');
    }

    #[Test]
    public function it_ignores_sermons_that_are_not_childrens_talks(): void
    {
        Storage::disk('local')->put('private/sermons/audio.mp3', 'audio-content');
        $sermon = Sermon::withoutEvents(fn (): Sermon => Sermon::factory()->create([
            'content_type' => SermonContentType::Sermon,
            'audio_file_path' => 'private/sermons/audio.mp3',
        ]));

        $this->artisan('media:publicise-childrens-talk-assets --apply')
            ->expectsOutputToContain('No children\'s talks reference private assets')
            ->assertExitCode(0);

        $this->assertSame('private/sermons/audio.mp3', $sermon->fresh()?->audio_file_path);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function talk(array $attributes): Sermon
    {
        return Sermon::withoutEvents(fn (): Sermon => Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            ...$attributes,
        ]));
    }
}
