<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\ServiceSectionType;
use App\Jobs\ResolveReadingReferences;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\ReadingReferenceExtractor;
use App\Services\Scripture\ScriptureReferenceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ResolveReadingReferencesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('media-processing.analysis.service', 'mock');
        Config::set('media-processing.reading_references.enabled', true);
    }

    private function runJob(MediaProcessingLog $log, ?ReadingReferenceExtractor $extractor = null): void
    {
        (new ResolveReadingReferences($log))->handle(
            $extractor ?? app(ReadingReferenceExtractor::class),
            app(ScriptureReferenceResolver::class),
        );
    }

    #[Test]
    public function it_populates_reading_reference_from_an_explicitly_announced_reading(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::BibleReading->value,
            'section_order' => 1,
            'metadata' => [
                'transcript' => 'Our reading this morning is from Joshua chapter 2.',
                'reading_reference' => 'Bible Reading',
            ],
        ]);

        $this->runJob($log);

        $section->refresh();

        $this->assertStringContainsString('Joshua', (string) ($section->metadata['reading_reference'] ?? ''));
        $this->assertSame('transcript_ai', $section->metadata['reading_reference_source'] ?? null);
        $this->assertNotContains('reading_reference_conflict', $section->metadata['review_flags'] ?? []);
    }

    #[Test]
    public function it_leaves_non_reading_sections_untouched(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create();

        $prayer = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Prayer->value,
            'section_order' => 1,
            'metadata' => [
                'transcript' => 'Let us turn to Psalm 23 and pray.',
            ],
        ]);

        $this->runJob($log);

        $prayer->refresh();

        $this->assertNull($prayer->metadata['reading_reference'] ?? null);
        $this->assertNull($prayer->metadata['reading_reference_source'] ?? null);
    }

    #[Test]
    public function it_keeps_the_oos_fallback_and_does_not_fail_when_the_extractor_throws(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::BibleReading->value,
            'section_order' => 1,
            'metadata' => [
                'transcript' => 'Our reading is from Joshua chapter 2.',
                'reading_reference' => 'Bible Reading',
            ],
        ]);

        $throwingExtractor = new class(app(ScriptureReferenceResolver::class)) extends ReadingReferenceExtractor
        {
            public function extract(string $transcript): array
            {
                throw new RuntimeException('model unavailable');
            }
        };

        $this->runJob($log, $throwingExtractor);

        $section->refresh();

        // OoS fallback retained; the non-fatal step swallowed the error.
        $this->assertSame('Bible Reading', $section->metadata['reading_reference'] ?? null);
    }

    #[Test]
    public function it_flags_a_conflict_when_the_transcript_disagrees_with_a_real_oos_reference(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::BibleReading->value,
            'section_order' => 1,
            'needs_manual_review' => false,
            'metadata' => [
                'transcript' => 'Our reading is from Romans chapter 8 verse 28.',
                'reading_reference' => 'John 3:16',
            ],
        ]);

        $this->runJob($log);

        $section->refresh();

        $this->assertStringContainsString('Romans', (string) ($section->metadata['reading_reference'] ?? ''));
        $this->assertContains('reading_reference_conflict', $section->metadata['review_flags'] ?? []);
        $this->assertTrue($section->needs_manual_review);
    }

    #[Test]
    public function it_skips_when_disabled(): void
    {
        Config::set('media-processing.reading_references.enabled', false);

        $log = MediaProcessingLog::factory()->livestream()->processing()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::BibleReading->value,
            'section_order' => 1,
            'metadata' => [
                'transcript' => 'Our reading this morning is from Joshua chapter 2.',
                'reading_reference' => 'Bible Reading',
            ],
        ]);

        $this->runJob($log);

        $section->refresh();

        $this->assertSame('Bible Reading', $section->metadata['reading_reference'] ?? null);
        $this->assertNull($section->metadata['reading_reference_source'] ?? null);
    }
}
