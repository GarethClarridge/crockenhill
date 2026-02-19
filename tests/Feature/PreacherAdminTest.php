<?php

namespace Tests\Feature;

use App\Contracts\SpeakerIdentificationInterface;
use App\Livewire\Admin\Preachers\CreatePreacher;
use App\Livewire\Admin\Preachers\EditPreacher;
use App\Livewire\Admin\Preachers\ListPreachers;
use App\Models\Preacher;
use App\Models\SpeakerProfile;
use App\Models\SpeakerSample;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PreacherAdminTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
    }

    #[Test]
    public function admin_can_view_preacher_list(): void
    {
        $this->actingAs($this->admin);

        Preacher::factory()->count(3)->create();

        $response = $this->get('/admin/preachers');
        $response->assertStatus(200);
    }

    #[Test]
    public function non_admin_cannot_view_preacher_list(): void
    {
        $user = User::factory()->create(['is_admin' => false, 'email_verified_at' => now()]);
        $this->actingAs($user);

        $response = $this->get('/admin/preachers');
        $response->assertStatus(403);
    }

    #[Test]
    public function admin_can_create_preacher(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreatePreacher::class)
            ->set('name', 'New Preacher')
            ->set('slug', 'new-preacher')
            ->call('save')
            ->assertRedirect(route('admin.preachers.index'));

        $this->assertDatabaseHas('preachers', ['name' => 'New Preacher', 'slug' => 'new-preacher']);
    }

    #[Test]
    public function admin_can_update_preacher(): void
    {
        $this->actingAs($this->admin);

        $preacher = Preacher::factory()->create(['name' => 'Old Name']);

        Livewire::test(EditPreacher::class, ['preacher' => $preacher])
            ->set('name', 'New Name')
            ->call('save')
            ->assertDispatched('notify');

        $this->assertDatabaseHas('preachers', ['id' => $preacher->id, 'name' => 'New Name']);
    }

    #[Test]
    public function admin_can_delete_preacher(): void
    {
        $this->actingAs($this->admin);

        $preacher = Preacher::factory()->create();

        Livewire::test(ListPreachers::class)
            ->call('delete', $preacher)
            ->assertDispatched('notify');

        $this->assertModelMissing($preacher);
    }

    #[Test]
    public function admin_can_add_alias_to_preacher(): void
    {
        $this->actingAs($this->admin);

        $preacher = Preacher::factory()->create();

        Livewire::test(EditPreacher::class, ['preacher' => $preacher])
            ->set('newAlias', 'My Test Alias')
            ->call('addAlias');

        $this->assertDatabaseHas('preacher_aliases', [
            'preacher_id' => $preacher->id,
            'alias' => 'my test alias',
        ]);
    }

    #[Test]
    public function admin_can_filter_sermons_needing_review(): void
    {
        $this->actingAs($this->admin);

        $needsReview = \App\Models\Sermon::factory()->create(['needs_preacher_review' => true, 'title' => 'Needs Review Sermon', 'date' => now()]);
        \App\Models\Sermon::factory()->create(['needs_preacher_review' => false, 'title' => 'Fine Sermon', 'date' => now()]);

        Livewire::test(\App\Livewire\Admin\Sermons\ListSermons::class)
            ->set('needsReviewFilter', true)
            ->assertSee('Needs Review Sermon')
            ->assertDontSee('Fine Sermon');
    }

    #[Test]
    public function admin_can_recompute_speaker_profile(): void
    {
        $this->actingAs($this->admin);

        $preacher = Preacher::factory()->create();
        $profile = SpeakerProfile::factory()->create(['preacher_id' => $preacher->id, 'is_active' => true]);
        SpeakerSample::factory()->approved()->count(3)->create(['speaker_profile_id' => $profile->id]);

        $this->mock(SpeakerIdentificationInterface::class, function (MockInterface $mock) use ($profile) {
            $mock->shouldReceive('updateProfile')
                ->once()
                ->with(\Mockery::on(fn ($p) => $p->id === $profile->id), \Mockery::type('array'))
                ->andReturn($profile);
        });

        Livewire::test(EditPreacher::class, ['preacher' => $preacher])
            ->call('recomputeProfile', $profile->id)
            ->assertDispatched('notify');
    }

    #[Test]
    public function recompute_fails_with_no_approved_samples(): void
    {
        $this->actingAs($this->admin);

        $preacher = Preacher::factory()->create();
        $profile = SpeakerProfile::factory()->create(['preacher_id' => $preacher->id, 'is_active' => true]);

        $this->mock(SpeakerIdentificationInterface::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('updateProfile');
        });

        Livewire::test(EditPreacher::class, ['preacher' => $preacher])
            ->call('recomputeProfile', $profile->id)
            ->assertDispatched('notify');

        // Profile centroid should be unchanged — no updateProfile call was made
    }

    #[Test]
    public function admin_cannot_recompute_another_preachers_profile(): void
    {
        $this->actingAs($this->admin);

        $preacher = Preacher::factory()->create();
        $otherPreacher = Preacher::factory()->create();
        $otherProfile = SpeakerProfile::factory()->create(['preacher_id' => $otherPreacher->id]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::test(EditPreacher::class, ['preacher' => $preacher])
            ->call('recomputeProfile', $otherProfile->id);
    }

    #[Test]
    public function admin_can_deactivate_speaker_profile(): void
    {
        $this->actingAs($this->admin);

        $preacher = Preacher::factory()->create();
        $profile = SpeakerProfile::factory()->create(['preacher_id' => $preacher->id, 'is_active' => true]);

        Livewire::test(EditPreacher::class, ['preacher' => $preacher])
            ->call('removeProfile', $profile->id)
            ->assertDispatched('notify');

        $this->assertDatabaseHas('speaker_profiles', ['id' => $profile->id, 'is_active' => false]);
    }

    #[Test]
    public function admin_cannot_deactivate_another_preachers_profile(): void
    {
        $this->actingAs($this->admin);

        $preacher = Preacher::factory()->create();
        $otherPreacher = Preacher::factory()->create();
        $otherProfile = SpeakerProfile::factory()->create(['preacher_id' => $otherPreacher->id, 'is_active' => true]);

        Livewire::test(EditPreacher::class, ['preacher' => $preacher])
            ->call('removeProfile', $otherProfile->id);

        // Profile must remain active — the where clause prevents cross-preacher updates
        $this->assertDatabaseHas('speaker_profiles', ['id' => $otherProfile->id, 'is_active' => true]);
    }
}
