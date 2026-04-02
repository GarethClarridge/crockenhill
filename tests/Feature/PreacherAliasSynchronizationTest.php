<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Preacher;
use App\Models\PreacherAlias;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PreacherAliasSynchronizationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function creating_a_preacher_alias_automatically_links_existing_unlinked_sermons(): void
    {
        // 1. Setup: a preacher and some unlinked sermons with matching name strings
        $preacher = Preacher::factory()->create(['name' => 'Canonical Name']);

        $sermon1 = Sermon::factory()->create([
            'preacher' => 'alias name',
            'preacher_id' => null,
        ]);

        $sermon2 = Sermon::factory()->create([
            'preacher' => '  Alias Name  ', // Testing whitespace/case
            'preacher_id' => null,
        ]);

        $unrelatedSermon = Sermon::factory()->create([
            'preacher' => 'Different Speaker',
            'preacher_id' => null,
        ]);

        $alreadyLinkedSermon = Sermon::factory()->create([
            'preacher' => 'alias name',
            'preacher_id' => Preacher::factory()->create(['name' => 'Other Preacher'])->id,
        ]);

        // 2. Action: Create an alias that matches the unlinked sermons
        PreacherAlias::create([
            'preacher_id' => $preacher->id,
            'alias' => 'alias name',
        ]);

        // 3. Assert: Unlinked sermons should now be linked and names canonicalized
        $this->assertSame($preacher->id, $sermon1->fresh()->preacher_id);
        $this->assertSame('Canonical Name', $sermon1->fresh()->preacher);

        $this->assertSame($preacher->id, $sermon2->fresh()->preacher_id);
        $this->assertSame('Canonical Name', $sermon2->fresh()->preacher);

        // unrelated should remain unlinked
        $this->assertNull($unrelatedSermon->fresh()->preacher_id);
        $this->assertSame('Different Speaker', $unrelatedSermon->fresh()->preacher);

        // already linked should NOT be hijacked
        $this->assertNotSame($preacher->id, $alreadyLinkedSermon->fresh()->preacher_id);
    }

    #[Test]
    public function sermon_preacher_sync_clears_id_when_name_string_is_changed_to_unrecognized_value(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Known Preacher']);
        $sermon = Sermon::factory()->create([
            'preacher_id' => $preacher->id,
            'preacher' => 'Known Preacher',
        ]);

        // Change the string to something that doesn't match any preacher or alias
        $sermon->update(['preacher' => 'Completely Unknown Person']);

        $this->assertNull($sermon->fresh()->preacher_id);
        $this->assertSame('Completely Unknown Person', $sermon->fresh()->preacher);
    }

    #[Test]
    public function sermon_preacher_sync_updates_id_when_name_string_is_changed_to_another_known_preacher(): void
    {
        $preacher1 = Preacher::factory()->create(['name' => 'Preacher One']);
        $preacher2 = Preacher::factory()->create(['name' => 'Preacher Two']);

        $sermon = Sermon::factory()->create([
            'preacher_id' => $preacher1->id,
            'preacher' => 'Preacher One',
        ]);

        // Change the string to match preacher 2
        $sermon->update(['preacher' => 'Preacher Two']);

        $this->assertSame($preacher2->id, $sermon->fresh()->preacher_id);
        $this->assertSame('Preacher Two', $sermon->fresh()->preacher);
    }

    #[Test]
    public function sermon_preacher_sync_respects_explicit_preacher_id_change_even_if_string_conflicts(): void
    {
        $preacher1 = Preacher::factory()->create(['name' => 'Preacher One']);
        $preacher2 = Preacher::factory()->create(['name' => 'Preacher Two']);

        $sermon = Sermon::factory()->create([
            'preacher_id' => $preacher1->id,
            'preacher' => 'Preacher One',
        ]);

        // Change both ID and Name, but ID wins and Name gets synced to match ID
        $sermon->preacher_id = $preacher2->id;
        $sermon->preacher = 'Some Conflicting Name';
        $sermon->save();

        $this->assertSame($preacher2->id, $sermon->fresh()->preacher_id);
        $this->assertSame('Preacher Two', $sermon->fresh()->preacher);
    }
}
