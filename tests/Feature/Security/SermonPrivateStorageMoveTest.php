<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\SermonContentType;
use App\Jobs\MoveSermonToPrivateStorage;
use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonPrivateStorageMoveTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_dispatches_move_job_when_sermon_is_created_as_childrens_talk(): void
    {
        Queue::fake();

        Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'audio_file_path' => 'sermons/childrens-talk.mp3',
        ]);

        Queue::assertPushed(MoveSermonToPrivateStorage::class);
    }

    #[Test]
    public function it_dispatches_move_job_when_content_type_changes_to_childrens_talk(): void
    {
        Queue::fake();

        $sermon = Sermon::factory()->create([
            'content_type' => SermonContentType::Sermon,
        ]);

        Queue::fake(); // reset — no job dispatched during create of a Sermon type
        Queue::assertNotPushed(MoveSermonToPrivateStorage::class);

        $sermon->update([
            'content_type' => SermonContentType::ChildrensTalk,
            'audio_file_path' => 'sermons/childrens-talk.mp3',
        ]);

        Queue::assertPushed(MoveSermonToPrivateStorage::class);
    }

    #[Test]
    public function it_does_not_dispatch_when_content_type_unchanged_on_update(): void
    {
        Queue::fake();

        $sermon = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
        ]);

        Queue::fake(); // reset after the create dispatch

        // Refresh to clear wasRecentlyCreated flag
        $sermon = $sermon->fresh();

        $sermon->update(['title' => 'Updated title']);

        Queue::assertNotPushed(MoveSermonToPrivateStorage::class);
    }

    #[Test]
    public function it_moves_audio_from_public_to_local_private_disk(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $audioPath = 'sermons/audio/test-sermon.mp3';
        Storage::disk('public')->put($audioPath, 'fake audio content');

        $sermon = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'audio_file_path' => $audioPath,
        ]);

        $job = new MoveSermonToPrivateStorage($sermon->id);
        $job->handle();

        Storage::disk('local')->assertExists('private/'.$audioPath);
        $sermon->refresh();
        $this->assertSame('private/'.$audioPath, $sermon->audio_file_path);
    }

    #[Test]
    public function it_serves_private_audio_to_authenticated_user(): void
    {
        Storage::fake('local');

        $privatePath = 'private/sermons/audio/test-childrens-talk.mp3';
        Storage::disk('local')->put($privatePath, 'fake audio content');

        $sermon = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'audio_file_path' => $privatePath,
        ]);

        $admin = User::factory()->crockenhillAdmin()->create();

        $response = $this->actingAs($admin)->get("/christ/sermons/{$sermon->slug}/audio");

        $response->assertStatus(200);
    }

    #[Test]
    public function it_moves_card_thumbnail_metadata_from_public_to_local_private_disk(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $cardPath = 'sermons/thumbnails/test-card.webp';
        Storage::disk('public')->put($cardPath, 'fake card thumbnail');

        $sermon = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'thumbnail_metadata' => [
                'card_thumbnail_path' => $cardPath,
            ],
        ]);

        $job = new MoveSermonToPrivateStorage($sermon->id);
        $job->handle();

        Storage::disk('local')->assertExists('private/'.$cardPath);
        Storage::disk('public')->assertMissing($cardPath);

        $sermon->refresh();
        $this->assertSame('private/'.$cardPath, $sermon->thumbnail_metadata?->cardThumbnailPath);
    }
}
