<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonAutomationConsistencyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_returns_false_for_is_automated_on_unsaved_models_even_if_unlinked_logs_exist(): void
    {
        // Setup: Create a log that isn't linked to any sermon (sermon_id = null)
        MediaProcessingLog::factory()->create(['sermon_id' => null]);

        $sermon = new Sermon;
        $sermon->transcript_file_path = null;

        $this->assertFalse($sermon->exists);
        $this->assertFalse($sermon->isAutomated(), 'Unsaved sermon must not be considered automated based on unlinked logs.');
    }

    #[Test]
    public function is_automated_and_automated_scope_are_consistent_for_empty_string_transcripts(): void
    {
        $sermon = Sermon::factory()->create([
            'transcript_file_path' => '',
        ]);

        $this->assertFalse($sermon->isAutomated(), 'Empty string transcript should not count as automated.');
        $this->assertFalse(
            Sermon::automated()->where('id', $sermon->id)->exists(),
            'Sermon::automated() scope should exclude empty string transcripts.'
        );
        $this->assertTrue(
            Sermon::manual()->where('id', $sermon->id)->exists(),
            'Sermon::manual() scope should include empty string transcripts.'
        );
    }

    #[Test]
    public function is_automated_and_automated_scope_are_consistent_for_whitespace_transcripts(): void
    {
        $sermon = Sermon::factory()->create([
            'transcript_file_path' => '   ',
        ]);

        $this->assertFalse($sermon->isAutomated(), 'Whitespace-only transcript should not count as automated.');
        $this->assertFalse(
            Sermon::automated()->where('id', $sermon->id)->exists(),
            'Sermon::automated() scope should exclude whitespace-only transcripts.'
        );
        $this->assertTrue(
            Sermon::manual()->where('id', $sermon->id)->exists(),
            'Sermon::manual() scope should include whitespace-only transcripts.'
        );
    }

    #[Test]
    public function is_automated_prefers_transcript_presence_over_loaded_relations(): void
    {
        $sermon = Sermon::factory()->create([
            'transcript_file_path' => 'transcripts/test.txt',
        ]);

        // Eager load an empty log relation to see if it's wrongly used
        $sermon->load(['latestProcessingLog', 'processingLogs']);

        $this->assertTrue($sermon->relationLoaded('latestProcessingLog'));
        $this->assertNull($sermon->latestProcessingLog);

        $this->assertTrue($sermon->isAutomated(), 'Transcript should take priority and return true even if log relation is empty.');
    }

    #[Test]
    public function is_automated_trusts_eager_loaded_relations_over_database_state(): void
    {
        $sermon = Sermon::factory()->create();
        MediaProcessingLog::factory()->for($sermon)->create();

        // 1. Load the relation
        $sermon->load('latestProcessingLog');
        $this->assertNotNull($sermon->latestProcessingLog);

        // 2. Delete the log from the database behind its back
        MediaProcessingLog::query()->where('sermon_id', $sermon->id)->delete();

        // 3. Method should still return true because it trusts the loaded relation (N+1 optimization)
        $this->assertTrue($sermon->isAutomated(), 'isAutomated() must trust eager-loaded relations to avoid redundant queries.');
    }

    #[Test]
    public function is_automated_trusts_eager_loaded_empty_relation_over_database_state(): void
    {
        $sermon = Sermon::factory()->create();
        // Database currently has NO logs

        // 1. Load the relation (it will be null)
        $sermon->load('latestProcessingLog');
        $this->assertNull($sermon->latestProcessingLog);

        // 2. Create a log in the database behind its back
        MediaProcessingLog::factory()->for($sermon)->create();

        // 3. Method should still return false because it trusts the loaded relation
        $this->assertFalse($sermon->isAutomated(), 'isAutomated() must trust eager-loaded empty relations.');
    }
}
