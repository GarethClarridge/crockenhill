<?php

namespace Tests\Unit;

use App\Models\Sermon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonTest extends TestCase
{
    use DatabaseTransactions;

    #[Test] // Replaced @test
    public function test_sermon_relationships()
    {
        // $serviceModel = \App\Models\Service::factory()->create(); // Not a direct relationship anymore
        $sermon = \App\Models\Sermon::factory()->create([
            'service' => 'morning', // Example enum value
        ]);

        $this->assertInstanceOf(\App\Enums\SermonService::class, $sermon->service);
        $this->assertContains($sermon->service, [
            \App\Enums\SermonService::MORNING,
            \App\Enums\SermonService::EVENING,
        ]);
        // $this->assertEquals($serviceModel->id, $sermon->service->id); // This was incorrect

        // PlayDate related assertions are removed as PlayDate feature is removed.
        // $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $sermon->playDates);
        // $this->assertCount(2, $sermon->playDates);
        // $this->assertTrue($sermon->playDates->contains($playDate1));
        // $this->assertTrue($sermon->playDates->contains($playDate2));
    }

    #[Test] // Replaced @test
    public function test_sermon_accessors()
    {
        $date = Carbon::create(2023, 1, 15, 10, 0, 0);
        $testFilename = 'sermons/audio.mp3';
        $sermon = \App\Models\Sermon::factory()->withDate($date)->create([ // Explicitly use App\Models
            'series' => 'My Sermon Series',
            'preacher' => 'John Doe',
            'filename' => $testFilename, // Changed audio_url to filename
        ]);

        // Test getHumanDateAttribute
        $this->assertEquals($date->format('F j, Y'), $sermon->human_date);

        // Test getAudioUrlAttribute - Returns full URL via SermonStorageService
        // The model uses SermonStorageService which returns full URLs including the base URL
        $this->assertNotNull($sermon->audio_url);
        $this->assertStringContainsString($testFilename, $sermon->audio_url);

        // Test getSeriesUrlAttribute
        // Assuming the Sermon model has a getSeriesUrlAttribute accessor
        $expectedSeriesUrl = '/christ/sermons/series/'.Str::slug('My Sermon Series'); // Corrected expected path
        $this->assertEquals($expectedSeriesUrl, $sermon->series_url);
        // If the accessor is not yet implemented, this test will guide its creation.

        // Test getPreacherUrlAttribute
        // Assuming the Sermon model has a getPreacherUrlAttribute accessor
        $expectedPreacherUrl = '/christ/sermons/preachers/'.Str::slug('John Doe'); // Corrected expected path
        $this->assertEquals($expectedPreacherUrl, $sermon->preacher_url);
        // If the accessor is not yet implemented, this test will guide its creation.
    }

    #[Test] // Replaced @test
    public function test_sermon_mutators_and_casts()
    {
        // Test 'points' attribute casting to array when explicitly set with valid JSON
        $pointsArray = ['Point 1: Introduction', 'Point 2: Main Body', 'Point 3: Conclusion'];
        // $pointsJson = json_encode($pointsArray); // No longer needed

        $sermonCreated = \App\Models\Sermon::factory()->create(['points' => $pointsArray]); // Pass array directly
        $sermonFetched = \App\Models\Sermon::find($sermonCreated->id);

        Log::info('SermonTest - Raw points from DB for Sermon ID '.$sermonFetched->id.': '.print_r($sermonFetched->getAttributes()['points'], true));
        Log::info('SermonTest - Casted points type for Sermon ID '.$sermonFetched->id.': '.gettype($sermonFetched->points));

        $this->assertIsArray($sermonFetched->points);
        $this->assertEquals($pointsArray, $sermonFetched->points);

        // Test 'date' attribute casting to Carbon instance
        $sermonWithDate = \App\Models\Sermon::factory()->create(['date' => '2023-03-15']);
        $this->assertInstanceOf(Carbon::class, $sermonWithDate->date);

        // Test 'points' from default factory creation (can be null or non-JSON text via faker->optional()->text())
        $sermonFromFactory = \App\Models\Sermon::factory()->create();
        $this->assertInstanceOf(Carbon::class, $sermonFromFactory->date);
        // Check if points from factory are cast to array OR are null after accessor processing
        $this->assertTrue(
            is_array($sermonFromFactory->points) || is_null($sermonFromFactory->points),
            'Points attribute should be an array or null after accessor processing when using default factory state.'
        );
    }

    #[Test] // Replaced @test
    public function test_sermon_scopes()
    {
        // Test last12Months scope
        $withinLast12Months = \App\Models\Sermon::factory()->withDate(Carbon::now()->subMonths(6))->create();
        $olderThan12Months = \App\Models\Sermon::factory()->withDate(Carbon::now()->subMonths(15))->create();
        $futureSermon = \App\Models\Sermon::factory()->withDate(Carbon::now()->addMonths(2))->create();

        $sermonsLast12Months = \App\Models\Sermon::last12Months()->get();
        $this->assertTrue($sermonsLast12Months->contains($withinLast12Months));
        $this->assertTrue($sermonsLast12Months->contains($futureSermon));
        $this->assertFalse($sermonsLast12Months->contains($olderThan12Months));

        // Test forService scope (no Service model, just use string type)
        $service1Date = Carbon::parse('2023-03-10');
        $service1Type = 'morning';
        $service2Date = Carbon::parse('2023-03-12');
        $service2Type = 'evening';

        $sermonForService1 = \App\Models\Sermon::factory()->create([
            'date' => $service1Date,
            'service' => $service1Type,
        ]);
        $sermonForService2 = \App\Models\Sermon::factory()->create([
            'date' => $service2Date,
            'service' => $service2Type,
        ]);
        // Another sermon on same date as service1 but different type
        \App\Models\Sermon::factory()->create(['date' => $service1Date, 'service' => 'evening']);

        // The scopeForService filters by service type (enum 'morning'/'evening')
        $sermonsForService1ByType = \App\Models\Sermon::forService($service1Type)
            ->whereDate('date', $service1Date)
            ->get();
        $this->assertTrue($sermonsForService1ByType->contains($sermonForService1));
        $this->assertFalse($sermonsForService1ByType->contains($sermonForService2));

        // If we want to assert that sermons for 'morning' services on a specific date are found:
        $sermonsForMorningServiceOnDate = \App\Models\Sermon::forService('morning')
            ->whereDate('date', $service1Date)
            ->get();
        $this->assertTrue($sermonsForMorningServiceOnDate->contains($sermonForService1));

        // Test inSeries scope
        $seriesATitle = 'The Beatitudes';
        $seriesBTitle = 'Fruit of the Spirit';
        $sermonInSeriesA = \App\Models\Sermon::factory()->inSeries($seriesATitle)->create();
        $sermonInSeriesB = \App\Models\Sermon::factory()->inSeries($seriesBTitle)->create();

        $sermonsInSeriesA = \App\Models\Sermon::inSeries($seriesATitle)->get();
        $this->assertTrue($sermonsInSeriesA->contains($sermonInSeriesA));
        $this->assertFalse($sermonsInSeriesA->contains($sermonInSeriesB));

        // Test byPreacher scope
        $preacherXName = 'Rev. Dr. Smith';
        $preacherYName = 'Pastor Jones';
        $sermonByPreacherX = \App\Models\Sermon::factory()->byPreacher($preacherXName)->create();
        $sermonByPreacherY = \App\Models\Sermon::factory()->byPreacher($preacherYName)->create();

        $sermonsByPreacherX = \App\Models\Sermon::byPreacher($preacherXName)->get();
        $this->assertTrue($sermonsByPreacherX->contains($sermonByPreacherX));
        $this->assertFalse($sermonsByPreacherX->contains($sermonByPreacherY));
    }

    // /**
    //  * @test
    //  */
    // public function testHasPlayDate()
    // {
    //     $sermon = Sermon::factory()->create();
    //     $playDate = Carbon::create(2023, 5, 10);
    //     $otherDate = Carbon::create(2023, 5, 11);

    //     // Create a play date for the sermon
    //     // PlayDate::factory()->create([  // PlayDate is removed
    //     //     'sermon_id' => $sermon->id,
    //     //     'played_on' => $playDate,
    //     // ]);

    //     // Test with string date
    //     // $this->assertTrue($sermon->hasPlayDate($playDate->toDateString()));
    //     // $this->assertFalse($sermon->hasPlayDate($otherDate->toDateString()));

    //     // Test with Carbon instance
    //     // $this->assertTrue($sermon->hasPlayDate($playDate));
    //     // $this->assertFalse($sermon->hasPlayDate($otherDate));

    //     // Test for a sermon with no play dates at all
    //     // $sermonWithoutPlayDates = Sermon::factory()->create();
    //     // $this->assertFalse($sermonWithoutPlayDates->hasPlayDate($playDate));
    // }
}
