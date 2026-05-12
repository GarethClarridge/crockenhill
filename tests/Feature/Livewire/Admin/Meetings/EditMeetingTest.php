<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin\Meetings;

use App\Livewire\Admin\Meetings\EditMeeting;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EditMeetingTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->crockenhillAdmin()->create(['is_admin' => true]);
    }

    #[Test]
    public function it_relies_on_route_middleware_for_access_control(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $meeting = Meeting::factory()->create();

        $this->actingAs($user);

        // Route middleware (auth, verified, admin) enforces access at the HTTP layer.
        // AdminLivewireAuthorizationTest covers this. Direct component mount is unrestricted.
        Livewire::test(EditMeeting::class, ['meeting' => $meeting])
            ->assertOk();
    }

    #[Test]
    public function it_renders_successfully_for_admin(): void
    {
        $meeting = Meeting::factory()->create();
        $this->actingAs($this->admin);

        Livewire::test(EditMeeting::class, ['meeting' => $meeting])
            ->assertStatus(200)
            ->assertSee('Edit Meeting')
            ->assertSet('form.slug', $meeting->slug);
    }

    #[Test]
    public function it_can_update_a_meeting(): void
    {
        $meeting = Meeting::factory()->create(['slug' => 'old-slug', 'who' => 'Old Who']);
        $this->actingAs($this->admin);

        Log::shouldReceive('warning')
            ->once()
            ->with('Meeting updated by admin', \Mockery::on(function ($args) use ($meeting) {
                return $args['admin_id'] === $this->admin->id &&
                       $args['meeting_id'] === $meeting->id &&
                       $args['slug'] === 'new-slug';
            }));

        Livewire::test(EditMeeting::class, ['meeting' => $meeting])
            ->set('form.slug', 'new-slug')
            ->set('form.who', 'New Who')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('meetings', [
            'id' => $meeting->id,
            'slug' => 'new-slug',
            'who' => 'New Who',
        ]);
    }

    #[Test]
    public function it_validates_unique_slug_ignoring_self(): void
    {
        $meeting = Meeting::factory()->create(['slug' => 'original-slug']);
        $this->actingAs($this->admin);

        Livewire::test(EditMeeting::class, ['meeting' => $meeting])
            ->set('form.slug', 'original-slug')
            ->call('save')
            ->assertHasNoErrors();
    }

    #[Test]
    public function it_validates_unique_slug_against_others(): void
    {
        $meeting1 = Meeting::factory()->create(['slug' => 'slug-one']);
        $meeting2 = Meeting::factory()->create(['slug' => 'slug-two']);
        $this->actingAs($this->admin);

        Livewire::test(EditMeeting::class, ['meeting' => $meeting1])
            ->set('form.slug', 'slug-two')
            ->call('save')
            ->assertHasErrors(['form.slug' => 'unique']);
    }
}
