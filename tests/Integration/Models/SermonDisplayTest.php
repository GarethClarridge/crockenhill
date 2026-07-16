<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\ScripturePassage;
use App\Models\Sermon;
use App\Presenters\SermonViewPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonDisplayTest extends TestCase
{
    use RefreshDatabase;

    private SermonViewPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->presenter = app(SermonViewPresenter::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    #[Test]
    public function it_displays_preacher_name_from_column_when_relation_not_loaded(): void
    {
        $sermon = Sermon::factory()->create([
            'preacher' => 'John Smith',
            'preacher_id' => null,
        ]);

        $this->assertFalse($sermon->relationLoaded('preacherProfile'));
        $this->assertEquals('John Smith', $this->presenter->displayPreacherName($sermon));
    }

    #[Test]
    public function it_displays_preacher_name_from_relation_when_loaded(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Pastor Dave']);
        $sermon = Sermon::factory()->create([
            'preacher' => 'Wrong Name',
            'preacher_id' => $preacher->id,
        ]);

        $sermon->load('preacherProfile');

        $this->assertTrue($sermon->relationLoaded('preacherProfile'));
        $this->assertEquals('Pastor Dave', $this->presenter->displayPreacherName($sermon));
    }

    #[Test]
    public function it_falls_back_to_column_when_relation_is_loaded_but_null(): void
    {
        $sermon = Sermon::factory()->create([
            'preacher' => 'John Smith',
            'preacher_id' => null,
        ]);

        // Manually set relation to null to simulate a loaded but empty relationship
        $sermon->setRelation('preacherProfile', null);

        $this->assertTrue($sermon->relationLoaded('preacherProfile'));
        $this->assertNull($sermon->preacherProfile);
        $this->assertEquals('John Smith', $this->presenter->displayPreacherName($sermon));
    }

    #[Test]
    public function it_returns_null_when_preacher_name_is_empty_or_whitespace(): void
    {
        $sermon = Sermon::factory()->withRawPreacher('  ')->create([
            'preacher_id' => null,
        ]);

        $this->assertNull($this->presenter->displayPreacherName($sermon));
    }

    #[Test]
    public function it_displays_reference_from_column_when_relation_not_loaded(): void
    {
        $sermon = Sermon::factory()->create([
            'reference' => 'John 3:16',
        ]);

        $this->assertFalse($sermon->relationLoaded('scripturePassage'));
        $this->assertEquals('John 3:16', $this->presenter->displayReference($sermon));
    }

    #[Test]
    public function it_displays_reference_from_relation_display_reference_when_loaded(): void
    {
        $passage = ScripturePassage::factory()->create([
            'display_reference' => 'I John 3:16',
            'normalized_reference' => '1 John 3:16',
        ]);
        $sermon = Sermon::factory()->create([
            'reference' => 'Wrong Reference',
            'scripture_passage_id' => $passage->id,
        ]);

        $sermon->load('scripturePassage');

        $this->assertTrue($sermon->relationLoaded('scripturePassage'));
        $this->assertEquals('I John 3:16', $this->presenter->displayReference($sermon));
    }

    #[Test]
    public function it_displays_reference_from_relation_normalized_reference_when_display_is_null(): void
    {
        $passage = ScripturePassage::factory()->create([
            'display_reference' => null,
            'normalized_reference' => 'Romans 8:28',
        ]);
        $sermon = Sermon::factory()->create([
            'reference' => 'Wrong Reference',
            'scripture_passage_id' => $passage->id,
        ]);

        $sermon->load('scripturePassage');

        $this->assertTrue($sermon->relationLoaded('scripturePassage'));
        $this->assertEquals('Romans 8:28', $this->presenter->displayReference($sermon));
    }

    #[Test]
    public function it_falls_back_to_column_when_relation_is_loaded_but_both_references_are_empty(): void
    {
        // Build an unsaved passage instance with both reference fields empty (bypasses DB constraints)
        // to simulate a legacy row that somehow has no usable reference text.
        $passage = new ScripturePassage;
        $passage->setRawAttributes([
            'id' => 99999,
            'bible_id' => 'de4e12af7f895db2-01',
            'normalized_reference' => '',
            'display_reference' => null,
            'html_content' => '<p>content</p>',
            'copyright' => 'Public Domain',
            'fetched_at' => now()->toDateTimeString(),
        ]);

        $sermon = Sermon::factory()->create([
            'reference' => 'John 3:16',
            'scripture_passage_id' => null,
        ]);

        $sermon->setRelation('scripturePassage', $passage);

        $this->assertTrue($sermon->relationLoaded('scripturePassage'));
        $this->assertEquals('John 3:16', $this->presenter->displayReference($sermon));
    }

    #[Test]
    public function it_returns_null_when_reference_is_empty_or_whitespace(): void
    {
        $sermon = Sermon::factory()->create([
            'reference' => '  ',
            'scripture_passage_id' => null,
        ]);

        $this->assertNull($this->presenter->displayReference($sermon));
    }

    #[Test]
    public function it_reports_has_transcript_correctly(): void
    {
        $sermonWith = Sermon::factory()->create(['transcript_file_path' => 'path/to/transcript.txt']);
        $sermonWithout = Sermon::factory()->create(['transcript_file_path' => null]);
        $sermonEmpty = Sermon::factory()->create(['transcript_file_path' => ' ']);

        $this->assertTrue($sermonWith->hasTranscript());
        $this->assertFalse($sermonWithout->hasTranscript());
        $this->assertFalse($sermonEmpty->hasTranscript());
    }

    #[Test]
    public function it_reports_has_thumbnail_correctly(): void
    {
        $sermonWith = Sermon::factory()->create(['thumbnail_file_path' => 'path/to/thumb.jpg']);
        $sermonWithout = Sermon::factory()->create(['thumbnail_file_path' => null]);
        $sermonEmpty = Sermon::factory()->create(['thumbnail_file_path' => ' ']);

        $this->assertTrue($sermonWith->hasThumbnail());
        $this->assertFalse($sermonWithout->hasThumbnail());
        $this->assertFalse($sermonEmpty->hasThumbnail());
    }

    #[Test]
    public function it_reports_is_automated_correctly_when_transcript_exists(): void
    {
        $sermon = Sermon::factory()->create([
            'transcript_file_path' => 'path.txt',
        ]);

        $this->assertTrue($sermon->isAutomated());
    }

    #[Test]
    public function it_reports_is_automated_correctly_when_latest_processing_log_is_loaded(): void
    {
        $sermon = Sermon::factory()->create(['transcript_file_path' => null]);
        $log = MediaProcessingLog::factory()->create(['sermon_id' => $sermon->id]);

        $sermon->load('latestProcessingLog');

        $this->assertTrue($sermon->isAutomated());
    }

    #[Test]
    public function it_reports_is_automated_correctly_when_processing_logs_relation_is_loaded(): void
    {
        $sermon = Sermon::factory()->create(['transcript_file_path' => null]);
        MediaProcessingLog::factory()->create(['sermon_id' => $sermon->id]);

        $sermon->load('processingLogs');

        $this->assertTrue($sermon->isAutomated());
    }

    #[Test]
    public function it_reports_is_automated_correctly_when_relation_not_loaded_but_exists_in_db(): void
    {
        $sermon = Sermon::factory()->create(['transcript_file_path' => null]);
        MediaProcessingLog::factory()->create(['sermon_id' => $sermon->id]);

        // Relations NOT loaded
        $this->assertFalse($sermon->relationLoaded('latestProcessingLog'));
        $this->assertFalse($sermon->relationLoaded('processingLogs'));

        $this->assertTrue($sermon->isAutomated());
    }

    #[Test]
    public function it_reports_is_not_automated_when_nothing_exists(): void
    {
        $sermon = Sermon::factory()->create([
            'transcript_file_path' => null,
        ]);

        $this->assertFalse($sermon->isAutomated());
    }
}
