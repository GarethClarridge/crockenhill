<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Admin\Sermons\EditSermon;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Sermon $sermon;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->sermon = Sermon::factory()->create();
    }

    #[Test]
    public function it_populates_timestamps_automatically()
    {
        $sermon = Sermon::factory()->create();

        $this->assertNotNull($sermon->created_at);
        $this->assertNotNull($sermon->updated_at);
    }

    #[Test]
    public function it_accepts_long_audio_file_paths()
    {
        $longPath = 'sermons/'.str_repeat('a', 200).'.mp3';

        $sermon = Sermon::factory()->create([
            'audio_file_path' => $longPath,
        ]);

        $this->assertEquals($longPath, $sermon->audio_file_path);
    }

    #[Test]
    public function it_sets_livestream_processing_id_to_null_when_log_is_deleted()
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_id' => Str::uuid()->toString(),
        ]);

        $sermon = Sermon::factory()->create([
            'livestream_processing_id' => $log->processing_id,
        ]);

        $this->assertEquals($log->processing_id, $sermon->livestream_processing_id);

        $log->delete();

        $sermon->refresh();
        $this->assertNull($sermon->livestream_processing_id);
    }

    #[Test]
    public function it_rejects_negative_duration_at_database_level(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Database-level CHECK constraints are only implemented for MySQL in this project.');
        }

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('sermons_duration_check');

        $data = Sermon::factory()->make()->toArray();
        $data['points'] = json_encode($data['points']);
        $data['duration'] = -1;
        $data['slug'] = 'db-integrity-test-duration';
        $data['date'] = now()->format('Y-m-d');

        DB::table('sermons')->insert($data);
    }

    #[Test]
    public function it_rejects_invalid_preacher_confidence_at_database_level(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Database-level CHECK constraints are only implemented for MySQL in this project.');
        }

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('sermons_preacher_confidence_check');

        $data = Sermon::factory()->make()->toArray();
        $data['points'] = json_encode($data['points']);
        $data['preacher_confidence'] = 1.1;
        $data['slug'] = 'db-integrity-test-confidence';
        $data['date'] = now()->format('Y-m-d');

        DB::table('sermons')->insert($data);
    }

    #[Test]
    public function it_rejects_invalid_timing_invariants_at_database_level(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Database-level CHECK constraints are only implemented for MySQL in this project.');
        }

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('sermons_timing_invariants_check');

        $data = Sermon::factory()->make()->toArray();
        $data['points'] = json_encode($data['points']);
        $data['segment_start_time'] = 100;
        $data['segment_end_time'] = 50;
        $data['slug'] = 'db-integrity-test-timing';
        $data['date'] = now()->format('Y-m-d');

        DB::table('sermons')->insert($data);
    }

    #[Test]
    public function edit_sermon_component_validates_preacher_confidence(): void
    {
        Livewire::actingAs($this->admin)
            ->test(EditSermon::class, ['sermon' => $this->sermon])
            ->set('form.preacherConfidence', 1.5)
            ->call('save')
            ->assertHasErrors(['form.preacherConfidence' => 'max']);

        Livewire::actingAs($this->admin)
            ->test(EditSermon::class, ['sermon' => $this->sermon])
            ->set('form.preacherConfidence', -0.1)
            ->call('save')
            ->assertHasErrors(['form.preacherConfidence' => 'min']);
    }
}
