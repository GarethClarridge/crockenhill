<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Admin\Sermons\ListSermons;
use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminSermonTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->crockenhillAdmin()->create(['is_admin' => true]);
    }

    #[Test]
    public function it_renders_successfully()
    {
        $this->actingAs($this->admin);
        
        Livewire::test(ListSermons::class)
            ->assertStatus(200)
            ->assertSee('Sermons');
    }

    #[Test]
    public function it_requires_admin_to_view()
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user);

        Livewire::test(ListSermons::class)
            ->assertForbidden();
    }

    #[Test]
    public function it_can_search_sermons()
    {
        $this->actingAs($this->admin);
        
        Sermon::factory()->create(['title' => 'Searchable Title', 'date' => now()]);
        Sermon::factory()->create(['title' => 'Other Sermon', 'date' => now()]);

        Livewire::test(ListSermons::class)
            ->set('search', 'Searchable')
            ->assertSee('Searchable Title')
            ->assertDontSee('Other Sermon');
    }

    #[Test]
    public function it_can_filter_by_preacher()
    {
        $this->actingAs($this->admin);
        
        Sermon::factory()->create(['preacher' => 'John Doe', 'title' => 'Sermon A', 'date' => now()]);
        Sermon::factory()->create(['preacher' => 'Jane Smith', 'title' => 'Sermon B', 'date' => now()]);

        Livewire::test(ListSermons::class)
            ->set('preacherFilter', 'John Doe')
            ->assertSee('Sermon A')
            ->assertDontSee('Sermon B');
    }

    #[Test]
    public function it_can_delete_a_sermon()
    {
        $this->actingAs($this->admin);
        
        $sermon = Sermon::factory()->create();

        Livewire::test(ListSermons::class)
            ->call('delete', $sermon)
            ->assertDispatched('notify');

        $this->assertModelMissing($sermon);
    }
}
