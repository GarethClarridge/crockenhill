<?php

declare(strict_types=1);

namespace Tests\Feature\Warden;

use App\Models\Meeting;
use App\Enums\MeetingType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_trims_identity_columns_via_model_attributes(): void
    {
        $meeting = Meeting::factory()->create([
            'slug' => '  test-meeting  ',
            'day' => '  Monday  ',
            'who' => '  Everyone  ',
            'location' => '  Church Hall  ',
            'leaders_phone' => '  0123456789  ',
            'leaders_email' => '  test@example.com  ',
        ]);

        $this->assertEquals('test-meeting', $meeting->slug);
        $this->assertEquals('Monday', $meeting->day);
        $this->assertEquals('Everyone', $meeting->who);
        $this->assertEquals('Church Hall', $meeting->location);
        $this->assertEquals('0123456789', $meeting->leaders_phone);
        $this->assertEquals('test@example.com', $meeting->leaders_email);

        $dbMeeting = DB::table('meetings')->where('id', $meeting->id)->first();
        $this->assertEquals('test-meeting', $dbMeeting->slug);
        $this->assertEquals('Monday', $dbMeeting->day);
        $this->assertEquals('Everyone', $dbMeeting->who);
        $this->assertEquals('Church Hall', $dbMeeting->location);
        $this->assertEquals('0123456789', $dbMeeting->leaders_phone);
        $this->assertEquals('test@example.com', $dbMeeting->leaders_email);
    }

    #[Test]
    public function it_converts_empty_strings_to_null_for_nullable_columns(): void
    {
        $meeting = Meeting::factory()->create([
            'location' => '   ',
            'leaders_phone' => '',
            'leaders_email' => null,
        ]);

        $this->assertNull($meeting->location);
        $this->assertNull($meeting->leaders_phone);
        $this->assertNull($meeting->leaders_email);

        $dbMeeting = DB::table('meetings')->where('id', $meeting->id)->first();
        $this->assertNull($dbMeeting->location);
        $this->assertNull($dbMeeting->leaders_phone);
        $this->assertNull($dbMeeting->leaders_email);
    }

    #[Test]
    public function it_rejects_untrimmed_day_at_database_level(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessage('meetings_day_format_check');

        DB::table('meetings')->insert([
            'slug' => 'test-untrimmed-day',
            'type' => MeetingType::Adults->value,
            'day' => ' Monday ',
            'who' => 'Everyone',
            'pictures' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_rejects_empty_who_at_database_level(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessage('meetings_who_format_check');

        DB::table('meetings')->insert([
            'slug' => 'test-empty-who',
            'type' => MeetingType::Adults->value,
            'day' => 'Monday',
            'who' => '',
            'pictures' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_rejects_untrimmed_location_at_database_level(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessage('meetings_location_format_check');

        DB::table('meetings')->insert([
            'slug' => 'test-location',
            'type' => MeetingType::Adults->value,
            'day' => 'Monday',
            'who' => 'Everyone',
            'location' => ' untrimmed ',
            'pictures' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_enforces_unique_slug_at_database_level(): void
    {
        Meeting::factory()->create(['slug' => 'unique-slug']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        // MySQL error for unique constraint violation usually mentions 'Duplicate entry'
        $this->expectExceptionMessage('Duplicate entry');

        DB::table('meetings')->insert([
            'slug' => 'unique-slug',
            'type' => MeetingType::Adults->value,
            'day' => 'Tuesday',
            'who' => 'Everyone',
            'pictures' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
