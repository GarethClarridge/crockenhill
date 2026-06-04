<?php

declare(strict_types=1);

namespace Tests\Integration\Observers;

use App\Models\Sermon;
use App\Observers\SermonObserver;
use App\Services\Scripture\SermonScriptureFilterIndexService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $observer = new SermonObserver($service);
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

        $observer = new SermonObserver($service);
        $observer->saved($sermon);
    }
}
