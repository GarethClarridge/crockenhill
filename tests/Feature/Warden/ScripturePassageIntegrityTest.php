<?php

declare(strict_types=1);

namespace Tests\Feature\Warden;

use App\Models\ScripturePassage;
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
    public function it_fails_validation_for_invalid_data(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        ScripturePassage::validate([
            'bible_id' => '', // Required
            'normalized_reference' => 'JHN.3.16',
            'html_content' => 'content',
            'copyright' => 'copyright',
            'fetched_at' => now(),
        ]);
    }
}
