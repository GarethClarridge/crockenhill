<?php

namespace Tests\Unit\Models;

use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SermonSeoTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_returns_custom_meta_description_when_set(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Test Sermon',
            'preacher' => 'John Smith',
            'meta_description' => 'This is a custom meta description',
        ]);

        $this->assertEquals('This is a custom meta description', $sermon->meta_description);
    }

    /** @test */
    public function it_generates_meta_description_from_title_and_preacher(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'The Grace of God',
            'preacher' => 'John Smith',
            'summary' => null,
            'reference' => null,
            'date' => '2024-01-15',
            'meta_description' => null,
        ]);

        $metaDescription = $sermon->meta_description;

        $this->assertStringContainsString('The Grace of God', $metaDescription);
        $this->assertStringContainsString('John Smith', $metaDescription);
    }

    /** @test */
    public function it_includes_date_in_generated_meta_description(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Test Sermon',
            'preacher' => 'Jane Doe',
            'date' => '2024-01-15',
            'meta_description' => null,
        ]);

        $metaDescription = $sermon->meta_description;

        $this->assertStringContainsString('preached on', $metaDescription);
        // Should contain formatted date (F j, Y format = "January 15, 2024")
        $this->assertMatchesRegularExpression('/[A-Z][a-z]+ \d{1,2}, \d{4}/', $metaDescription);
    }

    /** @test */
    public function it_includes_reference_in_generated_meta_description(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Test Sermon',
            'preacher' => 'John Smith',
            'reference' => 'John 3:16',
            'meta_description' => null,
        ]);

        $metaDescription = $sermon->meta_description;

        $this->assertStringContainsString('John 3:16', $metaDescription);
    }

    /** @test */
    public function it_includes_summary_excerpt_in_generated_meta_description(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Test Sermon',
            'preacher' => 'John Smith',
            'summary' => 'This is a comprehensive summary about the grace of God and how it impacts our daily lives through faith and obedience.',
            'meta_description' => null,
        ]);

        $metaDescription = $sermon->meta_description;

        $this->assertStringContainsString('This is a comprehensive summary', $metaDescription);
    }

    /** @test */
    public function it_strips_html_from_summary_in_meta_description(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Test Sermon',
            'preacher' => 'John Smith',
            'summary' => '<p>This summary has <strong>HTML tags</strong> that should be <em>removed</em>.</p>',
            'meta_description' => null,
        ]);

        $metaDescription = $sermon->meta_description;

        $this->assertStringNotContainsString('<p>', $metaDescription);
        $this->assertStringNotContainsString('<strong>', $metaDescription);
        $this->assertStringNotContainsString('<em>', $metaDescription);
        $this->assertStringContainsString('This summary has HTML tags', $metaDescription);
    }

    /** @test */
    public function it_truncates_generated_meta_description_to_155_characters(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'A Very Long Sermon Title That Takes Up Many Characters',
            'preacher' => 'Reverend Doctor Jonathan Alexander Smith III',
            'reference' => 'Romans 8:28-39',
            'date' => '2024-01-15',
            'summary' => str_repeat('This is a very long summary that will definitely exceed the character limit when combined with all the other fields. ', 5),
            'meta_description' => null,
        ]);

        $metaDescription = $sermon->meta_description;

        $this->assertLessThanOrEqual(158, strlen($metaDescription));
    }

    /** @test */
    public function it_generates_complete_meta_description_with_all_fields(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'The Love of Christ',
            'preacher' => 'Mark Johnson',
            'reference' => 'Romans 8:35-39',
            'date' => '2024-01-15',
            'summary' => 'An exploration of the unshakeable love of Christ that nothing can separate us from.',
            'meta_description' => null,
        ]);

        $metaDescription = $sermon->meta_description;

        // Should contain title
        $this->assertStringContainsString('The Love of Christ', $metaDescription);
        // Should contain preacher
        $this->assertStringContainsString('Mark Johnson', $metaDescription);
        // Should mention it was preached
        $this->assertStringContainsString('preached on', $metaDescription);
        // Should contain reference
        $this->assertStringContainsString('Romans 8:35-39', $metaDescription);
        // Should be within limit
        $this->assertLessThanOrEqual(158, strlen($metaDescription));
    }

    /** @test */
    public function meta_description_field_is_fillable(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Test',
            'preacher' => 'Test',
        ]);

        $sermon->fill(['meta_description' => 'New meta description']);

        $this->assertEquals('New meta description', $sermon->meta_description);
    }

    /** @test */
    public function it_handles_null_values_gracefully(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Simple Sermon',
            'preacher' => 'John Doe',
            'reference' => null,
            'date' => '2024-01-15',
            'summary' => null,
            'meta_description' => null,
        ]);

        $metaDescription = $sermon->meta_description;

        // Should still generate a valid meta description
        $this->assertNotEmpty($metaDescription);
        $this->assertStringContainsString('Simple Sermon', $metaDescription);
        $this->assertStringContainsString('John Doe', $metaDescription);
        $this->assertLessThanOrEqual(158, strlen($metaDescription));
    }
}
