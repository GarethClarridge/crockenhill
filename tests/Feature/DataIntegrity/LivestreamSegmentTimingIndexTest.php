<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LivestreamSegmentTimingIndexTest extends TestCase
{
    #[Test]
    public function it_has_a_timing_index_on_media_processing_log_id_and_start_time(): void
    {
        $this->assertTrue(
            Schema::hasIndex('livestream_segments', 'livestream_segments_log_start_time_index'),
            'The livestream_segments table is missing the composite index on (media_processing_log_id, start_time)'
        );
    }
}
