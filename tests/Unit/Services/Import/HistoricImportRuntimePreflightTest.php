<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Import;

use App\Services\Import\HistoricImportRuntimePreflight;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class HistoricImportRuntimePreflightTest extends TestCase
{
    #[Test]
    public function it_binds_the_exact_non_mock_runtime_and_rejects_any_changed_evidence(): void
    {
        $service = new HistoricImportRuntimePreflight;
        $evidence = $this->evidence();
        $fingerprint = $service->fingerprint($evidence);

        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $fingerprint);
        $evidence['queues']['llm_workers'] = 5;
        $this->assertNotSame($fingerprint, $service->fingerprint($evidence));
    }

    #[Test]
    public function it_fails_closed_on_mock_providers_unpinned_images_and_clock_drift(): void
    {
        $service = new HistoricImportRuntimePreflight;

        foreach (['mock_provider', 'floating_image', 'clock_drift'] as $case) {
            $evidence = $this->evidence();

            match ($case) {
                'mock_provider' => $evidence['providers']['analysis']['service'] = 'mock',
                'floating_image' => $evidence['image_digest'] = 'crockenhill:latest',
                'clock_drift' => $evidence['clock']['offset_ms'] = 1_001,
            };

            try {
                $service->fingerprint($evidence);
                $this->fail("{$case} should fail closed.");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return array<string, mixed> */
    private function evidence(): array
    {
        return [
            'format' => 'crockenhill.historic-runtime.v1',
            'commit' => str_repeat('a', 64),
            'image_digest' => 'registry.example/crockenhill@sha256:'.str_repeat('b', 64),
            'package_lock_sha256' => str_repeat('c', 64),
            'schema' => ['migration' => '2026_08_09_210003'],
            'database' => ['driver' => 'mysql', 'database' => 'historic', 'server_uuid' => 'db-1'],
            'storage' => [
                'staging' => 'staging-1',
                'production' => 'bucket-1/prefix',
                'encryption_at_rest_verified' => true,
                'encryption_in_transit_verified' => true,
            ],
            'providers' => [
                'transcription' => [
                    'service' => 'openai',
                    'model' => 'whisper-1',
                    'credential_present' => true,
                    'connectivity_verified' => true,
                ],
                'analysis' => [
                    'service' => 'openai',
                    'model' => 'gpt-5-mini',
                    'credential_present' => true,
                    'connectivity_verified' => true,
                ],
            ],
            'binaries' => [
                'ffmpeg' => ['version' => '7.1', 'sha256' => str_repeat('d', 64), 'arguments' => ['-c', 'copy']],
                'ffprobe' => ['version' => '7.1', 'sha256' => str_repeat('e', 64), 'arguments' => ['-show_streams']],
            ],
            'prompts' => ['transcription' => str_repeat('f', 64)],
            'algorithms' => ['processing' => str_repeat('1', 64)],
            'queues' => ['ffmpeg_workers' => 1, 'whisper_workers' => 1, 'llm_workers' => 4],
            'resources' => ['free_bytes' => 1_000_000, 'memory_bytes' => 1_000_000],
            'clock' => ['offset_ms' => 25],
            'outbound_probe' => ['ok' => true, 'observed_at' => '2026-08-09T12:00:00Z'],
        ];
    }
}
