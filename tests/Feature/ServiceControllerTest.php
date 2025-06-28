<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Service;
use App\Models\Sermon;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;

class ServiceControllerTest extends TestCase
{
  use RefreshDatabase;

  protected $adminUser;
  protected $regularUser;
  protected $baseRoute = '/church/members/services';

  protected function setUp(): void
  {
    parent::setUp();
    $this->adminUser = \App\Models\User::factory()->admin()->create();
    $this->regularUser = \App\Models\User::factory()->create(['is_admin' => false]);

    // Define a gate for 'edit-sermons'
    \Illuminate\Support\Facades\Gate::define('edit-sermons', function ($user) {
      return $user->is_admin ?? false;
    });
  }

  // 1. Authentication/Authorization Tests
  #[Test]
  public function guests_cannot_access_service_management_routes()
  {
    $service = \App\Models\Service::factory()->create();

    $this->get($this->baseRoute)->assertRedirect('/login');
    $this->get("{$this->baseRoute}/create")->assertRedirect('/login');
    $this->post($this->baseRoute, [])->assertRedirect('/login');
    $this->get("{$this->baseRoute}/{$service->id}")->assertRedirect('/login');
    $this->get("{$this->baseRoute}/{$service->id}/edit")->assertRedirect('/login');
    $this->put("{$this->baseRoute}/{$service->id}", [])->assertRedirect('/login');
    $this->delete("{$this->baseRoute}/{$service->id}")->assertRedirect('/login');
  }

  #[Test]
  public function regular_users_are_forbidden_from_service_management_routes()
  {
    $this->actingAs($this->regularUser);
    $service = \App\Models\Service::factory()->create();

    $this->get($this->baseRoute)->assertForbidden();
    $this->get("{$this->baseRoute}/create")->assertForbidden();
    $this->post($this->baseRoute, [])->assertForbidden();
    $this->get("{$this->baseRoute}/{$service->id}")->assertForbidden();
    $this->get("{$this->baseRoute}/{$service->id}/edit")->assertForbidden();
    $this->put("{$this->baseRoute}/{$service->id}", [])->assertForbidden();
    $this->delete("{$this->baseRoute}/{$service->id}")->assertForbidden();
  }

  // 2. testServiceIndexPageLoads
  #[Test]
  public function service_index_page_loads_for_admin_users()
  {
    $service = \App\Models\Service::factory()->create();
    $response = $this->actingAs($this->adminUser)->get($this->baseRoute);
    $response->assertOk();
    $response->assertViewIs('services.index');
    $response->assertSee($service->formatted_name); // Use accessor
    $response->assertSee($service->date->format('Y-m-d')); // Check for date
  }

  // 3. testServiceCreatePageLoads
  #[Test]
  public function service_create_page_loads_for_admin_users()
  {
    $response = $this->actingAs($this->adminUser)->get("{$this->baseRoute}/create");
    $response->assertOk();
    $response->assertViewIs('services.create');
    // $response->assertSee('Create Service'); // This text might still be valid
  }

  // 4. testStoreNewService
  #[Test]
  public function admin_user_can_store_new_service()
  {
    $date = Carbon::now()->addWeek()->format('Y-m-d');
    $type = 'morning';
    $serviceData = [
      'date' => $date,
      'type' => $type,
      'video' => 'videos/new_video.mp4', // Assuming form takes these directly
      'audio' => 'audio/new_audio.mp3',   // Assuming form takes these directly
    ];

    $response = $this->actingAs($this->adminUser)->post($this->baseRoute, $serviceData);

    $this->assertDatabaseHas('services', [
        'date' => $date,
        'type' => $type,
        'video' => 'videos/new_video.mp4',
        'audio' => 'audio/new_audio.mp3',
    ]);
    $response->assertRedirect($this->baseRoute);
    $response->assertSessionHas('success');
  }

  #[Test]
  public function store_service_fails_with_invalid_data()
  {
    // Assuming 'date' and 'type' are required.
    $response = $this->actingAs($this->adminUser)->post($this->baseRoute, [
      'date' => '', // Date is required
      'type' => '', // Type is required
      'video' => 'not a valid path sometimes', // Depending on validation rules
      'audio' => '',
    ]);
    // Update expected errors based on actual validation rules for Service
    $response->assertSessionHasErrors(['date', 'type', 'audio', 'video']);
    $this->assertDatabaseCount('services', 0);
  }

  // 5. testServiceEditPageLoads
  #[Test]
  public function service_edit_page_loads_for_admin_users()
  {
    $service = \App\Models\Service::factory()->create();
    $response = $this->actingAs($this->adminUser)->get("{$this->baseRoute}/{$service->id}/edit");
    $response->assertOk();
    $response->assertViewIs('services.edit');
    $response->assertSee($service->formatted_name); // Use accessor
    $response->assertSee($service->date->format('Y-m-d'));
  }

  #[Test]
  public function service_edit_page_returns_404_for_non_existent_service()
  {
    $this->actingAs($this->adminUser)->get("{$this->baseRoute}/9999/edit")->assertNotFound();
  }

  // 6. testUpdateExistingService
  #[Test]
  public function admin_user_can_update_existing_service()
  {
    $service = \App\Models\Service::factory()->create(['type' => 'morning', 'date' => '2023-01-01']);
    $newDate = Carbon::now()->addMonth()->format('Y-m-d');
    $newType = 'evening';
    $updateData = [
      'date' => $newDate,
      'type' => $newType,
      'video' => 'videos/updated_video.mp4',
      'audio' => 'audio/updated_audio.mp3',
    ];

    $response = $this->actingAs($this->adminUser)->put("{$this->baseRoute}/{$service->id}", $updateData);

    $this->assertDatabaseHas('services', [
      'id' => $service->id,
      'date' => $newDate,
      'type' => $newType,
    ]);
    $response->assertRedirect($this->baseRoute);
    $response->assertSessionHas('success');
  }

  #[Test]
  public function update_service_fails_with_invalid_data()
  {
    $service = \App\Models\Service::factory()->create();
    $originalDate = $service->date->format('Y-m-d');
    $originalType = $service->type;

    $response = $this->actingAs($this->adminUser)->put("{$this->baseRoute}/{$service->id}", [
      'date' => '', // Date is required
      'type' => 'invalidtype', // Type is invalid
    ]);
    $response->assertSessionHasErrors(['date', 'type']);

    $updatedService = \App\Models\Service::find($service->id);
    $this->assertEquals($originalDate, $updatedService->date->format('Y-m-d'));
    $this->assertEquals($originalType, $updatedService->type);
  }

  // 7. testDestroyService
  #[Test]
  public function admin_user_can_destroy_service()
  {
    $service = \App\Models\Service::factory()->create();
    // Assuming Sermons are not cascade deleted but service_id is set to null
    // $sermon = \App\Models\Sermon::factory()->forService($service)->create();

    $response = $this->actingAs($this->adminUser)->delete("{$this->baseRoute}/{$service->id}");

    $this->assertDatabaseMissing('services', ['id' => $service->id]);
    // If sermons.service_id is nullable and set to null on delete:
    // $this->assertDatabaseHas('sermons', ['id' => $sermon->id, 'service_id' => null]);
    // If sermons are cascade deleted:
    // $this->assertDatabaseMissing('sermons', ['id' => $sermon->id]);

    $response->assertRedirect($this->baseRoute);
    $response->assertSessionHas('success');
  }

  #[Test]
  public function destroy_non_existent_service_returns_404()
  {
    $this->actingAs($this->adminUser)->delete("{$this->baseRoute}/9999")->assertNotFound();
  }
}
