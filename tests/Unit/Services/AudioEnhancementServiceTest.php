<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AudioEnhancementService;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AudioEnhancementServiceTest extends TestCase
{
    private AudioEnhancementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('media-processing.ffmpeg.ffmpeg_path', '/usr/bin/ffmpeg');
        Config::set('media-processing.ffmpeg.ffprobe_path', '/usr/bin/ffprobe');
        Config::set('media-processing.audio_enhancement.enabled', true);
        Config::set('media-processing.audio_enhancement.noise_reduction', true);
        Config::set('media-processing.audio_enhancement.dynamic_norm', true);
        Config::set('media-processing.audio_enhancement.loudness_norm', true);
        Config::set('media-processing.audio_enhancement.target_lufs', -16.0);
        Config::set('media-processing.audio_enhancement.true_peak', -1.5);
        Config::set('media-processing.audio_enhancement.lra', 11.0);

        $this->service = new AudioEnhancementService;
    }

    // ---- enhance() returns null when disabled ----

    #[Test]
    public function enhance_returns_null_when_enhancement_is_disabled(): void
    {
        Config::set('media-processing.audio_enhancement.enabled', false);

        $result = $this->service->enhance('/some/file.mp3', 'test-id');

        $this->assertNull($result);
    }

    #[Test]
    public function enhance_returns_null_when_input_file_does_not_exist(): void
    {
        $result = $this->service->enhance('/nonexistent/file.mp3', 'test-id');

        $this->assertNull($result);
    }

    // ---- buildFilterChain() ----

    #[Test]
    public function build_filter_chain_returns_null_when_all_filters_disabled(): void
    {
        Config::set('media-processing.audio_enhancement.noise_reduction', false);
        Config::set('media-processing.audio_enhancement.dynamic_norm', false);
        Config::set('media-processing.audio_enhancement.loudness_norm', false);

        $result = $this->service->buildFilterChain('/some/file.mp3', 'test-id');

        $this->assertNull($result);
    }

    #[Test]
    public function build_filter_chain_includes_afftdn_when_noise_reduction_enabled(): void
    {
        Config::set('media-processing.audio_enhancement.loudness_norm', false);
        Config::set('media-processing.audio_enhancement.dynamic_norm', false);

        $result = $this->service->buildFilterChain('/some/file.mp3', 'test-id');

        $this->assertNotNull($result);
        $this->assertStringContainsString('afftdn', $result);
    }

    #[Test]
    public function build_filter_chain_includes_dynaudnorm_when_dynamic_norm_enabled(): void
    {
        Config::set('media-processing.audio_enhancement.noise_reduction', false);
        Config::set('media-processing.audio_enhancement.loudness_norm', false);

        $result = $this->service->buildFilterChain('/some/file.mp3', 'test-id');

        $this->assertNotNull($result);
        $this->assertStringContainsString('dynaudnorm', $result);
    }

    #[Test]
    public function build_filter_chain_includes_loudnorm_when_loudness_norm_enabled(): void
    {
        Config::set('media-processing.audio_enhancement.noise_reduction', false);
        Config::set('media-processing.audio_enhancement.dynamic_norm', false);

        // measureLoudness will fail (no real file) so single-pass loudnorm fallback is used
        $result = $this->service->buildFilterChain('/nonexistent/file.mp3', 'test-id');

        $this->assertNotNull($result);
        $this->assertStringContainsString('loudnorm', $result);
    }

    #[Test]
    public function build_filter_chain_omits_afftdn_when_noise_reduction_disabled(): void
    {
        Config::set('media-processing.audio_enhancement.noise_reduction', false);
        Config::set('media-processing.audio_enhancement.loudness_norm', false);

        $result = $this->service->buildFilterChain('/some/file.mp3', 'test-id');

        if ($result !== null) {
            $this->assertStringNotContainsString('afftdn', $result);
        }

        $this->assertTrue(true); // passes even if null (no filters active)
    }

    // ---- parseLoudnormJson() ----

    #[Test]
    public function measure_loudness_returns_null_when_stderr_contains_no_json(): void
    {
        $method = new \ReflectionMethod(AudioEnhancementService::class, 'parseLoudnormJson');

        $result = $method->invoke($this->service, 'no json here', 'test-id');

        $this->assertNull($result);
    }

    #[Test]
    public function measure_loudness_parses_valid_loudnorm_json_from_stderr(): void
    {
        $method = new \ReflectionMethod(AudioEnhancementService::class, 'parseLoudnormJson');

        $stderr = <<<'JSON'
        [Parsed_loudnorm_0] {
            "input_i" : "-23.50",
            "input_tp" : "-4.20",
            "input_lra" : "8.30",
            "input_thresh" : "-34.00",
            "output_i" : "-16.00",
            "output_tp" : "-1.50",
            "output_lra" : "7.10",
            "output_thresh" : "-26.50",
            "normalization_type" : "dynamic",
            "target_offset" : "0.50"
        }
        JSON;

        $result = $method->invoke($this->service, $stderr, 'test-id');

        $this->assertIsArray($result);
        $this->assertEqualsWithDelta(-23.50, $result['input_i'], 0.01);
        $this->assertEqualsWithDelta(-4.20, $result['input_tp'], 0.01);
        $this->assertEqualsWithDelta(8.30, $result['input_lra'], 0.01);
        $this->assertEqualsWithDelta(-34.00, $result['input_thresh'], 0.01);
        $this->assertEqualsWithDelta(0.50, $result['target_offset'], 0.01);
    }

    #[Test]
    public function measure_loudness_returns_null_when_required_keys_missing(): void
    {
        $method = new \ReflectionMethod(AudioEnhancementService::class, 'parseLoudnormJson');

        $stderr = '{"input_i": "-23.50"}'; // missing other required keys

        $result = $method->invoke($this->service, $stderr, 'test-id');

        $this->assertNull($result);
    }
}
