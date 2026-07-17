<?php

declare(strict_types=1);

namespace Tests\Integration\Observers;

use App\Enums\SermonContentType;
use App\Jobs\MoveSermonToPrivateStorage;
use App\Models\Sermon;
use App\Observers\SermonObserver;
use App\Services\Public\PodcastFeedService;
use App\Services\Public\SermonRepository;
use App\Services\Scripture\SermonScriptureFilterIndexService;
use App\Services\Sermon\SermonStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonObserverTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_does_not_rebuild_scripture_filters_for_unrelated_updates(): void
    {
        $created = Sermon::withoutEvents(fn (): Sermon => Sermon::factory()->create([
            'reference' => 'John 3:16',
        ]));
        $sermon = Sermon::query()->findOrFail($created->id);

        Sermon::withoutEvents(function () use ($sermon): void {
            $sermon->update(['title' => 'Updated Title Only']);
        });

        $service = $this->createMock(SermonScriptureFilterIndexService::class);
        $service->expects($this->never())->method('syncForSermon');

        $observer = $this->makeObserver(scriptureFilterIndexService: $service);
        $observer->saved($sermon);
    }

    #[Test]
    public function it_rebuilds_scripture_filters_when_reference_changes(): void
    {
        $created = Sermon::withoutEvents(fn (): Sermon => Sermon::factory()->create([
            'reference' => 'John 3:16',
        ]));
        $sermon = Sermon::query()->findOrFail($created->id);

        Sermon::withoutEvents(function () use ($sermon): void {
            $sermon->update(['reference' => 'Romans 8:1-4']);
        });

        $service = $this->createMock(SermonScriptureFilterIndexService::class);
        $service->expects($this->once())
            ->method('syncForSermon')
            ->with($this->callback(fn (Sermon $subject): bool => $subject->is($sermon)));

        $observer = $this->makeObserver(scriptureFilterIndexService: $service);
        $observer->saved($sermon);
    }

    #[Test]
    #[DataProvider('protectedDirectMediaFields')]
    public function it_dispatches_the_private_mover_for_same_type_media_reprocessing(string $field, string $path): void
    {
        Queue::fake();
        $sermon = Sermon::withoutEvents(fn (): Sermon => Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
        ]));

        Sermon::withoutEvents(fn () => $sermon->update([$field => $path]));

        $observer = $this->makeObserver();
        $observer->saved($sermon);

        Queue::assertPushed(
            MoveSermonToPrivateStorage::class,
            fn (MoveSermonToPrivateStorage $job): bool => true,
        );
    }

    #[Test]
    public function it_dispatches_the_private_mover_after_late_thumbnail_generation(): void
    {
        Queue::fake();
        $sermon = Sermon::withoutEvents(fn (): Sermon => Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
        ]));

        Sermon::withoutEvents(fn () => $sermon->update([
            'thumbnail_file_path' => 'thumbs/late-primary.webp',
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => 'thumbs/late-plain.webp',
                'thumbnail_candidates' => [[
                    'id' => 'late-candidate',
                    'timestamp' => 30.0,
                    'score' => 0.8,
                    'plain_path' => 'thumbs/late-candidate-plain.webp',
                ]],
            ],
        ]));

        $observer = $this->makeObserver();
        $observer->saved($sermon);

        Queue::assertPushed(MoveSermonToPrivateStorage::class);
    }

    #[Test]
    public function it_does_not_redispatch_when_the_mover_commits_private_paths(): void
    {
        Queue::fake();
        $sermon = Sermon::withoutEvents(fn (): Sermon => Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
        ]));

        Sermon::withoutEvents(fn () => $sermon->update([
            'audio_file_path' => 'private/sermons/audio.mp3',
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => 'private/thumbs/plain.webp',
            ],
        ]));

        $observer = $this->makeObserver();
        $observer->saved($sermon);

        Queue::assertNotPushed(MoveSermonToPrivateStorage::class);
    }

    #[Test]
    public function it_clears_file_metadata_when_the_audio_path_changes(): void
    {
        $sermon = Sermon::withoutEvents(fn (): Sermon => Sermon::factory()->create());
        Sermon::withoutEvents(fn () => $sermon->update(['audio_file_path' => 'sermons/replaced.mp3']));

        $storageService = $this->createMock(SermonStorageService::class);
        $storageService->expects($this->once())
            ->method('clearCachedMetadata')
            ->with($this->callback(fn (Sermon $subject): bool => $subject->is($sermon)));

        $observer = $this->makeObserver(storageService: $storageService);

        $observer->saved($sermon);
    }

    private function makeObserver(
        ?SermonScriptureFilterIndexService $scriptureFilterIndexService = null,
        ?SermonStorageService $storageService = null,
    ): SermonObserver {
        return new SermonObserver(
            app(PodcastFeedService::class),
            app(SermonRepository::class),
            $scriptureFilterIndexService ?? $this->createStub(SermonScriptureFilterIndexService::class),
            $storageService ?? $this->createStub(SermonStorageService::class),
        );
    }

    /** @return array<string, array{string, string}> */
    public static function protectedDirectMediaFields(): array
    {
        return [
            'audio' => ['audio_file_path', 'sermons/audio.mp3'],
            'video' => ['video_file_path', 'sermons/video.mp4'],
            'transcript' => ['transcript_file_path', 'transcripts/talk.md'],
        ];
    }
}
