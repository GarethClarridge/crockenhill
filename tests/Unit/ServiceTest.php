<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Crockenhill\Service; // Assuming Crockenhill namespace
use Crockenhill\Sermon;  // Assuming Crockenhill namespace
use Database\Factories\ServiceFactory;
use Database\Factories\SermonFactory;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Carbon\Carbon;

class ServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function testServiceRelationships()
    {
        $service = Service::factory()->create();
        $sermon1 = Sermon::factory()->forService($service)->create();
        $sermon2 = Sermon::factory()->forService($service)->create();

        // Test sermons() relationship (hasMany)
        // This assumes: public function sermons() { return $this->hasMany(Sermon::class); }
        $this->assertInstanceOf(EloquentCollection::class, $service->sermons);
        $this->assertCount(2, $service->sermons);
        $this->assertTrue($service->sermons->contains($sermon1));
        $this->assertTrue($service->sermons->contains($sermon2));
    }

    /**
     * @test
     */
    public function testServiceAccessors()
    {
        // Test getFormattedNameAttribute (assuming it returns the name directly for now)
        $service = Service::factory()->create(['name' => 'Morning Worship']);
        $this->assertEquals('Morning Worship', $service->formatted_name);
        // Assumes: public function getFormattedNameAttribute() { return $this->attributes['name']; }

        // Test getUpcomingSermonCountAttribute
        $serviceWithSermons = Service::factory()->create();
        Sermon::factory()->forService($serviceWithSermons)->withDate(Carbon::now()->subWeek())->create(); // Past
        Sermon::factory()->forService($serviceWithSermons)->withDate(Carbon::now()->addWeek())->create();   // Future
        Sermon::factory()->forService($serviceWithSermons)->withDate(Carbon::now()->addMonth())->create();  // Future

        // Refresh to load relationships if accessor depends on them being preloaded.
        // However, a good accessor would query the relationship itself.
        // $serviceWithSermons->refresh();

        $this->assertEquals(2, $serviceWithSermons->upcoming_sermon_count);
        // Assumes: public function getUpcomingSermonCountAttribute() {
        //     return $this->sermons()->where('date', '>=', Carbon::now())->count();
        // }

        $serviceWithoutSermons = Service::factory()->create();
        $this->assertEquals(0, $serviceWithoutSermons->upcoming_sermon_count);
    }

    /**
     * @test
     */
    public function testServiceMutatorsAndCasts()
    {
        // Test is_active casting to boolean
        $activeService = Service::factory()->active()->create();
        $this->assertTrue($activeService->is_active);

        $inactiveService = Service::factory()->inactive()->create();
        $this->assertFalse($inactiveService->is_active);
        // Assumes: protected $casts = ['is_active' => 'boolean'];

        // Test service_time casting
        // Assuming service_time is cast to a Carbon instance or a specific time string format.
        // If it's just a string like 'HH:MM:SS' and not cast, Carbon check isn't needed.
        // For this test, let's assume it's cast to a Carbon object for time handling flexibility.
        $time = '10:30:00';
        $serviceWithTime = Service::factory()->atTime($time)->create();

        // If service_time is cast to 'datetime' or 'time' via Carbon:
        $this->assertInstanceOf(Carbon::class, $serviceWithTime->service_time);
        $this->assertEquals($time, $serviceWithTime->service_time->format('H:i:s'));
        // Assumes: protected $casts = ['service_time' => 'datetime:H:i:s']; (or similar Carbon-handled cast)

        // If service_time is simply a string attribute without a cast:
        // $this->assertEquals($time, $serviceWithTime->service_time);
    }

    /**
     * @test
     */
    public function testServiceScopes()
    {
        // Test isActive() scope
        $activeService = Service::factory()->active()->create();
        $inactiveService = Service::factory()->inactive()->create();

        $activeServices = Service::isActive()->get();
        $this->assertTrue($activeServices->contains($activeService));
        $this->assertFalse($activeServices->contains($inactiveService));
        // Assumes: public function scopeIsActive($query) { return $query->where('is_active', true); }

        // Test orderByTime() scope
        $serviceAt1030 = Service::factory()->atTime('10:30:00')->create();
        $serviceAt0900 = Service::factory()->atTime('09:00:00')->create();
        $serviceAt1400 = Service::factory()->atTime('14:00:00')->create();

        $sortedServices = Service::orderByTime()->get();
        $this->assertEquals('09:00:00', $sortedServices->get(0)->service_time->format('H:i:s'));
        $this->assertEquals('10:30:00', $sortedServices->get(1)->service_time->format('H:i:s'));
        $this->assertEquals('14:00:00', $sortedServices->get(2)->service_time->format('H:i:s'));
        // Assumes: public function scopeOrderByTime($query) { return $query->orderBy('service_time', 'asc'); }
        // This also assumes service_time is stored in a way that direct DB ordering works as expected for time.
    }

    /**
     * @test
     */
    public function testCustomServiceMethods()
    {
        // Test hasUpcomingSermons() method
        $serviceWithUpcoming = Service::factory()->create();
        Sermon::factory()->forService($serviceWithUpcoming)->withDate(Carbon::now()->addDays(7))->create();

        $serviceWithPast = Service::factory()->create();
        Sermon::factory()->forService($serviceWithPast)->withDate(Carbon::now()->subDays(7))->create();

        $serviceWithNoSermons = Service::factory()->create();

        $this->assertTrue($serviceWithUpcoming->hasUpcomingSermons());
        $this->assertFalse($serviceWithPast->hasUpcomingSermons());
        $this->assertFalse($serviceWithNoSermons->hasUpcomingSermons());
        // Assumes: public function hasUpcomingSermons(): bool {
        //     return $this->sermons()->where('date', '>=', Carbon::now())->exists();
        // }
    }
}
