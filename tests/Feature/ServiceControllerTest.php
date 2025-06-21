<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Service;
use App\Models\Sermon; // For testing relationship implications
use Database\Factories\UserFactory;
use Database\Factories\ServiceFactory;
use Database\Factories\SermonFactory;

class ServiceControllerTest extends TestCase
{
  use RefreshDatabase;

  protected $adminUser;
  protected $regularUser;

  protected function setUp(): void
  {
    parent::setUp();
    $this->adminUser = \App\Models\User::factory()->admin()->create();
    $this->regularUser = \App\Models\User::factory()->create();
  }

  // 1. Authentication/Authorization Tests
  /** @test */
  public function guests_cannot_access_service_management_routes()
  {
    $service = \App\Models\Service::factory()->create();

    $this->get('/services')->assertRedirect('/login');
    $this->get('/services/create')->assertRedirect('/login');
    $this->post('/services', [])->assertRedirect('/login');
    $this->get("/services/{$service->id}")->assertRedirect('/login'); // Assuming show is admin only too
    $this->get("/services/{$service->id}/edit")->assertRedirect('/login');
    $this->put("/services/{$service->id}", [])->assertRedirect('/login');
    $this->delete("/services/{$service->id}")->assertRedirect('/login');
  }

  /** @test */
  public function regular_users_are_forbidden_from_service_management_routes()
  {
    $this->actingAs($this->regularUser);
    $service = \App\Models\Service::factory()->create();

    $this->get('/services')->assertForbidden();
    $this->get('/services/create')->assertForbidden();
    $this->post('/services', [])->assertForbidden();
    $this->get("/services/{$service->id}")->assertForbidden(); // Show page
    $this->get("/services/{$service->id}/edit")->assertForbidden();
    $this->put("/services/{$service->id}", [])->assertForbidden();
    $this->delete("/services/{$service->id}")->assertForbidden();
  }

  // 2. testServiceIndexPageLoads
  /** @test */
  public function service_index_page_loads_for_admin_users()
  {
    \App\Models\Service::factory()->count(3)->create();
    $response = $this->actingAs($this->adminUser)->get('/services');
    $response->assertOk();
    $response->assertViewIs('services.index'); // Assuming a view name
    // $response->assertSee(Service::first()->name);
  }

  // 3. testServiceCreatePageLoads
  /** @test */
  public function service_create_page_loads_for_admin_users()
  {
    $response = $this->actingAs($this->adminUser)->get('/services/create');
    $response->assertOk();
    $response->assertViewIs('services.create'); // Assuming a view name
    // $response->assertSee('Create Service');
  }

  // 4. testStoreNewService
  /** @test */
  public function admin_user_can_store_new_service()
  {
    $serviceData = [
      'name' => 'Sunday Morning Worship',
      'description' => 'Our main weekly gathering.',
      'service_time' => '10:30:00',
      'is_active' => true,
    ];

    $response = $this->actingAs($this->adminUser)->post('/services', $serviceData);

    $this->assertDatabaseHas('services', ['name' => 'Sunday Morning Worship', 'service_time' => '10:30:00']);
    $response->assertRedirect('/services'); // Or to the new service's page
    $response->assertSessionHas('success');
  }

  /** @test */
  public function store_service_fails_with_invalid_data()
  {
    $response = $this->actingAs($this->adminUser)->post('/services', [
      'name' => '', // Name is required
    ]);
    $response->assertSessionHasErrors('name');
    $this->assertDatabaseMissing('services', ['description' => 'Missing name test']);
  }

  // No public show page test, as assumed admin-only above.
  // If a public show page exists for services, it should be tested separately.

  // 5. testServiceEditPageLoads
  /** @test */
  public function service_edit_page_loads_for_admin_users()
  {
    $service = \App\Models\Service::factory()->create();
    $response = $this->actingAs($this->adminUser)->get("/services/{$service->id}/edit");
    $response->assertOk();
    $response->assertViewIs('services.edit');
    $response->assertSee($service->name);
  }

  /** @test */
  public function service_edit_page_returns_404_for_non_existent_service()
  {
    $this->actingAs($this->adminUser)->get('/services/9999/edit')->assertNotFound();
  }

  // 6. testUpdateExistingService
  /** @test */
  public function admin_user_can_update_existing_service()
  {
    $service = \App\Models\Service::factory()->create(['name' => 'Old Name', 'is_active' => true]);
    $updateData = [
      'name' => 'New Service Name',
      'description' => 'Updated description.',
      'service_time' => '11:00:00',
      'is_active' => false,
    ];

    $response = $this->actingAs($this->adminUser)->put("/services/{$service->id}", $updateData);

    $this->assertDatabaseHas('services', [
      'id' => $service->id,
      'name' => 'New Service Name',
      'is_active' => false
    ]);
    $response->assertRedirect('/services');
    $response->assertSessionHas('success');
  }

  /** @test */
  public function update_service_fails_with_invalid_data()
  {
    $service = \App\Models\Service::factory()->create();
    $originalName = $service->name;
    $response = $this->actingAs($this->adminUser)->put("/services/{$service->id}", [
      'name' => '', // Name is required
    ]);
    $response->assertSessionHasErrors('name');
    $this->assertEquals($originalName, \App\Models\Service::find($service->id)->name);
  }

  // 7. testDestroyService
  /** @test */
  public function admin_user_can_destroy_service()
  {
    $service = \App\Models\Service::factory()->create();
    $sermon = \App\Models\Sermon::factory()->forService($service)->create(); // Associated sermon

    $response = $this->actingAs($this->adminUser)->delete("/services/{$service->id}");

    $this->assertDatabaseMissing('services', ['id' => $service->id]);

    // Check impact on sermons - assuming service_id on sermons table is set to null or deleted.
    // This depends on DB schema (SET NULL ON DELETE or CASCADE) or controller logic.
    // For now, check if sermon still exists but service_id might be null.
    $this->assertDatabaseHas('sermons', ['id' => $sermon->id, 'service_id' => null]);
    // Or if sermons are cascade deleted (less likely for this relationship):
    // $this->assertDatabaseMissing('sermons', ['id' => $sermon->id]);

    $response->assertRedirect('/services');
    $response->assertSessionHas('success');
  }

  /** @test */
  public function destroy_non_existent_service_returns_404()
  {
    $this->actingAs($this->adminUser)->delete('/services/9999')->assertNotFound();
  }
}
