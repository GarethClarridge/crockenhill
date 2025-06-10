<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Sermon;
use App\Models\Service;
use App\Models\PlayDate;
use Database\Factories\SermonFactory;
use Database\Factories\ServiceFactory;
use Database\Factories\PlayDateFactory;
use Illuminate\Support\Str;
use Carbon\Carbon;
// Assuming Preacher and Series are not models for now, will adjust if needed.

class SermonTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function testSermonRelationships()
    {
        $service = Service::factory()->create();
        // Ensure SermonFactory is used correctly
        $sermon = Sermon::factory()->forService($service)->create();

        $this->assertInstanceOf(Service::class, $sermon->service);
        $this->assertEquals($service->id, $sermon->service->id);

        // Ensure PlayDateFactory is used correctly and sermon_id is passed
        $playDate1 = PlayDate::factory()->create(['sermon_id' => $sermon->id]);
        $playDate2 = PlayDate::factory()->create(['sermon_id' => $sermon->id]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $sermon->playDates);
        $this->assertCount(2, $sermon->playDates);
        $this->assertTrue($sermon->playDates->contains($playDate1));
        $this->assertTrue($sermon->playDates->contains($playDate2));
    }

    /**
     * @test
     */
    public function testSermonAccessors()
    {
        $date = Carbon::create(2023, 1, 15, 10, 0, 0);
        $sermon = Sermon::factory()->withDate($date)->create([
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

    /**
     * @test
     */
    public function testSermonMutatorsAndCasts()
    {
        // Test 'points' attribute casting to array
        $pointsArray = ['Point 1: Introduction', 'Point 2: Main Body', 'Point 3: Conclusion'];
        $pointsJson = json_encode($pointsArray);

        $sermonWithJsonPoints = Sermon::factory()->create(['points' => $pointsJson]);
        $this->assertIsArray($sermonWithJsonPoints->points);
        $this->assertEquals($pointsArray, $sermonWithJsonPoints->points);

        // Test 'date' attribute casting to Carbon instance
        $sermonWithDate = Sermon::factory()->create(['date' => '2023-03-15']);
        $this->assertInstanceOf(Carbon::class, $sermonWithDate->date);

        $sermonFromFactory = Sermon::factory()->create(); // Factory sets a date
        $this->assertInstanceOf(Carbon::class, $sermonFromFactory->date);
        // Check if points from factory are cast to array
        $this->assertIsArray($sermonFromFactory->points);
    }

    /**
     * @test
     */
    public function testSermonScopes()
    {
        // Test last12Months scope
        $withinLast12Months = Sermon::factory()->withDate(Carbon::now()->subMonths(6))->create();
        $olderThan12Months = Sermon::factory()->withDate(Carbon::now()->subMonths(15))->create();
        $futureSermon = Sermon::factory()->withDate(Carbon::now()->addMonths(2))->create(); // Should also be included

        $sermonsLast12Months = Sermon::last12Months()->get();
        $this->assertTrue($sermonsLast12Months->contains($withinLast12Months));
        $this->assertTrue($sermonsLast12Months->contains($futureSermon));
        $this->assertFalse($sermonsLast12Months->contains($olderThan12Months));

        // Test forService scope
        $service1 = Service::factory()->create();
        $service2 = Service::factory()->create();
        $sermonForService1 = Sermon::factory()->forService($service1)->create();
        $sermonForService2 = Sermon::factory()->forService($service2)->create();

        $sermonsForService1 = Sermon::forService($service1->id)->get();
        $this->assertTrue($sermonsForService1->contains($sermonForService1));
        $this->assertFalse($sermonsForService1->contains($sermonForService2));

        $sermonsForServiceByName = Sermon::forService($service1->name)->get();
        $this->assertTrue($sermonsForServiceByName->contains($sermonForService1));
        $this->assertFalse($sermonsForServiceByName->contains($sermonForService2));


        // Test inSeries scope
        $seriesATitle = 'The Beatitudes';
        $seriesBTitle = 'Fruit of the Spirit';
        $sermonInSeriesA = Sermon::factory()->inSeries($seriesATitle)->create();
        $sermonInSeriesB = Sermon::factory()->inSeries($seriesBTitle)->create();

        $sermonsInSeriesA = Sermon::inSeries($seriesATitle)->get();
        $this->assertTrue($sermonsInSeriesA->contains($sermonInSeriesA));
        $this->assertFalse($sermonsInSeriesA->contains($sermonInSeriesB));

        // Test byPreacher scope
        $preacherXName = 'Rev. Dr. Smith';
        $preacherYName = 'Pastor Jones';
        $sermonByPreacherX = Sermon::factory()->byPreacher($preacherXName)->create();
        $sermonByPreacherY = Sermon::factory()->byPreacher($preacherYName)->create();

        $sermonsByPreacherX = Sermon::byPreacher($preacherXName)->get();
        $this->assertTrue($sermonsByPreacherX->contains($sermonByPreacherX));
        $this->assertFalse($sermonsByPreacherX->contains($sermonByPreacherY));
    }

    /**
     * @test
     */
    public function testHasPlayDate()
    {
        $sermon = Sermon::factory()->create();
        $playDate = Carbon::create(2023, 5, 10);
        $otherDate = Carbon::create(2023, 5, 11);

        // Create a play date for the sermon
        PlayDate::factory()->create([
            'sermon_id' => $sermon->id,
            'played_on' => $playDate,
        ]);

        // Test with string date
        $this->assertTrue($sermon->hasPlayDate($playDate->toDateString()));
        $this->assertFalse($sermon->hasPlayDate($otherDate->toDateString()));

        // Test with Carbon instance
        $this->assertTrue($sermon->hasPlayDate($playDate));
        $this->assertFalse($sermon->hasPlayDate($otherDate));

        // Test for a sermon with no play dates at all
        $sermonWithoutPlayDates = Sermon::factory()->create();
        $this->assertFalse($sermonWithoutPlayDates->hasPlayDate($playDate));
    }
}
