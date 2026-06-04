<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Models\Preacher;
use App\Models\PreacherAlias;
use App\Models\ScripturePassage;
use App\Models\Sermon;
use App\Services\Processing\SermonIdentitySyncService;
use App\Services\Scripture\ScriptureReferenceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonIdentitySyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private SermonIdentitySyncService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SermonIdentitySyncService::class);
    }

    // ── Preacher identity ─────────────────────────────────────────────────────

    #[Test]
    public function it_syncs_the_preacher_name_from_an_existing_preacher_id(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Charles Spurgeon']);
        $sermon = Sermon::factory()->make([
            'preacher_id' => $preacher->id,
            'preacher' => 'Charles Spurgeon',
        ]);

        // Mark it as not dirty for any fields
        $sermon->syncOriginal();

        $this->service->syncForPersistence($sermon);

        $this->assertSame('Charles Spurgeon', $sermon->preacher);
        $this->assertSame($preacher->id, $sermon->preacher_id);
    }

    #[Test]
    public function it_updates_preacher_id_when_name_string_is_changed_to_another_preacher(): void
    {
        $preacher1 = Preacher::factory()->create(['name' => 'Preacher One']);
        $preacher2 = Preacher::factory()->create(['name' => 'Preacher Two']);

        $sermon = Sermon::factory()->create([
            'preacher_id' => $preacher1->id,
            'preacher' => 'Preacher One',
        ]);

        // Manually change the string name to match preacher 2
        $sermon->preacher = 'Preacher Two';

        $this->service->syncForPersistence($sermon);

        $this->assertSame($preacher2->id, $sermon->preacher_id);
        $this->assertSame('Preacher Two', $sermon->preacher);
    }

    #[Test]
    public function it_clears_preacher_id_when_name_string_is_changed_to_unknown(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Known Preacher']);

        $sermon = Sermon::factory()->create([
            'preacher_id' => $preacher->id,
            'preacher' => 'Known Preacher',
        ]);

        $sermon->preacher = 'Unknown Guest';

        $this->service->syncForPersistence($sermon);

        $this->assertNull($sermon->preacher_id);
        $this->assertSame('Unknown Guest', $sermon->preacher);
    }

    #[Test]
    public function it_matches_a_preacher_by_exact_name_when_no_preacher_id_is_set(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'John Owen']);

        // Start with no ID but the name set
        $sermon = new Sermon;
        $sermon->preacher = 'John Owen';

        $this->assertTrue($sermon->isDirty('preacher'));

        $this->service->syncForPersistence($sermon);

        $this->assertSame($preacher->id, $sermon->preacher_id);
        $this->assertSame('John Owen', $sermon->preacher);
    }

    #[Test]
    public function it_matches_a_preacher_via_alias(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Jonathan Edwards']);
        PreacherAlias::create(['preacher_id' => $preacher->id, 'alias' => 'j. edwards']);

        $sermon = new Sermon;
        $sermon->preacher = 'J. Edwards';

        $this->assertTrue($sermon->isDirty('preacher'));

        $this->service->syncForPersistence($sermon);

        $this->assertSame($preacher->id, $sermon->preacher_id);
        $this->assertSame('Jonathan Edwards', $sermon->preacher);
    }

    #[Test]
    public function it_does_not_match_a_preacher_when_the_name_does_not_exist(): void
    {
        Preacher::factory()->create(['name' => 'George Whitefield']);
        $sermon = Sermon::factory()->make([
            'preacher_id' => null,
            'preacher' => 'Unknown Speaker',
        ]);

        $this->service->syncForPersistence($sermon);

        $this->assertNull($sermon->preacher_id);
        $this->assertSame('Unknown Speaker', $sermon->preacher);
    }

    #[Test]
    public function it_does_not_match_a_preacher_when_name_is_null(): void
    {
        Preacher::factory()->create(['name' => 'George Whitefield']);
        $sermon = Sermon::factory()->make([
            'preacher_id' => null,
            'preacher' => null,
        ]);

        $this->service->syncForPersistence($sermon);

        $this->assertNull($sermon->preacher_id);
    }

    // ── Scripture reference lookup ────────────────────────────────────────────

    #[Test]
    public function it_finds_a_scripture_passage_by_normalized_reference(): void
    {
        $bibleId = 'de4e12af7f28f599-02';
        config(['services.api_bible.default_bible_id' => $bibleId]);

        $passage = ScripturePassage::factory()->create([
            'bible_id' => $bibleId,
            'normalized_reference' => 'John 3:16',
            'display_reference' => 'John 3:16',
        ]);

        $resolver = $this->mock(ScriptureReferenceResolver::class);
        $resolver->shouldReceive('normalize')->with('John 3:16')->andReturn('John 3:16');

        $this->app->forgetInstance(SermonIdentitySyncService::class);
        $found = app(SermonIdentitySyncService::class)->findExistingScripturePassage('John 3:16');

        $this->assertInstanceOf(ScripturePassage::class, $found);
        $this->assertSame($passage->id, $found->id);
    }

    #[Test]
    public function it_returns_null_for_a_null_raw_reference(): void
    {
        $result = $this->service->findExistingScripturePassage(null);

        $this->assertNull($result);
    }

    #[Test]
    public function it_returns_null_for_a_whitespace_only_reference(): void
    {
        $result = $this->service->findExistingScripturePassage('   ');

        $this->assertNull($result);
    }

    #[Test]
    public function it_returns_null_when_no_passage_matches(): void
    {
        $resolver = $this->mock(ScriptureReferenceResolver::class);
        $resolver->shouldReceive('normalize')->with('Fake 99:99')->andReturn('Fake 99:99');

        $this->app->forgetInstance(SermonIdentitySyncService::class);
        $result = app(SermonIdentitySyncService::class)->findExistingScripturePassage('Fake 99:99');

        $this->assertNull($result);
    }

    // ── Scripture identity sync state machine ─────────────────────────────────

    #[Test]
    public function it_sets_canonical_reference_from_passage_when_passage_id_is_newly_set(): void
    {
        $passage = ScripturePassage::factory()->create([
            'normalized_reference' => 'Romans 8:28',
            'display_reference' => 'Romans 8:28',
        ]);

        // Start with no passage linked, then set it so isDirty fires
        $sermon = Sermon::factory()->create(['scripture_passage_id' => null, 'reference' => null]);
        $sermon->scripture_passage_id = $passage->id;

        $this->service->syncForPersistence($sermon);

        $this->assertSame('Romans 8:28', $sermon->reference);
    }

    #[Test]
    public function it_clears_scripture_passage_id_when_reference_is_blanked(): void
    {
        $passage = ScripturePassage::factory()->create([
            'normalized_reference' => 'Psalm 23:1',
            'display_reference' => 'Psalm 23:1',
        ]);

        $sermon = Sermon::factory()->create([
            'scripture_passage_id' => $passage->id,
            'reference' => 'Psalm 23:1',
        ]);

        // Blank the reference so isDirty('reference') returns true
        $sermon->reference = '';

        $this->service->syncForPersistence($sermon);

        $this->assertNull($sermon->reference);
        $this->assertNull($sermon->scripture_passage_id);
    }

    #[Test]
    public function it_trims_a_free_text_reference_when_no_passage_is_linked(): void
    {
        $sermon = Sermon::factory()->create(['scripture_passage_id' => null, 'reference' => null]);
        $sermon->reference = '  Matthew 5:1-12  ';

        $this->service->syncForPersistence($sermon);

        $this->assertSame('Matthew 5:1-12', $sermon->reference);
        $this->assertNull($sermon->scripture_passage_id);
    }
}
