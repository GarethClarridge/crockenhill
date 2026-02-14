<?php

namespace Tests\Feature;

use App\Livewire\Admin\Preachers\CreatePreacher;
use App\Livewire\Admin\Preachers\EditPreacher;
use App\Livewire\Admin\Preachers\ListPreachers;
use App\Models\Preacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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
}
