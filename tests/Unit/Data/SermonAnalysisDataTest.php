<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\SermonAnalysis;
use App\Data\SermonAnalysisCast;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonAnalysisDataTest extends TestCase
{
    // ── Construction ──────────────────────────────────────────────────────────

    #[Test]
    public function it_creates_from_explicit_values(): void
    {
        $analysis = SermonAnalysis::create(
            title: 'The Grace of God',
            series: 'Romans',
            reference: 'Romans 5:1-5',
            points: ['Point one', 'Point two'],
            summary: 'A summary',
            transcript: str_repeat('a', 200),
        );

        $this->assertSame('The Grace of God', $analysis->title);
        $this->assertSame('Romans', $analysis->series);
        $this->assertSame('Romans 5:1-5', $analysis->reference);
        $this->assertSame(['Point one', 'Point two'], $analysis->points);
        $this->assertSame('A summary', $analysis->summary);
    }

    #[Test]
    public function it_creates_from_ai_analysis_array(): void
    {
        $analysis = SermonAnalysis::fromAiAnalysis([
            'title' => 'Faith and Works',
            'series' => 'James',
            'reference' => 'James 2:14-26',
            'points' => ['Faith without works is dead'],
            'summary' => 'A short summary',
            'transcript' => str_repeat('x', 300),
        ]);

        $this->assertSame('Faith and Works', $analysis->title);
        $this->assertSame('James', $analysis->series);
    }

    #[Test]
    public function it_uses_untitled_sermon_when_title_missing_from_ai_analysis(): void
    {
        $analysis = SermonAnalysis::fromAiAnalysis([
            'points' => [],
            'transcript' => str_repeat('t', 200),
        ]);

        $this->assertSame('Untitled Sermon', $analysis->title);
    }

    #[Test]
    public function it_coerces_empty_string_series_and_reference_to_null(): void
    {
        $analysis = SermonAnalysis::fromAiAnalysis([
            'title' => 'Test',
            'series' => '',
            'reference' => '',
            'points' => [],
            'summary' => '',
            'transcript' => str_repeat('t', 200),
        ]);

        $this->assertNull($analysis->series);
        $this->assertNull($analysis->reference);
        $this->assertNull($analysis->summary);
    }

    // ── Title truncation ──────────────────────────────────────────────────────

    #[Test]
    public function it_truncates_title_exceeding_twelve_words(): void
    {
        $longTitle = 'One Two Three Four Five Six Seven Eight Nine Ten Eleven Twelve Thirteen';

        $analysis = SermonAnalysis::create(
            title: $longTitle,
            series: null,
            reference: null,
            points: [],
            summary: null,
            transcript: str_repeat('t', 200),
        );

        $this->assertSame(12, $analysis->getTitleWordCount());
        $this->assertTrue($analysis->isTitleValid());
        $this->assertStringNotContainsString('Thirteen', $analysis->title);
    }

    #[Test]
    public function it_does_not_truncate_title_within_word_limit(): void
    {
        $analysis = SermonAnalysis::create(
            title: 'A Short Title',
            series: null,
            reference: null,
            points: [],
            summary: null,
            transcript: str_repeat('t', 200),
        );

        $this->assertSame(3, $analysis->getTitleWordCount());
        $this->assertTrue($analysis->isTitleValid());
    }

    #[Test]
    public function it_preserves_long_ai_titles_without_truncating(): void
    {
        $longTitle = 'This is a sermon title that is definitely longer than sixty characters in total';

        $analysis = SermonAnalysis::fromAiAnalysis([
            'title' => $longTitle,
            'points' => [],
            'transcript' => str_repeat('t', 200),
        ]);

        // Word-limited to 12 words, but not character-truncated
        $this->assertLessThanOrEqual(12, count(explode(' ', $analysis->title)));
        $this->assertStringNotContainsString('...', $analysis->title);
    }

    // ── Transcript validation ─────────────────────────────────────────────────

    #[Test]
    public function it_identifies_valid_transcript(): void
    {
        $analysis = SermonAnalysis::create('Title', null, null, [], null, str_repeat('w', 101));

        $this->assertTrue($analysis->hasValidTranscript());
    }

    #[Test]
    public function it_identifies_invalid_short_transcript(): void
    {
        $analysis = SermonAnalysis::create('Title', null, null, [], null, 'Too short');

        $this->assertFalse($analysis->hasValidTranscript());
    }

    // ── Points serialisation ──────────────────────────────────────────────────

    #[Test]
    public function it_encodes_points_as_json(): void
    {
        $analysis = SermonAnalysis::create('Title', null, null, ['Point A', 'Point B'], null, str_repeat('t', 200));

        $this->assertSame('["Point A","Point B"]', $analysis->getPointsAsJson());
    }

    #[Test]
    public function it_encodes_empty_points_as_empty_json_array(): void
    {
        $analysis = SermonAnalysis::create('Title', null, null, [], null, str_repeat('t', 200));

        $this->assertSame('[]', $analysis->getPointsAsJson());
    }

    // ── toSermonAttributes ────────────────────────────────────────────────────

    #[Test]
    public function it_transforms_to_sermon_attributes(): void
    {
        $analysis = SermonAnalysis::create('Title', 'Series', 'Ref', ['Point'], 'Summary', str_repeat('t', 200));

        $attrs = $analysis->toSermonAttributes();

        $this->assertSame('Title', $attrs['title']);
        $this->assertSame('Series', $attrs['series']);
        $this->assertSame('Ref', $attrs['reference']);
        $this->assertSame('Summary', $attrs['summary']);
        $this->assertArrayHasKey('points', $attrs);
        $this->assertArrayNotHasKey('transcript', $attrs);
    }

    // ── getSummary ────────────────────────────────────────────────────────────

    #[Test]
    public function it_returns_summary_array_with_correct_keys(): void
    {
        $analysis = SermonAnalysis::create('My Title', null, null, ['P1'], null, str_repeat('t', 200));

        $summary = $analysis->getSummary();

        $this->assertArrayHasKey('title', $summary);
        $this->assertArrayHasKey('title_word_count', $summary);
        $this->assertArrayHasKey('points_count', $summary);
        $this->assertArrayHasKey('has_valid_transcript', $summary);
        $this->assertSame(1, $summary['points_count']);
    }

    // ── ArrayAccess ───────────────────────────────────────────────────────────

    #[Test]
    public function it_is_readable_via_array_access(): void
    {
        $analysis = SermonAnalysis::create('Title', null, null, [], null, str_repeat('t', 200));

        $this->assertSame('Title', $analysis['title']);
    }

    #[Test]
    public function it_throws_on_array_write(): void
    {
        $analysis = SermonAnalysis::create('Title', null, null, [], null, str_repeat('t', 200));

        $this->expectException(LogicException::class);
        $analysis['title'] = 'changed'; // @phpstan-ignore-line
    }

    #[Test]
    public function it_throws_on_array_unset(): void
    {
        $analysis = SermonAnalysis::create('Title', null, null, [], null, str_repeat('t', 200));

        $this->expectException(LogicException::class);
        unset($analysis['title']); // @phpstan-ignore-line
    }

    // ── SermonAnalysisCast ────────────────────────────────────────────────────

    #[Test]
    public function cast_get_deserialises_json_to_sermon_analysis(): void
    {
        $cast = new SermonAnalysisCast;
        $model = $this->createStub(Model::class);

        $json = json_encode([
            'title' => 'Cast Title',
            'points' => ['P1'],
            'transcript' => str_repeat('t', 200),
        ]);

        $result = $cast->get($model, 'analysis', $json, []);

        $this->assertInstanceOf(SermonAnalysis::class, $result);
        $this->assertSame('Cast Title', $result->title);
    }

    #[Test]
    public function cast_get_returns_null_for_empty_value(): void
    {
        $cast = new SermonAnalysisCast;
        $model = $this->createStub(Model::class);

        $this->assertNull($cast->get($model, 'analysis', '', []));
        $this->assertNull($cast->get($model, 'analysis', null, []));
    }

    #[Test]
    public function cast_set_serialises_sermon_analysis_to_json(): void
    {
        $cast = new SermonAnalysisCast;
        $model = $this->createStub(Model::class);

        $analysis = SermonAnalysis::create('Title', null, null, [], null, str_repeat('t', 200));
        $result = $cast->set($model, 'analysis', $analysis, []);

        $this->assertIsString($result);
        $decoded = json_decode($result, true);
        $this->assertSame('Title', $decoded['title']);
    }

    #[Test]
    public function cast_set_returns_null_for_null_value(): void
    {
        $cast = new SermonAnalysisCast;
        $model = $this->createStub(Model::class);

        $this->assertNull($cast->set($model, 'analysis', null, []));
    }

    #[Test]
    public function cast_round_trips_sermon_analysis(): void
    {
        $cast = new SermonAnalysisCast;
        $model = $this->createStub(Model::class);

        $original = SermonAnalysis::create('Round Trip', 'Series', 'John 1:1', ['P1', 'P2'], 'Summary', str_repeat('t', 200));

        $json = $cast->set($model, 'analysis', $original, []);
        $restored = $cast->get($model, 'analysis', $json, []);

        $this->assertInstanceOf(SermonAnalysis::class, $restored);
        $this->assertSame($original->title, $restored->title);
        $this->assertSame($original->series, $restored->series);
        $this->assertSame($original->reference, $restored->reference);
    }
}
