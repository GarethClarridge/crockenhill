<?php

namespace Tests\Feature;

use App\Enums\PreacherSource;
use App\Models\Preacher;
use App\Models\PreacherAlias;
use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\TestCase;

class SermonAdminControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_update_resolves_and_persists_canonical_preacher_fields(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $sermon = Sermon::factory()->create([
            'title' => 'Original Title',
            'slug' => 'original-title',
            'date' => '2024-03-15',
            'service' => 'morning',
            'preacher' => 'Legacy Name',
            'preacher_id' => null,
            'preacher_source' => null,
            'needs_preacher_review' => true,
        ]);

        $response = $this->actingAs($admin)->post("/christ/sermons/{$sermon->slug}/edit", [
            'title' => 'Updated Sermon Title',
            'date' => '2024-03-15',
            'service' => 'morning',
            'preacher' => '  New   Guest  ',
            'series' => 'Test Series',
            'reference' => 'John 3:16',
            'points' => json_encode(['Point 1', 'Point 2']),
            'summary' => 'Summary text',
            'show_summary' => '1',
            'show_points' => '1',
        ]);

        $response->assertRedirect(route('sermonIndex'));

        $sermon->refresh();

        $preacher = Preacher::where('slug', 'new-guest')->first();
        $this->assertNotNull($preacher);
        $this->assertEquals('New Guest', $preacher->name);

        $this->assertEquals($preacher->id, $sermon->preacher_id);
        $this->assertEquals('New Guest', $sermon->preacher);
        $this->assertEquals(PreacherSource::MANUAL, $sermon->preacher_source);
        $this->assertFalse($sermon->needs_preacher_review);

        $this->assertDatabaseHas('preacher_aliases', [
            'preacher_id' => $preacher->id,
            'alias' => 'new guest',
        ]);

        $alias = PreacherAlias::where('alias', 'new guest')->first();
        $this->assertNotNull($alias);
        $this->assertEquals($preacher->id, $alias->preacher_id);
    }
}
