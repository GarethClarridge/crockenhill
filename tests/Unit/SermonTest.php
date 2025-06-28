<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Sermon;
use App\Models\Service;
// use App\Models\PlayDate; // PlayDate model is removed
// SermonFactory and ServiceFactory not explicitly used with Model::factory()
// use Database\Factories\PlayDateFactory; // PlayDateFactory is removed
use Illuminate\Support\Str;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test; // Added import
// Assuming Preacher and Series are not models for now, will adjust if needed.

class SermonTest extends TestCase
{
    use RefreshDatabase;

    #[Test] // Replaced @test
    public function testSermonRelationships()
    {
        // $serviceModel = \App\Models\Service::factory()->create(); // Not a direct relationship anymore
        $sermon = \App\Models\Sermon::factory()->create([
            'service' => 'morning' // Example enum value
        ]);

        $this->assertIsString($sermon->service);
        $this->assertContains($sermon->service, ['morning', 'evening']);
        // $this->assertEquals($serviceModel->id, $sermon->service->id); // This was incorrect

        // PlayDate related assertions are removed as PlayDate feature is removed.
        // $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $sermon->playDates);
        // $this->assertCount(2, $sermon->playDates);
        // $this->assertTrue($sermon->playDates->contains($playDate1));
        // $this->assertTrue($sermon->playDates->contains($playDate2));
    }

    #[Test] // Replaced @test
    public function testSermonAccessors()
    {
        $date = Carbon::create(2023, 1, 15, 10, 0, 0);
        $sermon = \App\Models\Sermon::factory()->withDate($date)->create([ // Explicitly use App\Models
            'series' => 'My Sermon Series',
            'preacher' => 'John Doe',
            'audio_url' => 'sermons/audio.mp3',
        ]);

        // Test getHumanDateAttribute
        $this->assertEquals($date->format('F j, Y'), $sermon->human_date);

        // Test getAudioUrlAttribute - Assuming it returns a URL based on the stored path
        // This might need adjustment based on how audio_url is actually stored and retrieved
        $this->assertEquals(url('sermons/audio.mp3'), $sermon->audio_url);

        // Test getSeriesUrlAttribute
        // Assuming the Sermon model has a getSeriesUrlAttribute accessor
        $expectedSeriesUrl = '/series/' . Str::slug('My Sermon Series');
        $this->assertEquals($expectedSeriesUrl, $sermon->series_url);
        // If the accessor is not yet implemented, this test will guide its creation.

        // Test getPreacherUrlAttribute
        // Assuming the Sermon model has a getPreacherUrlAttribute accessor
        $expectedPreacherUrl = '/preachers/' . Str::slug('John Doe');
        $this->assertEquals($expectedPreacherUrl, $sermon->preacher_url);
        // If the accessor is not yet implemented, this test will guide its creation.
    }

    #[Test] // Replaced @test
    public function testSermonMutatorsAndCasts()
    {
        // Test 'points' attribute casting to array
        $pointsArray = ['Point 1: Introduction', 'Point 2: Main Body', 'Point 3: Conclusion'];
        $pointsJson = json_encode($pointsArray);

        $sermonInstance = \App\Models\Sermon::factory()->create(['points' => $pointsJson]); // Explicitly use App\Models
        $sermonWithJsonPoints = $sermonInstance->fresh(); // Re-fetch from DB to ensure casts are applied
        $this->assertIsArray($sermonWithJsonPoints->points);
        $this->assertEquals($pointsArray, $sermonWithJsonPoints->points);

        // Test 'date' attribute casting to Carbon instance
        $sermonWithDate = \App\Models\Sermon::factory()->create(['date' => '2023-03-15']); // Explicitly use App\Models
        $this->assertInstanceOf(Carbon::class, $sermonWithDate->date);

        $sermonFromFactory = \App\Models\Sermon::factory()->create(); // Factory sets a date, Explicitly use App\Models
        $this->assertInstanceOf(Carbon::class, $sermonFromFactory->date);
        // Check if points from factory are cast to array
        $this->assertIsArray($sermonFromFactory->points);
    }

    #[Test] // Replaced @test
    public function testSermonScopes()
    {
        // Test last12Months scope
        $withinLast12Months = \App\Models\Sermon::factory()->withDate(Carbon::now()->subMonths(6))->create(); // Explicitly use App\Models
        $olderThan12Months = \App\Models\Sermon::factory()->withDate(Carbon::now()->subMonths(15))->create(); // Explicitly use App\Models
        $futureSermon = \App\Models\Sermon::factory()->withDate(Carbon::now()->addMonths(2))->create(); // Should also be included, Explicitly use App\Models

        $sermonsLast12Months = \App\Models\Sermon::last12Months()->get(); // Explicitly use App\Models
        $this->assertTrue($sermonsLast12Months->contains($withinLast12Months));
        $this->assertTrue($sermonsLast12Months->contains($futureSermon));
        $this->assertFalse($sermonsLast12Months->contains($olderThan12Months));

        // Test forService scope
        $service1 = \App\Models\Service::factory()->create(); // Explicitly use App\Models
        $service2 = \App\Models\Service::factory()->create(); // Explicitly use App\Models
        $sermonForService1 = \App\Models\Sermon::factory()->forService($service1)->create(); // Explicitly use App\Models
        $sermonForService2 = \App\Models\Sermon::factory()->forService($service2)->create(); // Explicitly use App\Models

        $sermonsForService1 = \App\Models\Sermon::forService($service1->id)->get(); // Explicitly use App\Models
        $this->assertTrue($sermonsForService1->contains($sermonForService1));
        $this->assertFalse($sermonsForService1->contains($sermonForService2));

        $sermonsForServiceByName = \App\Models\Sermon::forService($service1->name)->get(); // Explicitly use App\Models
        $this->assertTrue($sermonsForServiceByName->contains($sermonForService1));
        $this->assertFalse($sermonsForServiceByName->contains($sermonForService2));


        // Test inSeries scope
        $seriesATitle = 'The Beatitudes';
        $seriesBTitle = 'Fruit of the Spirit';
        $sermonInSeriesA = \App\Models\Sermon::factory()->inSeries($seriesATitle)->create(); // Explicitly use App\Models
        $sermonInSeriesB = \App\Models\Sermon::factory()->inSeries($seriesBTitle)->create(); // Explicitly use App\Models

        $sermonsInSeriesA = \App\Models\Sermon::inSeries($seriesATitle)->get(); // Explicitly use App\Models
        $this->assertTrue($sermonsInSeriesA->contains($sermonInSeriesA));
        $this->assertFalse($sermonsInSeriesA->contains($sermonInSeriesB));

        // Test byPreacher scope
        $preacherXName = 'Rev. Dr. Smith';
        $preacherYName = 'Pastor Jones';
        $sermonByPreacherX = \App\Models\Sermon::factory()->byPreacher($preacherXName)->create(); // Explicitly use App\Models
        $sermonByPreacherY = \App\Models\Sermon::factory()->byPreacher($preacherYName)->create(); // Explicitly use App\Models

        $sermonsByPreacherX = \App\Models\Sermon::byPreacher($preacherXName)->get(); // Explicitly use App\Models
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
