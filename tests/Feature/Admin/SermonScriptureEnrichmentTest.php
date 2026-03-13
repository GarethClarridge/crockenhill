<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Jobs\FetchBibleTextForSermon;
use App\Livewire\Admin\Sermons\EditSermon;
use App\Models\ScripturePassage;
use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class SermonScriptureEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
        Config::set('services.api_bible.enabled', true);
        Config::set('services.api_bible.default_bible_id', 'de4e12af7f28f599-02');
        Queue::fake();
    }

    public function test_admin_reference_edit_dispatches_enrichment_job(): void
    {
        $sermon = Sermon::factory()->create(['reference' => 'John 3:16']);

        $this->actingAs($this->admin);

        Livewire::test(EditSermon::class, ['sermon' => $sermon])
            ->set('reference', 'Romans 8:28')
            ->call('save');

        Queue::assertPushed(FetchBibleTextForSermon::class);
    }

    public function test_admin_reference_unchanged_does_not_dispatch_enrichment(): void
    {
        $sermon = Sermon::factory()->create(['reference' => 'John 3:16']);

        $this->actingAs($this->admin);

        Livewire::test(EditSermon::class, ['sermon' => $sermon])
            ->set('reference', 'John 3:16') // same value
            ->call('save');

        Queue::assertNotPushed(FetchBibleTextForSermon::class);
    }

    public function test_admin_reference_change_clears_stale_passage_link(): void
    {
        $passage = ScripturePassage::factory()->create([
            'normalized_reference' => 'John 3:16',
        ]);
        $sermon = Sermon::factory()->create([
            'reference' => 'John 3:16',
            'scripture_passage_id' => $passage->id,
        ]);

        $this->actingAs($this->admin);

        Livewire::test(EditSermon::class, ['sermon' => $sermon])
            ->set('reference', 'Romans 8:28')
            ->call('save');

        $this->assertNull($sermon->fresh()->scripture_passage_id);
    }

    public function test_admin_reference_clear_removes_passage_link(): void
    {
        $passage = ScripturePassage::factory()->create([
            'normalized_reference' => 'John 3:16',
        ]);
        $sermon = Sermon::factory()->create([
            'reference' => 'John 3:16',
            'scripture_passage_id' => $passage->id,
        ]);

        $this->actingAs($this->admin);

        Livewire::test(EditSermon::class, ['sermon' => $sermon])
            ->set('reference', null)
            ->call('save');

        $this->assertNull($sermon->fresh()->scripture_passage_id);
        Queue::assertNotPushed(FetchBibleTextForSermon::class);
    }
}
