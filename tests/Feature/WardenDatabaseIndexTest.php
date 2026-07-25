<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WardenDatabaseIndexTest extends TestCase
{
    use RefreshDatabase;

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

    /**
     * Test that the updated_at index exists on the sermons table.
     */
    #[Test]
    public function it_has_index_on_sermons_updated_at(): void
    {
        $this->assertTrue(
            Schema::hasIndex('sermons', 'sermons_updated_at_index'),
            'Index "sermons_updated_at_index" is missing on "sermons" table.'
        );
    }
}
