<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

use App\Enums\MeetingFrequency;
use App\Livewire\Admin\Meetings\CreateMeeting;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingFrequencyIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
    }

    #[Test]
    public function it_allows_valid_frequency_values_at_database_level(): void
    {
        foreach (MeetingFrequency::values() as $value) {
            $meeting = Meeting::factory()->create([
                'frequency' => $value,
                'slug' => 'meeting-'.$value,
            ]);

            $this->assertEquals($value, $meeting->fresh()->frequency->value);
        }
    }

    #[Test]
    public function it_allows_null_frequency_for_non_recurring_meetings_at_database_level(): void
    {
        $meeting = Meeting::factory()->create([
            'is_recurring' => false,
            'frequency' => null,
            'slug' => 'meeting-null-non-recurring',
        ]);

        $this->assertNull($meeting->fresh()->frequency);
    }

    #[Test]
    public function it_rejects_null_frequency_for_recurring_meetings_at_database_level(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('meetings_recurring_frequency_check');

        DB::table('meetings')->insert([
            'slug' => 'recurring-null-freq',
            'type' => 'Adults',
            'day' => 'Monday',
            'who' => 'Anyone',
            'pictures' => false,
            'is_recurring' => true,
            'frequency' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_enforces_enum_constraint_at_database_level(): void
    {
        $this->expectException(QueryException::class);

        // Directly inserting via DB to bypass Eloquent/Enum casting if possible,
        // though Eloquent will also fail if it can't cast.
        // But the goal is to prove the DB constraint.
        DB::table('meetings')->insert([
            'slug' => 'invalid-freq',
            'type' => 'Adults',
            'day' => 'Monday',
            'who' => 'Anyone',
            'pictures' => false,
            'frequency' => 'invalid-value',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_validates_frequency_in_livewire_create(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreateMeeting::class)
            ->set('form.slug', 'new-meeting')
            ->set('form.type', 'Adults')
            ->set('form.day', 'Monday')
            ->set('form.who', 'Anyone')
            ->set('form.isRecurring', true)
            ->set('form.frequency', 'invalid-frequency')
            ->call('save')
            ->assertHasErrors(['form.frequency' => 'in']);
    }

    #[Test]
    public function it_requires_frequency_if_recurring_in_livewire_create(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreateMeeting::class)
            ->set('form.slug', 'new-meeting')
            ->set('form.type', 'Adults')
            ->set('form.day', 'Monday')
            ->set('form.who', 'Anyone')
            ->set('form.isRecurring', true)
            ->set('form.frequency', '')
            ->call('save')
            ->assertHasErrors(['form.frequency' => 'required_if']);
    }
}
