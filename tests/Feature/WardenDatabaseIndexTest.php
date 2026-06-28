<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WardenDatabaseIndexTest extends TestCase
{
    /**
     * Test that the approved index exists on the speaker_samples table.
     */
    #[Test]
    public function it_has_index_on_speaker_samples_approved(): void
    {
        $this->assertTrue(
            Schema::hasIndex('speaker_samples', 'speaker_samples_approved_index'),
            'Index "speaker_samples_approved_index" is missing on "speaker_samples" table.'
        );
    }
}
