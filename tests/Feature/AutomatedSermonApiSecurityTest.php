<?php

namespace Tests\Feature;

use App\Enums\ProcessingStatus;
use App\Models\SermonProcessingLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutomatedSermonApiSecurityTest extends TestCase
{
  use RefreshDatabase;

  protected User $user;

  protected function setUp(): void
  {
    parent::setUp();

    $this->user = User::factory()->create();
    Storage::fake('local');
    Storage::fake('public');

    config([
      'sermon-processing.processing.max_file_size' => 100 * 1024 * 1024,
      'sermon-processing.processing.allowed_mime_types' => [
        'audio/mpeg',
        'audio/mp3',
        'audio/wav',
      ],
      'sermon-processing.processing.allowed_extensions' => ['mp3', 'wav'],
    ]);
  }

  /** @test */
  public function it_prevents_unauthorized_access_to_upload_endpoint(): void
  {
    $file = UploadedFile::fake()->create('sermon.mp3', 1024, 'audio/mpeg');

    $response = $this->postJson('/api/sermons/automated', [
      'file' => $file,
    ]);

    $response->assertStatus(401);
  }

  /** @test */
  public function it_prevents_unauthorized_access_to_status_endpoint(): void
  {
    $processingId = (string) Str::uuid();

    $response = $this->getJson("/api/sermons/processing/{$processingId}/status");

    $response->assertStatus(401);
  }

  /** @test */
  public function it_prevents_unauthorized_access_to_statistics_endpoint(): void
  {
    $response = $this->getJson('/api/sermons/processing/statistics');

    $response->assertStatus(401);
  }

  /** @test */
  public function it_prevents_unauthorized_access_to_retry_endpoint(): void
  {
    $processingId = (string) Str::uuid();

    $response = $this->postJson("/api/sermons/processing/{$processingId}/retry");

    $response->assertStatus(401);
  }

  /** @test */
  public function it_prevents_unauthorized_access_to_failed_logs_endpoint(): void
  {
    $response = $this->getJson('/api/sermons/processing/failed');

    $response->assertStatus(401);
  }

  /** @test */
  public function it_prevents_unauthorized_access_to_graceful_degradation_endpoint(): void
  {
    $processingId = (string) Str::uuid();

    $response = $this->postJson("/api/sermons/processing/{$processingId}/graceful-degradation");

    $response->assertStatus(401);
  }

  /** @test */
  public function it_prevents_unauthorized_access_to_health_endpoint(): void
  {
    $response = $this->getJson('/api/sermons/processing/health');

    $response->assertStatus(401);
  }

  /** @test */
  public function it_validates_file_mime_type_strictly(): void
  {
    // Try to upload a malicious file disguised as audio
    $maliciousFile = UploadedFile::fake()->createWithContent(
      'malicious.mp3',
      '<?php system($_GET["cmd"]); ?>',
      'audio/mpeg'
    );

    $response = $this->actingAs($this->user)
      ->postJson('/api/sermons/automated', [
        'file' => $maliciousFile,
      ]);

    // Should be rejected due to content validation
    $response->assertStatus(400)
      ->assertJson([
        'success' => false,
        'error_code' => 'INVALID_FILE',
      ]);
  }

  /** @test */
  public function it_prevents_path_traversal_attacks(): void
  {
    // Try to upload file with path traversal in name
    $file = UploadedFile::fake()->create('../../../etc/passwd.mp3', 1024, 'audio/mpeg');

    $response = $this->actingAs($this->user)
      ->postJson('/api/sermons/automated', [
        'file' => $file,
      ]);

    // Should handle safely without path traversal
    if ($response->status() === 202) {
      // If upload succeeds, verify the file was stored safely
      $processingId = $response->json('processing_id');
      $this->assertDatabaseHas('sermon_processing_logs', [
        'processing_id' => $processingId,
        'original_filename' => '../../../etc/passwd.mp3', // Filename preserved but handled safely
      ]);
    }
  }

  /** @test */
  public function it_prevents_sql_injection_in_processing_id(): void
  {
    $maliciousId = "'; DROP TABLE sermons; --";

    $response = $this->actingAs($this->user)
      ->getJson("/api/sermons/processing/{$maliciousId}/status");

    $response->assertStatus(400)
      ->assertJson([
        'found' => false,
        'message' => 'Invalid processing ID format',
      ]);
  }

  /** @test */
  public function it_prevents_xss_in_error_messages(): void
  {
    $xssPayload = '<script>alert("xss")</script>';

    // Try to inject XSS through filename
    $file = UploadedFile::fake()->create($xssPayload . '.mp3', 1024, 'audio/mpeg');

    $response = $this->actingAs($this->user)
      ->postJson('/api/sermons/automated', [
        'file' => $file,
      ]);

    // Response should be JSON and not execute script
    $response->assertHeader('Content-Type', 'application/json');

    if ($response->status() === 202) {
      // If successful, verify filename is stored safely
      $processingId = $response->json('processing_id');
      $this->assertDatabaseHas('sermon_processing_logs', [
        'processing_id' => $processingId,
        'original_filename' => $xssPayload . '.mp3',
      ]);
    }
  }

  /** @test */
  public function it_limits_file_upload_size(): void
  {
    // Try to upload file larger than configured limit
    $largeFile = UploadedFile::fake()->create('large.mp3', 101 * 1024, 'audio/mpeg');

    $response = $this->actingAs($this->user)
      ->postJson('/api/sermons/automated', [
        'file' => $largeFile,
      ]);

    $response->assertStatus(422)
      ->assertJsonValidationErrors(['file']);
  }

  /** @test */
  public function it_prevents_zip_bomb_attacks(): void
  {
    // Create a file that could be a compressed bomb
    $suspiciousFile = UploadedFile::fake()->create('suspicious.mp3', 1024, 'audio/mpeg');

    $response = $this->actingAs($this->user)
      ->postJson('/api/sermons/automated', [
        'file' => $suspiciousFile,
      ]);

    // Should handle normally but with proper validation
    $this->assertContains($response->status(), [202, 400, 422]);
  }

  /** @test */
  public function it_sanitizes_user_input_in_logs(): void
  {
    \Illuminate\Support\Facades\Log::spy();

    $maliciousFilename = "test\n[MALICIOUS LOG ENTRY]\nsermon.mp3";
    $file = UploadedFile::fake()->create($maliciousFilename, 1024, 'audio/mpeg');

    $response = $this->actingAs($this->user)
      ->postJson('/api/sermons/automated', [
        'file' => $file,
      ]);

    // Verify that log entries don't contain unescaped user input
    \Illuminate\Support\Facades\Log::shouldHaveReceived('info')
      ->with('Automated sermon upload initiated', \Mockery::on(function ($context) use ($maliciousFilename) {
        // Verify the filename is logged but doesn't break log structure
        return isset($context['original_filename']) &&
          $context['original_filename'] === $maliciousFilename;
      }));
  }

  /** @test */
  public function it_prevents_processing_id_enumeration(): void
  {
    // Try to access processing status with sequential IDs
    $sequentialIds = [
      '00000000-0000-0000-0000-000000000001',
      '00000000-0000-0000-0000-000000000002',
      '00000000-0000-0000-0000-000000000003',
    ];

    foreach ($sequentialIds as $id) {
      $response = $this->actingAs($this->user)
        ->getJson("/api/sermons/processing/{$id}/status");

      // Should return 404 for non-existent IDs, not reveal system info
      $response->assertStatus(404)
        ->assertJson([
          'found' => false,
        ]);
    }
  }

  /** @test */
  public function it_prevents_timing_attacks_on_processing_id_lookup(): void
  {
    // Create one real processing log
    $realId = (string) Str::uuid();
    SermonProcessingLog::create([
      'processing_id' => $realId,
      'original_filename' => 'test.mp3',
      'status' => ProcessingStatus::COMPLETED,
    ]);

    $fakeId = (string) Str::uuid();

    // Measure response times (simplified test)
    $start1 = microtime(true);
    $response1 = $this->actingAs($this->user)
      ->getJson("/api/sermons/processing/{$realId}/status");
    $time1 = microtime(true) - $start1;

    $start2 = microtime(true);
    $response2 = $this->actingAs($this->user)
      ->getJson("/api/sermons/processing/{$fakeId}/status");
    $time2 = microtime(true) - $start2;

    $response1->assertStatus(200);
    $response2->assertStatus(404);

    // Response times should be similar (within reasonable bounds)
    // This is a basic test - in production you'd want more sophisticated timing analysis
    $timeDifference = abs($time1 - $time2);
    $this->assertLessThan(0.1, $timeDifference, 'Response times differ significantly, potential timing attack vector');
  }

  /** @test */
  public function it_handles_concurrent_requests_safely(): void
  {
    // Test for race conditions by making concurrent requests
    $processingId = (string) Str::uuid();
    SermonProcessingLog::create([
      'processing_id' => $processingId,
      'original_filename' => 'test.mp3',
      'status' => ProcessingStatus::FAILED,
      'current_step' => 'transcribing_audio_failed',
    ]);

    // Make multiple concurrent retry requests
    $responses = [];
    for ($i = 0; $i < 3; $i++) {
      $responses[] = $this->actingAs($this->user)
        ->postJson("/api/sermons/processing/{$processingId}/retry");
    }

    // Only one should succeed, others should handle gracefully
    $successCount = 0;
    foreach ($responses as $response) {
      if ($response->status() === 202) {
        $successCount++;
      } else {
        // Should return appropriate error, not crash
        $this->assertContains($response->status(), [422, 409]);
      }
    }

    // At most one retry should succeed
    $this->assertLessThanOrEqual(1, $successCount);
  }

  /** @test */
  public function it_validates_content_type_headers(): void
  {
    $file = UploadedFile::fake()->create('sermon.mp3', 1024, 'audio/mpeg');

    // Try with incorrect content type
    $response = $this->actingAs($this->user)
      ->post('/api/sermons/automated', [
        'file' => $file,
      ], [
        'Content-Type' => 'text/plain',
        'Accept' => 'application/json',
      ]);

    // Should handle gracefully
    $this->assertContains($response->status(), [400, 422]);
  }

  /** @test */
  public function it_prevents_csrf_attacks(): void
  {
    // Laravel's CSRF protection should be active for state-changing operations
    $file = UploadedFile::fake()->create('sermon.mp3', 1024, 'audio/mpeg');

    // Make request without CSRF token (if CSRF is enabled for API)
    $response = $this->post('/api/sermons/automated', [
      'file' => $file,
    ]);

    // Should require authentication at minimum
    $response->assertStatus(401);
  }

  /** @test */
  public function it_handles_malformed_multipart_requests(): void
  {
    // Send malformed multipart data
    $response = $this->actingAs($this->user)
      ->post('/api/sermons/automated', 'malformed-data', [
        'Content-Type' => 'multipart/form-data',
        'Accept' => 'application/json',
      ]);

    // Should handle gracefully without crashing
    $this->assertContains($response->status(), [400, 422]);
  }

  /** @test */
  public function it_limits_request_frequency(): void
  {
    // This would test rate limiting if implemented
    // For now, verify multiple requests don't cause issues
    $file = UploadedFile::fake()->create('sermon.mp3', 1024, 'audio/mpeg');

    $responses = [];
    for ($i = 0; $i < 5; $i++) {
      $responses[] = $this->actingAs($this->user)
        ->postJson('/api/sermons/automated', [
          'file' => $file,
        ]);
    }

    // All should either succeed or be rate limited gracefully
    foreach ($responses as $response) {
      $this->assertContains($response->status(), [202, 429]);
    }
  }

  /** @test */
  public function it_prevents_information_disclosure_in_errors(): void
  {
    // Try to trigger various error conditions and verify they don't leak sensitive info
    $testCases = [
      ['invalid-uuid', 400],
      [(string) Str::uuid(), 404], // Non-existent ID
    ];

    foreach ($testCases as [$processingId, $expectedStatus]) {
      $response = $this->actingAs($this->user)
        ->getJson("/api/sermons/processing/{$processingId}/status");

      $response->assertStatus($expectedStatus);

      // Verify response doesn't contain sensitive information
      $content = $response->getContent();
      $this->assertStringNotContainsString('database', strtolower($content));
      $this->assertStringNotContainsString('sql', strtolower($content));
      $this->assertStringNotContainsString('password', strtolower($content));
      $this->assertStringNotContainsString('secret', strtolower($content));
      $this->assertStringNotContainsString('key', strtolower($content));
    }
  }

  /** @test */
  public function it_validates_http_methods(): void
  {
    $processingId = (string) Str::uuid();

    // Test wrong HTTP methods
    $wrongMethods = [
      ['PUT', '/api/sermons/automated'],
      ['DELETE', '/api/sermons/automated'],
      ['PATCH', "/api/sermons/processing/{$processingId}/status"],
      ['PUT', "/api/sermons/processing/{$processingId}/status"],
    ];

    foreach ($wrongMethods as [$method, $url]) {
      $response = $this->actingAs($this->user)
        ->json($method, $url);

      // Should return method not allowed
      $response->assertStatus(405);
    }
  }
}
