<?php

declare(strict_types=1);

namespace Tests\Integration\Observers;

use App\Models\Preacher;
use App\Models\Sermon;
use App\Observers\PreacherObserver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PreacherObserverTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_updates_sermon_preacher_names_when_preacher_name_is_updated(): void
    {
        $preacher = Preacher::withoutEvents(fn () => Preacher::factory()->create(['name' => 'Old Name']));
        $sermon = Sermon::withoutEvents(fn () => Sermon::factory()->create([
            'preacher_id' => $preacher->id,
            'preacher' => 'Old Name',
        ]));

        Preacher::withoutEvents(function () use ($preacher) {
            $preacher->update(['name' => 'New Name']);
        });

        $observer = new PreacherObserver;
        $observer->updated($preacher);

        $this->assertEquals('New Name', $sermon->fresh()->preacher);
    }

    #[Test]
    public function it_does_not_update_sermons_when_other_preacher_attributes_are_updated(): void
    {
        $preacher = Preacher::withoutEvents(fn () => Preacher::factory()->create(['name' => 'Constant Name']));
        $sermon = Sermon::withoutEvents(fn () => Sermon::factory()->create([
            'preacher_id' => $preacher->id,
            'preacher' => 'Constant Name',
        ]));

        // Manually set sermon preacher to something else to see if it gets "reverted" (it shouldn't)
        $sermon->updateQuietly(['preacher' => 'Should Remain']);

        Preacher::withoutEvents(function () use ($preacher) {
            $preacher->update(['is_active' => ! $preacher->is_active]);
        });

        $observer = new PreacherObserver;
        $observer->updated($preacher);

        $this->assertEquals('Should Remain', $sermon->fresh()->preacher);
    }

    #[Test]
    public function it_only_updates_sermons_belonging_to_the_updated_preacher(): void
    {
        $preacherA = Preacher::withoutEvents(fn () => Preacher::factory()->create(['name' => 'Preacher A']));
        $preacherB = Preacher::withoutEvents(fn () => Preacher::factory()->create(['name' => 'Preacher B']));

        $sermonA = Sermon::withoutEvents(fn () => Sermon::factory()->create([
            'preacher_id' => $preacherA->id,
            'preacher' => 'Preacher A',
        ]));

        $sermonB = Sermon::withoutEvents(fn () => Sermon::factory()->create([
            'preacher_id' => $preacherB->id,
            'preacher' => 'Preacher B',
        ]));

        Preacher::withoutEvents(function () use ($preacherA) {
            $preacherA->update(['name' => 'New Name A']);
        });

        $observer = new PreacherObserver;
        $observer->updated($preacherA);

        $this->assertEquals('New Name A', $sermonA->fresh()->preacher);
        $this->assertEquals('Preacher B', $sermonB->fresh()->preacher);
    }
}
