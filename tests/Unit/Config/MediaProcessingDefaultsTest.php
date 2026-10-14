<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The defaults any machine gets without a `.env` override must be the values the
 * historic work measured, so weekly processing wherever it next runs behaves as
 * tested (plan §4.0b, re-checked 2026-10-14). `.env.testing` sets none of these.
 */
class MediaProcessingDefaultsTest extends TestCase
{
    #[Test]
    public function local_whisper_defaults_to_the_model_every_loop_and_edge_measurement_used(): void
    {
        $this->assertSame('large-v3-turbo', config('media-processing.transcription.local_whisper_model'));
    }

    #[Test]
    public function structure_and_analysis_default_to_the_adopted_model(): void
    {
        $this->assertSame('gpt-5.6-luna', config('media-processing.service_structure.model'));
        $this->assertSame('gpt-5.6-luna', config('media-processing.analysis.model'));
    }

    /** Bulk passes trade quality for throughput in `.env`; one service a week does not need to. */
    #[Test]
    public function the_reencode_preset_default_keeps_quality_over_throughput(): void
    {
        $this->assertSame('faster', config('media-processing.video_extraction.reencode_preset'));
    }
}
