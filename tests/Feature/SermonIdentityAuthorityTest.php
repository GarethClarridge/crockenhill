<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Admin\Sermons\EditSermon;
use App\Livewire\Admin\Sermons\ListSermons;
use App\Models\Preacher;
use App\Models\ScripturePassage;
use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonIdentityAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.api_bible.default_bible_id', 'de4e12af7f28f599-02');
        $this->admin = User::factory()->admin()->create();
    }

    #[Test]
    public function public_sermon_page_prefers_canonical_identity_over_stale_cache_fields(): void
    {
        $preacher = Preacher::factory()->create([
            'name' => 'Canonical Preacher',
            'slug' => 'canonical-preacher',
        ]);
        $passage = ScripturePassage::factory()->create([
            'bible_id' => 'de4e12af7f28f599-02',
            'normalized_reference' => 'John 3:16',
            'display_reference' => 'John 3:16',
        ]);

        $sermon = Sermon::factory()->create([
            'title' => 'Canonical Identity Sermon',
            'preacher' => $preacher->name,
            'preacher_id' => $preacher->id,
            'reference' => $passage->display_reference,
            'scripture_passage_id' => $passage->id,
        ]);

        DB::table('sermons')->where('id', $sermon->id)->update([
            'preacher' => 'Stale Preacher Cache',
            'reference' => 'Stale Reference Cache',
        ]);

        $this->followingRedirects()
            ->get(route('sermons.show', $sermon->slug))
            ->assertOk()
            ->assertSee('Canonical Preacher')
            ->assertSee('John 3:16')
            ->assertDontSee('Stale Preacher Cache')
            ->assertDontSee('Stale Reference Cache');
    }

    #[Test]
    public function api_search_and_payload_use_canonical_identity_when_cache_fields_are_stale(): void
    {
        $preacher = Preacher::factory()->create([
            'name' => 'Canonical Preacher',
            'slug' => 'canonical-preacher',
        ]);
        $passage = ScripturePassage::factory()->create([
            'bible_id' => 'de4e12af7f28f599-02',
            'normalized_reference' => 'John 3:16',
            'display_reference' => 'John 3:16',
        ]);

        $sermon = Sermon::factory()->create([
            'title' => 'Canonical Identity Sermon',
            'preacher' => $preacher->name,
            'preacher_id' => $preacher->id,
            'reference' => $passage->display_reference,
            'scripture_passage_id' => $passage->id,
        ]);

        DB::table('sermons')->where('id', $sermon->id)->update([
            'preacher' => 'Stale Preacher Cache',
            'reference' => 'Stale Reference Cache',
        ]);

        $this->getJson('/api/sermons?search=Canonical%20Preacher')
            ->assertOk()
            ->assertJsonPath('data.0.id', $sermon->id)
            ->assertJsonPath('data.0.preacher', 'Canonical Preacher')
            ->assertJsonPath('data.0.reference', 'John 3:16');

        $this->getJson('/api/sermons?search=John%203%3A16')
            ->assertOk()
            ->assertJsonPath('data.0.id', $sermon->id);
    }

    #[Test]
    public function api_sort_by_preacher_uses_canonical_preacher_name_ordering(): void
    {
        $aaron = Preacher::factory()->create(['name' => 'Aaron Preacher', 'slug' => 'aaron-preacher']);
        $zed = Preacher::factory()->create(['name' => 'Zed Preacher', 'slug' => 'zed-preacher']);

        $zedSermon = Sermon::factory()->create([
            'title' => 'Zed Sermon',
            'preacher' => $zed->name,
            'preacher_id' => $zed->id,
        ]);
        $aaronSermon = Sermon::factory()->create([
            'title' => 'Aaron Sermon',
            'preacher' => $aaron->name,
            'preacher_id' => $aaron->id,
        ]);

        DB::table('sermons')->where('id', $zedSermon->id)->update(['preacher' => 'Alpha Cache']);
        DB::table('sermons')->where('id', $aaronSermon->id)->update(['preacher' => 'Zulu Cache']);

        $titles = collect($this->getJson('/api/sermons?sort=preacher&order=asc&per_page=10')
            ->assertOk()
            ->json('data'))
            ->pluck('title')
            ->take(2)
            ->values()
            ->all();

        $this->assertSame(['Aaron Sermon', 'Zed Sermon'], $titles);
    }

    #[Test]
    public function admin_listing_search_and_sort_prefer_canonical_identity_when_cache_fields_are_stale(): void
    {
        $this->actingAs($this->admin);

        $aaron = Preacher::factory()->create(['name' => 'Aaron Preacher', 'slug' => 'aaron-preacher']);
        $zed = Preacher::factory()->create(['name' => 'Zed Preacher', 'slug' => 'zed-preacher']);
        $passage = ScripturePassage::factory()->create([
            'bible_id' => 'de4e12af7f28f599-02',
            'normalized_reference' => 'Romans 8:28',
            'display_reference' => 'Romans 8:28',
        ]);

        $zedSermon = Sermon::factory()->create([
            'title' => 'Zed Sermon',
            'preacher' => $zed->name,
            'preacher_id' => $zed->id,
            'reference' => 'Romans 8:28',
            'scripture_passage_id' => $passage->id,
        ]);
        $aaronSermon = Sermon::factory()->create([
            'title' => 'Aaron Sermon',
            'preacher' => $aaron->name,
            'preacher_id' => $aaron->id,
        ]);

        DB::table('sermons')->where('id', $zedSermon->id)->update([
            'preacher' => 'Stale Zed Cache',
            'reference' => 'Stale Reference Cache',
        ]);
        DB::table('sermons')->where('id', $aaronSermon->id)->update([
            'preacher' => 'Stale Aaron Cache',
        ]);

        Livewire::test(ListSermons::class)
            ->set('last12Months', false)
            ->set('search', 'Romans 8:28')
            ->assertSee('Zed Sermon')
            ->assertSee('Zed Preacher')
            ->assertDontSee('Stale Zed Cache')
            ->assertDontSee('Stale Reference Cache');

        Livewire::test(ListSermons::class)
            ->set('last12Months', false)
            ->set('sortBy', 'preacher')
            ->set('sortDirection', 'asc')
            ->assertSeeInOrder(['Aaron Sermon', 'Zed Sermon']);
    }

    #[Test]
    public function admin_edit_resolves_free_text_preacher_and_existing_scripture_to_canonical_identity(): void
    {
        $this->actingAs($this->admin);

        $preacher = Preacher::factory()->create([
            'name' => 'Mark Drury Identity Test',
            'slug' => 'mark-drury-identity-test',
        ]);
        $passage = ScripturePassage::factory()->create([
            'bible_id' => 'de4e12af7f28f599-02',
            'normalized_reference' => 'John 3:16',
            'display_reference' => 'John 3:16',
        ]);

        $sermon = Sermon::factory()->create([
            'preacher' => 'Initial Preacher',
            'preacher_id' => null,
            'reference' => null,
            'scripture_passage_id' => null,
        ]);

        Livewire::test(EditSermon::class, ['sermon' => $sermon])
            ->set('preacherId', null)
            ->set('preacher', '  Mark   Drury   Identity   Test  ')
            ->set('reference', 'John 3:16')
            ->call('save')
            ->assertDispatched('notify', type: 'success', message: 'Sermon updated');

        $sermon->refresh();

        $this->assertSame($preacher->id, $sermon->preacher_id);
        $this->assertSame($preacher->name, $sermon->preacher);
        $this->assertSame($passage->id, $sermon->scripture_passage_id);
        $this->assertSame($passage->display_reference, $sermon->reference);
    }

    #[Test]
    public function renaming_a_preacher_refreshes_the_linked_sermon_cache_field(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Original Preacher', 'slug' => 'original-preacher']);
        $linkedSermon = Sermon::factory()->create([
            'preacher' => 'Stale Cache Name',
            'preacher_id' => $preacher->id,
        ]);
        $unlinkedSermon = Sermon::factory()->create([
            'preacher' => 'Independent Text Name',
            'preacher_id' => null,
        ]);

        $preacher->update([
            'name' => 'Renamed Preacher',
            'slug' => 'renamed-preacher',
        ]);

        $this->assertSame('Renamed Preacher', $linkedSermon->fresh()->preacher);
        $this->assertSame('Independent Text Name', $unlinkedSermon->fresh()->preacher);
    }
}
