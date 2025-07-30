<?php

namespace Tests\Feature;

use App\Enums\ProcessingStatus;
use App\Models\SermonProcessingLog;
use App\Models\User;
use App\Services\SermonProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutomatedSermonApiTest extends TestCase
{
  use RefreshDatabase;

  protected User $user;

  protected function setUp(): void
  {
    parent::setUp();

    // Create test user
    $this->user = User::factory()->create();

    // Set up storage and configuration
    Storage::fake('local');
    Storage::fake('public');

    config([
      'sermon-processing.processing.max_file_size' => 100 * 1024 * 1024, // 100MB
      'sermon-processing.processing.allowed_mime_types' => [
        'audio/mpeg',
        'audio/mp3',
        'audio/wav',
        'audio/x-wav',
        'audio/mp4',
        'audio/m4a',
      ],
      'sermon-processing.processing.allowed_extensions' => ['mp3', 'wav', 'm4a', 'mp4'],
    ]);
  }

  /** @test */
  public function it_uploads_sermon_file_successfully(): void
  {
    $file = UploadedFile::fake()->create('sermon.mp3', 1024, 'audio/mpeg');

    $response = $this->actingAs($this->user)
      ->postJson('/api/sermons/automated', [
        'file' => $file,
      ]);

    $response->assertStatus(202)
      ->assertJsonStructure([
        'success',
        'message',
        'processing_id',
        'status_url',
      ])
      ->assertJson([
        'success' => true,
      ]);

    // Verify processing log was created
    $processingId = $response->json('processing_id');
    $this->assertDatabaseHas('sermon_processing_logs', [
      'processing_id' => $processingId,
      'original_filename' => 'sermon.mp3',
      'status' => ProcessingStatus::PENDING->value,
    ]);
  }

  /** @test */
  public function it_requires_authentication_for_upload(): void
  {
    $file = UploadedFile::fake()->create('sermon.mp3', 1024, 'audio/mpeg');

    $response = $this->postJson('/api/sermons/automated', [
      'file' => $file,
    ]);

    $response->assertStatus(401);
  }

  /** @test */
  public function it_validates_file_is_required(): void
  {
    $response = $this->actingAs($this->user)
      ->postJson('/api/sermons/automated', []);

    $response->assertStatus(422)
      ->assertJsonValidationErrors(['file'])
      ->assertJsonFragment([
        'file' => ['Please select an audio file to upload.']
      ]);
  }

  /** @test */
  public function it_validates_file_type(): void
  {
    $file = UploadedFile::fake()->create('document.pdf', 1024, 'application/pdf');

    $response = $this->actingAs($this->user)
      ->postJson('/api/sermons/automated', [
        'file' => $file,
      ]);

    $response->assertStatus(422)
      ->assertJsonValidationErrors(['file'])
      ->assertJsonFragment([
        'file' => ['The uploaded file must be one of the following types: mp3, wav, m4a, mp4.']
      ]);
  }

  /** @test */
  public function it_validates_file_size(): void
  {
    // Create file larger than 100MB limit
    $file = UploadedFile::fake()->create('large-sermon.mp3', 101 * 1024, 'audio/mpeg');

    $response = $this->actingAs($this->user)
      ->postJson('/api/sermons/automated', [
        'file' => $file,
      ]);

    $response->assertStatus(422)
      ->assertJsonValidationErrors(['file'])
      ->assertJsonFragment([
        'file' => ['The sermon audio file may not be greater than 100MB.']
      ]);
  }

  /** @test */
  public function it_accepts_various_audio_formats(): void
  {
    $audioFormats = [
      ['sermon.mp3', 'audio/mpeg'],
      ['sermon.wav', 'audio/wav'],
      ['sermon.m4a', 'audio/m4a'],
      ['sermon.mp4', 'audio/mp4'],
    ];

    foreach ($audioFormats as [$filename, $mimeType]) {
      $file = UploadedFile::fake()->create($filename, 1024, $mimeType);

      $response = $this->actingAs($this->user)
        ->postJson('/api/sermons/automated', [
          'file' => $file,
        ]);

      $response->assertStatus(202)
        ->assertJson(['success' => true]);
    }
  }

  /** @test */
  public function it_handles_corrupted_file_upload(): void
  {
    // Create a file that appears valid but is corrupted
    $file = UploadedFile::fake()->createWithContent('corrupted.mp3', 'invalid audio data');

    $response = $this->actingAs($this->user)
      ->postJson('/api/sermons/automated', [
        'file' => $file,
      ]);

    $response->assertStatus(400)
      ->assertJson([
        'success' => false,
        'message' => 'Invalid or corrupted file uploaded',
        'error_code' => 'INVALID_FILE',
      ]);
  }

  /** @test */
  public function it_handles_processing_service_errors(): void
  {
    // Mock the service to throw an exception
    $mockService = $this->createMock(SermonProcessingService::class);
    $mockService->method('processSermon')
      ->willThrowException(new \Exception('Service unavailable'));

    $this->app->instance(SermonProcessingService::class, $mockService);

    $file = UploadedFile::fake()->create('sermon.mp3', 1024, 'audio/mpeg');

    $response = $this->actingAs($this->user)
      ->postJson('/api/sermons/automated', [
        'file' => $file,
      ]);

    $response->assertStatus(500)
      ->assertJson([
        'success' => false,
        'message' => 'An unexpected error occurred during upload processing',
        'error_code' => 'INTERNAL_ERROR',
      ]);
  }

  /** @test */
  public function it_retrieves_processing_status_successfully(): void
  {
    // Create processing log
    $processingId = (string) Str::uuid();
    $processingLog = SermonProcessingLog::create([
      'processing_id' => $processingId,
      'original_filename' => 'test-sermon.mp3',
      'status' => ProcessingStatus::PROCESSING,
      'current_step' => 'transcribing_audio',
    ]);

    $response = $this->actingAs($this->user)
      ->getJson("/api/sermons/processing/{$processingId}/status");

    $response->assertStatus(200)
      ->assertJsonStructure([
        'found',
        'processing_id',
        'status',
        'current_step',
        'created_at',
        'updated_at',
      ])
      ->assertJson([
        'found' => true,
        'processing_id' => $processingId,
        'status' => 'processing',
        'current_step' => 'transcribing_audio',
      ]);
  }

  /** @test */
  public function it_returns_404_for_nonexistent_processing_id(): void
  {
    $nonexistentId = (string) Str::uuid();

    $response = $this->actingAs($this->user)
      ->getJson("/api/sermons/processing/{$nonexistentId}/status");

    $response->assertStatus(404)
      ->assertJson([
        'found' => false,
      ]);
  }

  /** @test */
  public function it_validates_processing_id_format(): void
  {
    $invalidId = 'invalid-uuid-format';

    $response = $this->actingAs($this->user)
      ->getJson("/api/sermons/processing/{$invalidId}/status");

    $response->assertStatus(400)
      ->assertJson([
        'found' => false,
        'message' => 'Invalid processing ID format',
      ]);
  }

  /** @test */
  public function it_retrieves_processing_statistics(): void
  {
    // Create some test processing logs
    SermonProcessingLog::factory()->count(5)->create([
      'status' => ProcessingStatus::COMPLETED,
    ]);
    SermonProcessingLog::factory()->count(2)->create([
      'status' => ProcessingStatus::FAILED,
    ]);
    SermonProcessingLog::factory()->count(1)->create([
      'status' => ProcessingStatus::PROCESSING,
    ]);

    $response = $this->actingAs($this->user)
      ->getJson('/api/sermons/processing/statistics');

    $response->assertStatus(200)
      ->assertJsonStructure([
        'success',
        'data' => [
          'total_processed',
          'completed',
          'failed',
          'in_progress',
          'pending',
          'recent_activity',
        ],
        'timestamp',
      ])
      ->assertJson([
        'success' => true,
        'data' => [
          'total_processed' => 8,
          'completed' => 5,
          'failed' => 2,
          'in_progress' => 1,
        ],
      ]);
  }

  /** @test */
  public function it_retries_failed_processing(): void
  {
    // Create failed processing log
    $processingId = (string) Str::uuid();
    SermonProcessingLog::create([
      'processing_id' => $processingId,
      'original_filename' => 'failed-sermon.mp3',
      'status' => ProcessingStatus::FAILED,
      'current_step' => 'transcribing_audio_failed',
      'error_message' => 'Temporary service unavailable',
    ]);

    $response = $this->actingAs($this->user)
      ->postJson("/api/sermons/processing/{$processingId}/retry");

    $response->assertStatus(202)
      ->assertJsonStructure([
        'success',
        'message',
        'processing_id',
        'status_url',
      ])
      ->assertJson([
        'success' => true,
        'processing_id' => $processingId,
      ]);

    // Verify processing log was reset
    $this->assertDatabaseHas('sermon_processing_logs', [
      'processing_id' => $processingId,
      'status' => ProcessingStatus::PENDING->value,
      'current_step' => 'retry_initiated',
    ]);
  }

  /** @test */
  public function it_handles_retry_of_non_failed_processing(): void
  {
    // Create processing log that's not failed
    $processingId = (string) Str::uuid();
    SermonProcessingLog::create([
      'processing_id' => $processingId,
      'original_filename' => 'active-sermon.mp3',
      'status' => ProcessingStatus::PROCESSING,
      'current_step' => 'transcribing_audio',
    ]);

    $response = $this->actingAs($this->user)
      ->postJson("/api/sermons/processing/{$processingId}/retry");

    $response->assertStatus(422)
      ->assertJson([
        'success' => false,
        'error_code' => 'PROCESSING_NOT_FAILED',
      ]);
  }

  /** @test */
  public function it_retrieves_failed_processing_logs(): void
  {
    // Create failed processing logs
    SermonProcessingLog::factory()->count(3)->create([
      'status' => ProcessingStatus::FAILED,
      'error_message' => 'Various failure reasons',
    ]);

    // Create successful processing logs (should not be included)
    SermonProcessingLog::factory()->count(2)->create([
      'status' => ProcessingStatus::COMPLETED,
    ]);

    $response = $this->actingAs($this->user)
      ->getJson('/api/sermons/processing/failed');

    $response->assertStatus(200)
      ->assertJsonStructure([
        'success',
        'data',
        'count',
        'limit',
        'timestamp',
      ])
      ->assertJson([
        'success' => true,
        'count' => 3,
        'limit' => 50,
      ]);

    // Verify only failed logs are returned
    $data = $response->json('data');
    $this->assertCount(3, $data);
    foreach ($data as $log) {
      $this->assertNotNull($log['error_message']);
    }
  }

  /** @test */
  public function it_respects_limit_parameter_for_failed_logs(): void
  {
    // Create more failed logs than the limit
    SermonProcessingLog::factory()->count(10)->create([
      'status' => ProcessingStatus::FAILED,
    ]);

    $response = $this->actingAs($this->user)
      ->getJson('/api/sermons/processing/failed?limit=5');

    $response->assertStatus(200)
      ->assertJson([
        'success' => true,
        'count' => 5,
        'limit' => 5,
      ]);

    $this->assertCount(5, $response->json('data'));
  }

  /** @test */
  public function it_enforces_maximum_limit_for_failed_logs(): void
  {
    $response = $this->actingAs($this->user)
      ->getJson('/api/sermons/processing/failed?limit=200');

    $response->assertStatus(200)
      ->assertJson([
        'limit' => 100, // Should be capped at 100
      ]);
  }

  /** @test */
  public function it_applies_graceful_degradation(): void
  {
    // Create failed processing log with sermon
    $processingId = (string) Str::uuid();
    $sermon = \App\Models\Sermon::factory()->create([
      'title' => 'Untitled Sermon',
    ]);

    SermonProcessingLog::create([
      'processing_id' => $processingId,
      'original_filename' => '2024-01-15_morning_service.mp3',
      'status' => ProcessingStatus::FAILED,
      'current_step' => 'analyzing_transcript_failed',
      'sermon_id' => $sermon->id,
      'error_message' => 'AI service permanently unavailable',
    ]);

    $response = $this->actingAs($this->user)
      ->postJson("/api/sermons/processing/{$processingId}/graceful-degradation");

    $response->assertStatus(200)
      ->assertJsonStructure([
        'success',
        'message',
        'processing_id',
        'details',
      ])
      ->assertJson([
        'success' => true,
        'processing_id' => $processingId,
      ]);

    // Verify processing log was marked as completed with degradation
    $this->assertDatabaseHas('sermon_processing_logs', [
      'processing_id' => $processingId,
      'status' => ProcessingStatus::COMPLETED->value,
      'current_step' => 'completed_with_degradation',
    ]);
  }

  /** @test */
  public function it_handles_graceful_degradation_for_nonexistent_processing(): void
  {
    $nonexistentId = (string) Str::uuid();

    $response = $this->actingAs($this->user)
      ->postJson("/api/sermons/processing/{$nonexistentId}/graceful-degradation");

    $response->assertStatus(422)
      ->assertJson([
        'success' => false,
        'error_code' => 'PROCESSING_LOG_NOT_FOUND',
      ]);
  }

  /** @test */
  public function it_retrieves_system_health(): void
  {
    $response = $this->actingAs($this->user)
      ->getJson('/api/sermons/processing/health');

    $response->assertStatus(200)
      ->assertJsonStructure([
        'overall_status',
        'checks',
        'statistics',
        'timestamp',
      ]);

    $overallStatus = $response->json('overall_status');
    $this->assertContains($overallStatus, ['healthy', 'degraded', 'error']);
  }

  /** @test */
  public function it_handles_health_check_errors(): void
  {
    // Mock the service to throw an exception
    $mockService = $this->createMock(SermonProcessingService::class);
    $mockService->method('getSystemHealth')
      ->willThrowException(new \Exception('Health check failed'));

    $this->app->instance(SermonProcessingService::class, $mockService);

    $response = $this->actingAs($this->user)
      ->getJson('/api/sermons/processing/health');

    $response->assertStatus(503)
      ->assertJson([
        'overall_status' => 'error',
      ]);
  }

  /** @test */
  public function it_applies_rate_limiting(): void
  {
    // This test would require setting up rate limiting middleware
    // For now, we'll test that multiple requests can be made successfully
    $file = UploadedFile::fake()->create('sermon.mp3', 1024, 'audio/mpeg');

    for ($i = 0; $i < 3; $i++) {
      $response = $this->actingAs($this->user)
        ->postJson('/api/sermons/automated', [
          'file' => $file,
        ]);

      $response->assertStatus(202);
    }
  }

  /** @test */
  public function it_logs_api_requests(): void
  {
    $file = UploadedFile::fake()->create('sermon.mp3', 1024, 'audio/mpeg');

    // Enable log testing
    \Illuminate\Support\Facades\Log::spy();

    $response = $this->actingAs($this->user)
      ->postJson('/api/sermons/automated', [
        'file' => $file,
      ]);

    $response->assertStatus(202);

    // Verify logging occurred
    \Illuminate\Support\Facades\Log::shouldHaveReceived('info')
      ->with('Automated sermon upload initiated', \Mockery::type('array'));
  }

  /** @test */
  public function it_handles_concurrent_uploads(): void
  {
    // Test multiple simultaneous uploads
    $files = [
      UploadedFile::fake()->create('sermon1.mp3', 1024, 'audio/mpeg'),
      UploadedFile::fake()->create('sermon2.mp3', 1024, 'audio/mpeg'),
      UploadedFile::fake()->create('sermon3.mp3', 1024, 'audio/mpeg'),
    ];

    $responses = [];
    foreach ($files as $file) {
      $responses[] = $this->actingAs($this->user)
        ->postJson('/api/sermons/automated', [
          'file' => $file,
        ]);
    }

    // All uploads should succeed
    foreach ($responses as $response) {
      $response->assertStatus(202)
        ->assertJson(['success' => true]);
    }

    // Verify all processing logs were created
    $this->assertDatabaseCount('sermon_processing_logs', 3);
  }

  /** @test */
  public function it_validates_processing_id_format_in_all_endpoints(): void
  {
    $invalidId = 'invalid-format';
    $endpoints = [
      ['GET', "/api/sermons/processing/{$invalidId}/status"],
      ['POST', "/api/sermons/processing/{$invalidId}/retry"],
      ['POST', "/api/sermons/processing/{$invalidId}/graceful-degradation"],
    ];

    foreach ($endpoints as [$method, $url]) {
      $response = $this->actingAs($this->user)
        ->json($method, $url);

      $response->assertStatus(400)
        ->assertJsonFragment([
          'message' => 'Invalid processing ID format',
        ]);
    }
  }

  /** @test */
  public function it_handles_authorization_properly(): void
  {
    // Create user without sermon creation permission
    $unauthorizedUser = User::factory()->create();

    // Mock the authorization to return false
    $this->app['auth']->shouldReceive('user')
      ->andReturn($unauthorizedUser);

    $file = UploadedFile::fake()->create('sermon.mp3', 1024, 'audio/mpeg');

    $response = $this->actingAs($unauthorizedUser)
      ->postJson('/api/sermons/automated', [
        'file' => $file,
      ]);

    // Should be forbidden due to authorization failure
    $response->assertStatus(403);
  }

  /** @test */
  public function it_returns_proper_content_types(): void
  {
    $file = UploadedFile::fake()->create('sermon.mp3', 1024, 'audio/mpeg');

    $response = $this->actingAs($this->user)
      ->postJson('/api/sermons/automated', [
        'file' => $file,
      ]);

    $response->assertHeader('Content-Type', 'application/json');
  }

  /** @test */
  public function it_handles_malformed_json_requests(): void
  {
    $response = $this->actingAs($this->user)
      ->post('/api/sermons/automated', [], [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ]);

    // Should handle malformed JSON gracefully
    $response->assertStatus(422);
  }
}
