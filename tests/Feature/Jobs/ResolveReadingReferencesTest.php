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

    /**
     * An extractor that always names the given passage — used to simulate the live model
     * confidently (and, for a benediction, wrongly) resolving a reference.
     */
    private function fixedExtractor(string $reference): ReadingReferenceExtractor
    {
        return new class($reference, app(ScriptureReferenceResolver::class)) extends ReadingReferenceExtractor
        {
            public function __construct(private string $reference, ScriptureReferenceResolver $resolver)
            {
                parent::__construct($resolver);
            }

            public function extract(string $transcript): array
            {
                return [
                    'reference' => $this->reference,
                    'confidence' => 0.9,
                    'source' => 'transcript_ai',
                    'raw' => $this->reference,
                ];
            }
        };
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
    public function a_transcript_subrange_of_the_planned_passage_is_not_a_conflict(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::BibleReading->value,
            'section_order' => 1,
            'needs_manual_review' => false,
            'metadata' => [
                'transcript' => 'Our reading is from Luke chapter 18.',
                'reading_reference' => 'Luke 18:31-43',
            ],
        ]);

        // The model hears the planned passage as two subranges — same text, not a conflict.
        $this->runJob($log, $this->fixedExtractor('Luke 18:31-33, 35-43'));

        $section->refresh();

        $this->assertSame('Luke 18:31-33, 35-43', $section->metadata['reading_reference'] ?? null);
        $this->assertNotContains('reading_reference_conflict', $section->metadata['review_flags'] ?? []);
        $this->assertFalse($section->needs_manual_review);
    }

    #[Test]
    public function it_suppresses_a_closing_benediction_even_when_the_model_names_a_passage(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::BibleReading->value,
            'section_order' => 1,
            'start_time' => 4048.0,
            'end_time' => 4076.0,
            'needs_manual_review' => false,
            'metadata' => [
                'transcript' => 'The grace of our Lord Jesus Christ be with you all.',
                'reading_reference' => 'Bible Reading',
            ],
        ]);

        $this->runJob($log, $this->fixedExtractor('Luke 18:9-14'));

        $section->refresh();

        $this->assertSame('none', $section->metadata['reading_reference_source'] ?? null);
        $this->assertSame('closing_benediction', $section->metadata['reading_reference_suppressed'] ?? null);
        $this->assertSame('Bible Reading', $section->metadata['reading_reference'] ?? null);
        $this->assertNotContains('reading_reference_missing', $section->metadata['review_flags'] ?? []);
        $this->assertFalse($section->needs_manual_review);
    }

    #[Test]
    public function it_still_resolves_a_benediction_passage_read_mid_service(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::BibleReading->value,
            'section_order' => 1,
            'start_time' => 300.0,
            'end_time' => 328.0,
            'metadata' => [
                'transcript' => 'The grace of our Lord Jesus Christ be with you all.',
                'reading_reference' => 'Bible Reading',
            ],
        ]);

        // A later section means the benediction-like reading is not at the end of the service.
        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Prayer->value,
            'section_order' => 5,
        ]);

        $this->runJob($log, $this->fixedExtractor('2 Corinthians 13:14'));

        $section->refresh();

        $this->assertSame('transcript_ai', $section->metadata['reading_reference_source'] ?? null);
        $this->assertStringContainsString('Corinthians', (string) ($section->metadata['reading_reference'] ?? ''));
        $this->assertNull($section->metadata['reading_reference_suppressed'] ?? null);
    }

    #[Test]
    public function it_flags_a_missing_reference_when_neither_transcript_nor_oos_resolve(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::BibleReading->value,
            'section_order' => 1,
            'needs_manual_review' => false,
            'metadata' => [
                'transcript' => 'And so we continue together in our worship this morning.',
            ],
        ]);

        $this->runJob($log);

        $section->refresh();

        $this->assertSame('none', $section->metadata['reading_reference_source'] ?? null);
        $this->assertContains('reading_reference_missing', $section->metadata['review_flags'] ?? []);
        $this->assertTrue($section->needs_manual_review);
    }

    #[Test]
    public function a_rerun_clears_a_stale_conflict_flag_and_resets_the_review_state(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::BibleReading->value,
            'section_order' => 1,
            'needs_manual_review' => true,
            'metadata' => [
                'transcript' => 'Our reading this morning is from Joshua chapter 2.',
                'reading_reference' => 'Bible Reading',
                'review_flags' => ['reading_reference_conflict'],
            ],
        ]);

        $this->runJob($log);

        $section->refresh();

        $this->assertNotContains('reading_reference_conflict', $section->metadata['review_flags'] ?? []);
        $this->assertFalse($section->needs_manual_review);
    }

    #[Test]
    public function a_rerun_preserves_unrelated_review_flags_owned_by_other_concerns(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::BibleReading->value,
            'section_order' => 1,
            'needs_manual_review' => true,
            'metadata' => [
                'transcript' => 'Our reading this morning is from Joshua chapter 2.',
                'reading_reference' => 'Bible Reading',
                'review_flags' => ['reading_reference_conflict', 'unmatched_song_section'],
            ],
        ]);

        $this->runJob($log);

        $section->refresh();

        $this->assertNotContains('reading_reference_conflict', $section->metadata['review_flags'] ?? []);
        $this->assertContains('unmatched_song_section', $section->metadata['review_flags'] ?? []);
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
