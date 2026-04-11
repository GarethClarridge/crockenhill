<?php

declare(strict_types=1);

namespace Tests\Feature\Warden;

use App\Models\ScripturePassage;
use App\Services\ScriptureOperatorService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScripturePassageIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_enforces_unique_bible_id_and_normalized_reference(): void
    {
        ScripturePassage::factory()->create([
            'bible_id' => 'de4e12af7f895db2-01',
            'normalized_reference' => 'JHN.3.16',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        ScripturePassage::factory()->create([
            'bible_id' => 'de4e12af7f895db2-01',
            'normalized_reference' => 'JHN.3.16',
        ]);
    }

    #[Test]
    public function it_validates_required_fields(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        // bible_id is NOT NULL in database
        ScripturePassage::query()->insert([
            'normalized_reference' => 'JHN.3.16',
            'html_content' => '<p>For God so loved the world...</p>',
            'copyright' => 'Public Domain',
            'fetched_at' => now(),
        ]);
    }

    #[Test]
    public function it_handles_long_copyright_text(): void
    {
        $longCopyright = str_repeat('Copyright notice content. ', 100); // 2600 chars

        $passage = ScripturePassage::factory()->create([
            'copyright' => $longCopyright,
        ]);

        $this->assertEquals($longCopyright, $passage->fresh()?->copyright);
    }

    #[Test]
    public function it_returns_failed_when_api_returns_empty_copyright(): void
    {
        // Arrange: mock the API client to return a passage with empty copyright
        $passage = ScripturePassage::factory()->create([
            'bible_id' => 'de4e12af7f895db2-01',
            'normalized_reference' => 'JHN.3.16',
        ]);

        $result = new \App\Data\ApiBiblePassageResult(
            passageId: 'JHN.3.16',
            displayReference: 'John 3:16',
            htmlContent: '<p>For God so loved the world...</p>',
            copyright: '', // Empty copyright should fail validation
            fumsToken: null,
        );

        $client = $this->createMock(\App\Services\ApiBibleClient::class);
        $client->method('hasDailyBudget')->willReturn(true);
        $client->method('fetchPassageById')->willReturn($result);

        $sanitizer = $this->createMock(\App\Services\ScriptureHtmlSanitizer::class);
        $sanitizer->method('sanitize')->willReturn('<p>For God so loved the world...</p>');

        $this->app->instance(\App\Services\ApiBibleClient::class, $client);
        $this->app->instance(\App\Services\ScriptureHtmlSanitizer::class, $sanitizer);

        $service = $this->app->make(ScriptureOperatorService::class);

        // Act
        $outcome = $service->refreshPassage($passage);

        // Assert: validation catches the empty copyright and returns 'failed'
        $this->assertSame('failed', $outcome);
    }
}
