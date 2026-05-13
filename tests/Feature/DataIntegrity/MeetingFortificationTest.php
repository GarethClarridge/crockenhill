<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

use App\Models\Meeting;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingFortificationTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_enforces_trimmed_day_at_database_level()
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('meetings_day_format_check');

        Meeting::query()->insert([
            'slug' => 'test-meeting',
            'type' => 'Adults',
            'day' => ' Monday ',
            'who' => 'Everyone',
            'pictures' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_enforces_non_empty_day_at_database_level()
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('meetings_day_format_check');

        Meeting::query()->insert([
            'slug' => 'test-meeting',
            'type' => 'Adults',
            'day' => '',
            'who' => 'Everyone',
            'pictures' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_enforces_trimmed_who_at_database_level()
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('meetings_who_format_check');

        Meeting::query()->insert([
            'slug' => 'test-meeting',
            'type' => 'Adults',
            'day' => 'Monday',
            'who' => ' Everyone ',
            'pictures' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_enforces_trimmed_location_at_database_level()
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('meetings_location_format_check');

        Meeting::query()->insert([
            'slug' => 'test-meeting',
            'type' => 'Adults',
            'day' => 'Monday',
            'who' => 'Everyone',
            'location' => ' Church ',
            'pictures' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_enforces_lowercased_trimmed_email_at_database_level()
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('meetings_leaders_email_format_check');

        Meeting::query()->insert([
            'slug' => 'test-meeting',
            'type' => 'Adults',
            'day' => 'Monday',
            'who' => 'Everyone',
            'leaders_email' => ' Test@Example.Com ',
            'pictures' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_automatically_trims_values_via_attribute_setters()
    {
        $meeting = Meeting::factory()->create([
            'day' => ' Monday ',
            'who' => ' Everyone ',
            'location' => ' Church ',
            'leaders_phone' => ' 1234567890 ',
            'leaders_email' => ' Test@Example.Com ',
        ]);

        $this->assertEquals('Monday', $meeting->day);
        $this->assertEquals('Everyone', $meeting->who);
        $this->assertEquals('Church', $meeting->location);
        $this->assertEquals('1234567890', $meeting->leaders_phone);
        $this->assertEquals('test@example.com', $meeting->leaders_email);
    }

    #[Test]
    public function it_converts_empty_strings_to_null_for_optional_fields()
    {
        $meeting = Meeting::factory()->create([
            'location' => '   ',
            'leaders_phone' => '   ',
            'leaders_email' => '   ',
        ]);

        $this->assertNull($meeting->location);
        $this->assertNull($meeting->leaders_phone);
        $this->assertNull($meeting->leaders_email);
    }
}
