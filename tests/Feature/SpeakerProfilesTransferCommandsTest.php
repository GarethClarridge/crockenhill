<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\SpeakerProfile;
use App\Models\SpeakerSample;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SpeakerProfilesTransferCommandsTest extends TestCase
{
    use RefreshDatabase;

    private string $bundlePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bundlePath = storage_path('framework/testing/speaker-profiles-'.uniqid().'.json');
    }

    protected function tearDown(): void
    {
        if (File::exists($this->bundlePath)) {
            File::delete($this->bundlePath);
        }

        parent::tearDown();
    }

    public function test_export_writes_a_portable_bundle_without_local_ids(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Mark Drury', 'slug' => 'mark-drury']);
        SpeakerProfile::factory()->create([
            'preacher_id' => $preacher->id,
            'provider' => 'resemblyzer',
            'model_version' => 'v1.0',
            'centroid_embedding' => array_fill(0, 256, 0.25),
            'sample_count' => 4,
        ]);

        $this->artisan("speaker-profiles:export --output={$this->bundlePath}")
            ->assertSuccessful();

        $raw = File::get($this->bundlePath);
        $bundle = json_decode($raw, true);

        $this->assertSame('crockenhill.speaker-profiles', $bundle['format']);
        $this->assertSame(1, $bundle['version']);
        $this->assertCount(1, $bundle['profiles']);

        $exported = $bundle['profiles'][0];
        $this->assertSame('Mark Drury', $exported['preacher_name']);
        $this->assertSame('mark-drury', $exported['preacher_slug']);
        $this->assertSame('resemblyzer', $exported['provider']);
        $this->assertCount(256, $exported['centroid_embedding']);

        // No local primary keys may cross the boundary.
        $this->assertArrayNotHasKey('id', $exported);
        $this->assertArrayNotHasKey('preacher_id', $exported);
        $this->assertStringNotContainsString('"preacher_id"', $raw);
    }

    public function test_export_reports_the_sample_date_range(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Steve Marchant', 'slug' => 'steve-marchant']);
        $profile = SpeakerProfile::factory()->create(['preacher_id' => $preacher->id]);

        foreach (['2024-03-10', '2026-01-18'] as $date) {
            $sermon = Sermon::factory()->withPreacher($preacher)->withAudio()->create([
                'date' => Carbon::parse($date),
            ]);
            SpeakerSample::factory()->create([
                'speaker_profile_id' => $profile->id,
                'sermon_id' => $sermon->id,
            ]);
        }

        $this->artisan("speaker-profiles:export --output={$this->bundlePath}")
            ->assertSuccessful();

        $bundle = json_decode(File::get($this->bundlePath), true);
        $range = $bundle['profiles'][0]['sample_date_range'];

        $this->assertStringStartsWith('2024-03-10', $range['first']);
        $this->assertStringStartsWith('2026-01-18', $range['last']);
        $this->assertSame(2, $range['count']);
    }

    public function test_export_excludes_inactive_profiles_unless_requested(): void
    {
        $preacher = Preacher::factory()->create(['slug' => 'dormant-preacher']);
        SpeakerProfile::factory()->inactive()->create(['preacher_id' => $preacher->id]);

        $this->artisan("speaker-profiles:export --output={$this->bundlePath}")
            ->assertFailed();

        $this->assertFalse(File::exists($this->bundlePath));

        $this->artisan("speaker-profiles:export --include-inactive --output={$this->bundlePath}")
            ->assertSuccessful();

        $bundle = json_decode(File::get($this->bundlePath), true);
        $this->assertCount(1, $bundle['profiles']);
    }

    public function test_export_requires_an_output_option(): void
    {
        $this->artisan('speaker-profiles:export')->assertFailed();
    }

    public function test_import_remaps_preacher_by_slug_not_by_id(): void
    {
        // The receiving environment has a different primary key for the same preacher.
        Preacher::factory()->count(3)->create();
        $local = Preacher::factory()->create(['name' => 'Mark Drury', 'slug' => 'mark-drury']);

        $this->writeBundle([
            $this->profilePayload(['preacher_name' => 'Mark Drury', 'preacher_slug' => 'mark-drury']),
        ]);

        $this->artisan("speaker-profiles:import {$this->bundlePath} --apply")->assertSuccessful();

        $profile = SpeakerProfile::query()->firstOrFail();
        $this->assertSame($local->id, $profile->preacher_id);
        $this->assertCount(256, $profile->centroid_embedding);
        $this->assertEqualsWithDelta(0.5, $profile->centroid_embedding[0], 1e-9);
    }

    public function test_import_creates_a_missing_preacher(): void
    {
        $this->writeBundle([
            $this->profilePayload(['preacher_name' => 'Brand New Preacher', 'preacher_slug' => 'brand-new-preacher']),
        ]);

        $this->artisan("speaker-profiles:import {$this->bundlePath} --apply")->assertSuccessful();

        $this->assertDatabaseHas('preachers', ['name' => 'Brand New Preacher']);
        $this->assertSame(1, SpeakerProfile::query()->count());
    }

    public function test_import_is_idempotent(): void
    {
        Preacher::factory()->create(['name' => 'Mark Drury', 'slug' => 'mark-drury']);
        $this->writeBundle([$this->profilePayload()]);

        $this->artisan("speaker-profiles:import {$this->bundlePath} --apply")->assertSuccessful();
        $this->artisan("speaker-profiles:import {$this->bundlePath} --apply")->assertSuccessful();

        $this->assertSame(1, SpeakerProfile::query()->count());
    }

    public function test_import_overwrites_an_existing_profile_centroid(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Mark Drury', 'slug' => 'mark-drury']);
        SpeakerProfile::factory()->create([
            'preacher_id' => $preacher->id,
            'provider' => 'resemblyzer',
            'model_version' => 'v1.0',
            'centroid_embedding' => array_fill(0, 256, 0.99),
        ]);

        $this->writeBundle([$this->profilePayload()]);

        $this->artisan("speaker-profiles:import {$this->bundlePath} --apply")->assertSuccessful();

        $profile = SpeakerProfile::query()->firstOrFail();
        $this->assertEqualsWithDelta(0.5, $profile->centroid_embedding[0], 1e-9);
    }

    public function test_import_deactivate_existing_stands_down_local_profiles(): void
    {
        $stale = SpeakerProfile::factory()->create(['is_active' => true]);
        Preacher::factory()->create(['name' => 'Mark Drury', 'slug' => 'mark-drury']);

        $this->writeBundle([$this->profilePayload()]);

        $this->artisan("speaker-profiles:import {$this->bundlePath} --apply --deactivate-existing")
            ->assertSuccessful();

        $this->assertFalse($stale->fresh()->is_active);
        $this->assertTrue(
            SpeakerProfile::query()->where('preacher_id', '!=', $stale->preacher_id)->firstOrFail()->is_active
        );
    }

    public function test_import_is_a_dry_run_by_default(): void
    {
        $this->writeBundle([
            $this->profilePayload(['preacher_name' => 'Nobody Here', 'preacher_slug' => 'nobody-here']),
        ]);

        $this->artisan("speaker-profiles:import {$this->bundlePath}")
            ->assertSuccessful();

        $this->assertSame(0, SpeakerProfile::query()->count());
        $this->assertDatabaseMissing('preachers', ['slug' => 'nobody-here']);
    }

    public function test_import_skips_untrained_zero_vector_profiles(): void
    {
        Preacher::factory()->create(['name' => 'Mark Drury', 'slug' => 'mark-drury']);

        $this->writeBundle([
            $this->profilePayload(['centroid_embedding' => array_fill(0, 256, 0.0)]),
            $this->profilePayload(['preacher_name' => 'Real Profile', 'preacher_slug' => 'real-profile']),
        ]);

        $this->artisan("speaker-profiles:import {$this->bundlePath} --apply")->assertSuccessful();

        $this->assertSame(1, SpeakerProfile::query()->count());
        $this->assertSame(
            'Real Profile',
            SpeakerProfile::query()->firstOrFail()->preacher?->name
        );
    }

    public function test_import_rejects_a_missing_file(): void
    {
        $this->artisan('speaker-profiles:import /nonexistent/bundle.json')->assertFailed();
    }

    public function test_import_rejects_a_foreign_format(): void
    {
        File::put($this->bundlePath, json_encode(['format' => 'something.else', 'version' => 1, 'profiles' => []]));

        $this->artisan("speaker-profiles:import {$this->bundlePath}")->assertFailed();
        $this->assertSame(0, SpeakerProfile::query()->count());
    }

    public function test_import_rejects_an_unsupported_version(): void
    {
        File::put($this->bundlePath, json_encode([
            'format' => 'crockenhill.speaker-profiles',
            'version' => 99,
            'profiles' => [$this->profilePayload()],
        ]));

        $this->artisan("speaker-profiles:import {$this->bundlePath}")->assertFailed();
    }

    public function test_import_rejects_a_non_numeric_embedding(): void
    {
        $this->writeBundle([
            $this->profilePayload(['centroid_embedding' => ['not', 'numbers']]),
        ]);

        $this->artisan("speaker-profiles:import {$this->bundlePath}")->assertFailed();
        $this->assertSame(0, SpeakerProfile::query()->count());
    }

    public function test_export_and_import_round_trip_preserves_the_centroid(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Round Trip', 'slug' => 'round-trip']);
        $original = array_map(fn (int $i): float => round(sin($i), 6), range(1, 256));

        SpeakerProfile::factory()->create([
            'preacher_id' => $preacher->id,
            'centroid_embedding' => $original,
            'accept_threshold' => 0.8,
            'margin_threshold' => 0.05,
        ]);

        $this->artisan("speaker-profiles:export --output={$this->bundlePath}")->assertSuccessful();

        SpeakerProfile::query()->delete();

        $this->artisan("speaker-profiles:import {$this->bundlePath} --apply")->assertSuccessful();

        $restored = SpeakerProfile::query()->firstOrFail();

        $this->assertEqualsWithDelta(0.8, $restored->accept_threshold, 1e-9);
        $this->assertEqualsWithDelta(0.05, $restored->margin_threshold, 1e-9);

        foreach ($original as $index => $value) {
            $this->assertEqualsWithDelta($value, $restored->centroid_embedding[$index], 1e-9);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function profilePayload(array $overrides = []): array
    {
        return array_merge([
            'preacher_name' => 'Mark Drury',
            'preacher_slug' => 'mark-drury',
            'provider' => 'resemblyzer',
            'model_version' => 'v1.0',
            'centroid_embedding' => array_fill(0, 256, 0.5),
            'sample_count' => 10,
            'quality_score' => null,
            'accept_threshold' => null,
            'margin_threshold' => null,
            'is_active' => true,
            'sample_date_range' => ['first' => '2025-01-05', 'last' => '2026-01-18', 'count' => 10],
        ], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>  $profiles
     */
    private function writeBundle(array $profiles): void
    {
        File::put($this->bundlePath, json_encode([
            'format' => 'crockenhill.speaker-profiles',
            'version' => 1,
            'exported_at' => now()->toIso8601String(),
            'profiles' => $profiles,
        ], JSON_THROW_ON_ERROR));
    }
}
