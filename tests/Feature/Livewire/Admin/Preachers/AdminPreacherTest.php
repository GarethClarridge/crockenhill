<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin\Preachers;

use App\Contracts\SpeakerIdentificationInterface;
use App\Livewire\Admin\Preachers\CreatePreacher;
use App\Livewire\Admin\Preachers\EditPreacher;
use App\Livewire\Admin\Preachers\ListPreachers;
use App\Models\Preacher;
use App\Models\PreacherAlias;
use App\Models\SpeakerProfile;
use App\Models\SpeakerSample;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminPreacherTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    #[Test]
    public function it_validates_alias_uniqueness_after_normalization_in_edit_component(): void
    {
        $preacher1 = Preacher::factory()->create();
        $preacher2 = Preacher::factory()->create();

        PreacherAlias::create([
            'preacher_id' => $preacher1->id,
            'alias' => 'normalized-alias',
        ]);

        $this->actingAs($this->admin);

        Livewire::test(EditPreacher::class, ['preacher' => $preacher2])
            ->set('newAlias', '  NORMALIZED-ALIAS  ')
            ->call('addAlias')
            ->assertHasErrors(['newAlias' => 'unique']);
    }

    #[Test]
    public function list_preachers_component_renders_successfully_for_admin(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListPreachers::class)
            ->assertStatus(200)
            ->assertSee('Preachers');
    }

    #[Test]
    public function list_preachers_route_forbids_non_admins(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->get(route('admin.preachers.index'))
            ->assertForbidden();
    }

    #[Test]
    public function it_can_search_preachers_by_name(): void
    {
        Preacher::factory()->create(['name' => 'Charles Spurgeon']);
        Preacher::factory()->create(['name' => 'John Calvin']);

        $this->actingAs($this->admin);

        Livewire::test(ListPreachers::class)
            ->set('search', 'Charles')
            ->assertSee('Charles Spurgeon')
            ->assertDontSee('John Calvin');
    }

    #[Test]
    public function it_can_filter_preachers_by_active_status(): void
    {
        Preacher::factory()->create(['name' => 'Active Preacher', 'is_active' => true]);
        Preacher::factory()->create(['name' => 'Inactive Preacher', 'is_active' => false]);

        $this->actingAs($this->admin);

        Livewire::test(ListPreachers::class)
            ->set('activeFilter', true)
            ->assertSee('Active Preacher')
            ->assertDontSee('Inactive Preacher')
            ->set('activeFilter', false)
            ->assertSee('Inactive Preacher')
            ->assertDontSee('Active Preacher');
    }

    #[Test]
    public function it_can_sort_preachers(): void
    {
        Preacher::factory()->create(['name' => 'Zebra', 'is_active' => true]);
        Preacher::factory()->create(['name' => 'Alpha', 'is_active' => true]);

        $this->actingAs($this->admin);

        Livewire::test(ListPreachers::class)
            ->set('sortBy', 'name')
            ->set('sortDirection', 'asc')
            ->assertSeeInOrder(['Alpha', 'Zebra'])
            ->set('sortDirection', 'desc')
            ->assertSeeInOrder(['Zebra', 'Alpha']);
    }

    #[Test]
    public function it_can_delete_a_preacher(): void
    {
        $preacher = Preacher::factory()->create();

        $this->actingAs($this->admin);

        Livewire::test(ListPreachers::class)
            ->call('delete', $preacher)
            ->assertDispatched('notify', type: 'success', message: 'Preacher deleted');

        $this->assertModelMissing($preacher);
    }

    #[Test]
    public function create_preacher_validation_rules(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreatePreacher::class)
            ->set('name', '')
            ->set('slug', '')
            ->call('save')
            ->assertHasErrors(['name', 'slug']);
    }

    #[Test]
    public function it_generates_slug_automatically_during_creation(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreatePreacher::class)
            ->set('name', 'New Preacher')
            ->assertSet('slug', 'new-preacher');
    }

    #[Test]
    public function it_can_create_a_preacher(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreatePreacher::class)
            ->set('name', 'Alistair Begg')
            ->set('bio', 'Truth For Life')
            ->set('isActive', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.preachers.index'));

        $this->assertDatabaseHas('preachers', [
            'name' => 'Alistair Begg',
            'slug' => 'alistair-begg',
            'bio' => 'Truth For Life',
            'is_active' => true,
        ]);
    }

    #[Test]
    public function edit_preacher_component_renders_with_preacher_data(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Martyn Lloyd-Jones', 'bio' => 'The Doctor']);

        $this->actingAs($this->admin);

        Livewire::test(EditPreacher::class, ['preacher' => $preacher])
            ->assertSet('name', 'Martyn Lloyd-Jones')
            ->assertSet('bio', 'The Doctor');
    }

    #[Test]
    public function it_can_update_a_preacher(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Old Name']);

        $this->actingAs($this->admin);

        Livewire::test(EditPreacher::class, ['preacher' => $preacher])
            ->set('name', 'New Name')
            ->call('save')
            ->assertDispatched('notify', type: 'success', message: 'Preacher updated');

        $this->assertEquals('New Name', $preacher->fresh()->name);
    }

    #[Test]
    public function it_can_manage_aliases(): void
    {
        $preacher = Preacher::factory()->create();

        $this->actingAs($this->admin);

        // Add alias
        Livewire::test(EditPreacher::class, ['preacher' => $preacher])
            ->set('newAlias', 'Spurgeon')
            ->call('addAlias')
            ->assertSet('newAlias', '');

        $this->assertDatabaseHas('preacher_aliases', [
            'preacher_id' => $preacher->id,
            'alias' => 'spurgeon',
        ]);

        $alias = PreacherAlias::where('alias', 'spurgeon')->first();

        // Remove alias
        Livewire::test(EditPreacher::class, ['preacher' => $preacher])
            ->call('removeAlias', $alias->id);

        $this->assertModelMissing($alias);
    }

    #[Test]
    public function it_validates_alias_uniqueness_in_edit_component(): void
    {
        $preacher1 = Preacher::factory()->create();
        $preacher2 = Preacher::factory()->create();

        PreacherAlias::create([
            'preacher_id' => $preacher1->id,
            'alias' => 'duplicate-alias',
        ]);

        $this->actingAs($this->admin);

        Livewire::test(EditPreacher::class, ['preacher' => $preacher2])
            ->set('newAlias', 'duplicate-alias')
            ->call('addAlias')
            ->assertHasErrors(['newAlias' => 'unique']);
    }

    #[Test]
    public function it_validates_alias_max_length_in_edit_component(): void
    {
        $preacher = Preacher::factory()->create();

        $this->actingAs($this->admin);

        Livewire::test(EditPreacher::class, ['preacher' => $preacher])
            ->set('newAlias', str_repeat('a', 256))
            ->call('addAlias')
            ->assertHasErrors(['newAlias' => 'max']);
    }

    #[Test]
    public function it_can_deactivate_a_speaker_profile(): void
    {
        $preacher = Preacher::factory()->create();
        $profile = SpeakerProfile::factory()->create(['preacher_id' => $preacher->id, 'is_active' => true]);

        $this->actingAs($this->admin);

        Livewire::test(EditPreacher::class, ['preacher' => $preacher])
            ->call('removeProfile', $profile->id)
            ->assertDispatched('notify', type: 'success');

        $this->assertFalse($profile->fresh()->is_active);
    }

    #[Test]
    public function it_can_recompute_a_speaker_profile(): void
    {
        $preacher = Preacher::factory()->create();
        $profile = SpeakerProfile::factory()->create(['preacher_id' => $preacher->id]);
        SpeakerSample::factory()->create([
            'speaker_profile_id' => $profile->id,
            'approved' => true,
            'embedding' => [0.1, 0.2, 0.3],
        ]);

        $mock = Mockery::mock(SpeakerIdentificationInterface::class);
        $mock->shouldReceive('updateProfile')
            ->once()
            ->with(Mockery::on(fn ($p) => $p->id === $profile->id), [[0.1, 0.2, 0.3]])
            ->andReturn($profile);

        $this->app->instance(SpeakerIdentificationInterface::class, $mock);

        $this->actingAs($this->admin);

        Livewire::test(EditPreacher::class, ['preacher' => $preacher])
            ->call('recomputeProfile', $profile->id)
            ->assertDispatched('notify', type: 'success');
    }

    #[Test]
    public function it_shows_error_when_recomputing_profile_with_no_approved_samples(): void
    {
        $preacher = Preacher::factory()->create();
        $profile = SpeakerProfile::factory()->create(['preacher_id' => $preacher->id]);

        $this->actingAs($this->admin);

        Livewire::test(EditPreacher::class, ['preacher' => $preacher])
            ->call('recomputeProfile', $profile->id)
            ->assertDispatched('notify', type: 'error', message: 'No approved samples to recompute from.');
    }

    #[Test]
    public function it_enforces_admin_authorization_internally(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $preacher = Preacher::factory()->create();

        $this->actingAs($user);

        // mount() should fail for ListPreachers
        Livewire::test(ListPreachers::class)
            ->assertForbidden();

        // mount() should fail for CreatePreacher
        Livewire::test(CreatePreacher::class)
            ->assertForbidden();

        // mount() should fail for EditPreacher
        Livewire::test(EditPreacher::class, ['preacher' => $preacher])
            ->assertForbidden();
    }
}
