<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

use App\Enums\MeetingType;
use App\Livewire\Admin\Meetings\CreateMeeting;
use App\Livewire\Admin\Meetings\EditMeeting;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingTimeIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
    }

    #[Test]
    public function it_enforces_start_and_end_time_integrity_at_database_level(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('meetings_time_check');

        // Attempt to create a meeting where end_time is before start_time
        Meeting::factory()->create([
            'start_time' => '11:00:00',
            'end_time' => '10:00:00',
        ]);
    }

    #[Test]
    public function it_allows_equal_start_and_end_times_at_database_level(): void
    {
        $meeting = Meeting::factory()->create([
            'start_time' => '10:00:00',
            'end_time' => '10:00:00',
        ]);

        $this->assertDatabaseHas('meetings', [
            'id' => $meeting->id,
            'start_time' => '10:00:00',
            'end_time' => '10:00:00',
        ]);
    }

    #[Test]
    public function it_validates_end_time_after_start_time_in_livewire_create(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreateMeeting::class)
            ->set('form.slug', 'test-meeting')
            ->set('form.type', MeetingType::Adults->value)
            ->set('form.day', 'Monday')
            ->set('form.who', 'Anyone')
            ->set('form.startTime', '11:00')
            ->set('form.endTime', '10:00')
            ->call('save')
            ->assertHasErrors(['form.endTime']);
    }

    #[Test]
    public function it_validates_end_time_after_start_time_in_livewire_edit(): void
    {
        $meeting = Meeting::factory()->create([
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
        ]);

        Livewire::actingAs($this->admin)
            ->test(EditMeeting::class, ['meeting' => $meeting])
            ->set('form.startTime', '12:00')
            ->set('form.endTime', '11:00')
            ->call('save')
            ->assertHasErrors(['form.endTime']);
    }
}
