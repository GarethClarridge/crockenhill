<?php

declare(strict_types=1);

namespace Tests\Feature\Warden;

use App\Data\ApiBiblePassageResult;
use App\Models\ScripturePassage;
use App\Services\Scripture\ApiBibleClient;
use App\Services\Scripture\ScriptureHtmlSanitizer;
use App\Services\Scripture\ScriptureOperatorService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScripturePassageIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_enforces_unique_bible_id_and_normalized_reference(): void
    {
        ScripturePassage::factory()->create([
            'bible_id' => 'de4e12af7f895db2-01',
            'normalized_reference' => 'JHN.3.16',
        ]);

        $this->expectException(QueryException::class);

        ScripturePassage::factory()->create([
            'bible_id' => 'de4e12af7f895db2-01',
            'normalized_reference' => 'JHN.3.16',
        ]);
    }

    #[Test]
    public function it_validates_required_fields(): void
    {
        $this->expectException(QueryException::class);

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
        $longCopyright = trim(str_repeat('Copyright notice content. ', 100)); // 2600 chars

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

        $result = new ApiBiblePassageResult(
            passageId: 'JHN.3.16',
            displayReference: 'John 3:16',
            htmlContent: '<p>For God so loved the world...</p>',
            copyright: '', // Empty copyright should fail validation
            fumsToken: null,
        );

        $client = $this->createStub(ApiBibleClient::class);
        $client->method('hasDailyBudget')->willReturn(true);
        $client->method('fetchPassageById')->willReturn($result);

        $sanitizer = $this->createStub(ScriptureHtmlSanitizer::class);
        $sanitizer->method('sanitize')->willReturn('<p>For God so loved the world...</p>');

        $this->app->instance(ApiBibleClient::class, $client);
        $this->app->instance(ScriptureHtmlSanitizer::class, $sanitizer);

        $service = $this->app->make(ScriptureOperatorService::class);

        // Act
        $outcome = $service->refreshPassage($passage);

        // Assert: validation catches the empty copyright and returns 'failed'
        $this->assertSame('failed', $outcome);
    }

    #[Test]
    public function it_validates_scripture_passage_data(): void
    {
        $validData = [
            'bible_id' => 'de4e12af7f895db2-01',
            'normalized_reference' => 'JHN.3.16',
            'html_content' => '<p>For God so loved the world...</p>',
            'copyright' => 'Public Domain',
            'fetched_at' => now()->toDateTimeString(),
        ];

        $this->assertTrue(Validator::make($validData, ScripturePassage::validationRules())->passes());

        // Test required fields
        foreach (['bible_id', 'normalized_reference', 'html_content', 'copyright', 'fetched_at'] as $field) {
            $invalidData = $validData;
            unset($invalidData[$field]);
            $this->assertFalse(Validator::make($invalidData, ScripturePassage::validationRules())->passes(), "Validation should fail when $field is missing");
        }

        // Test max length
        foreach (['bible_id', 'normalized_reference', 'api_passage_id', 'display_reference', 'fums_token'] as $field) {
            $invalidData = $validData;
            $invalidData[$field] = str_repeat('a', 256);
            $this->assertFalse(Validator::make($invalidData, ScripturePassage::validationRules())->passes(), "Validation should fail when $field exceeds 255 chars");
        }
    }

    #[Test]
    public function it_trims_identifying_strings(): void
    {
        $passage = new ScripturePassage([
            'bible_id' => '  de4e12af7f895db2-01  ',
            'normalized_reference' => '  JHN.3.16  ',
            'api_passage_id' => '  JHN.3.16  ',
            'display_reference' => '  John 3:16  ',
            'html_content' => '<p>content</p>',
            'copyright' => 'copyright',
            'fetched_at' => now(),
        ]);

        $this->assertEquals('de4e12af7f895db2-01', $passage->bible_id);
        $this->assertEquals('JHN.3.16', $passage->normalized_reference);
        $this->assertEquals('JHN.3.16', $passage->api_passage_id);
        $this->assertEquals('John 3:16', $passage->display_reference);
    }

    #[Test]
    public function it_enforces_database_check_constraints(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('scripture_passages_bible_id_check');

        ScripturePassage::query()->insert([
            'bible_id' => '',
            'normalized_reference' => 'JHN.3.16',
            'html_content' => '<p>content</p>',
            'copyright' => 'copyright',
            'fetched_at' => now(),
        ]);
    }

    #[Test]
    public function it_enforces_copyright_check_constraint(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('scripture_passages_copyright_check');

        ScripturePassage::query()->insert([
            'bible_id' => 'de4e12af7f895db2-01',
            'normalized_reference' => 'JHN.3.16',
            'html_content' => '<p>content</p>',
            'copyright' => '',
            'fetched_at' => now(),
        ]);
    }
}
