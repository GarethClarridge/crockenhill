<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Meeting;
use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingValidationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_validates_time_formats(): void
    {
        $rules = Meeting::validationRules();

        $this->assertTrue(Validator::make(['start_time' => '10:30'], ['start_time' => $rules['start_time']])->passes());
        $this->assertTrue(Validator::make(['start_time' => '10:30:00'], ['start_time' => $rules['start_time']])->passes());
        $this->assertFalse(Validator::make(['start_time' => '10:30 AM'], ['start_time' => $rules['start_time']])->passes());
    }

    #[Test]
    public function it_ensures_end_time_is_after_or_equal_to_start_time(): void
    {
        $rules = Meeting::validationRules();

        $this->assertTrue(Validator::make(
            ['start_time' => '10:00', 'end_time' => '11:00'],
            ['start_time' => $rules['start_time'], 'end_time' => $rules['end_time']]
        )->passes());

        $this->assertTrue(Validator::make(
            ['start_time' => '10:00', 'end_time' => '10:00'],
            ['start_time' => $rules['start_time'], 'end_time' => $rules['end_time']]
        )->passes());

        $this->assertFalse(Validator::make(
            ['start_time' => '10:00', 'end_time' => '09:00'],
            ['start_time' => $rules['start_time'], 'end_time' => $rules['end_time']]
        )->passes());
    }

    #[Test]
    public function it_validates_boolean_fields(): void
    {
        $rules = Meeting::validationRules();

        $this->assertTrue(Validator::make(['pictures' => true], ['pictures' => $rules['pictures']])->passes());
        $this->assertTrue(Validator::make(['pictures' => 0], ['pictures' => $rules['pictures']])->passes());
        $this->assertFalse(Validator::make(['pictures' => 'yes'], ['pictures' => $rules['pictures']])->passes());

    }

    #[Test]
    public function it_validates_page_id_uniqueness_and_existence(): void
    {
        $page = Page::factory()->create();
        $otherPage = Page::factory()->create();
        $meeting = Meeting::factory()->create(['page_id' => $page->id]);

        $rules = Meeting::validationRules();

        // Valid: existing page_id that is not used
        $this->assertTrue(Validator::make(['page_id' => $otherPage->id], ['page_id' => $rules['page_id']])->passes());

        // Invalid: page_id that does not exist
        $this->assertFalse(Validator::make(['page_id' => 999], ['page_id' => $rules['page_id']])->passes());

        // Invalid: page_id that is already used by another meeting
        $this->assertFalse(Validator::make(['page_id' => $page->id], ['page_id' => $rules['page_id']])->passes());

        // Valid: ignoring current meeting's page_id
        $updateRules = Meeting::validationRules($meeting);
        $this->assertTrue(Validator::make(['page_id' => $page->id], ['page_id' => $updateRules['page_id']])->passes());
    }
}
