<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Preacher;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DatabaseIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function sermon_audio_file_path_cannot_be_empty(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('This test requires MySQL for CHECK constraints.');
        }

        $preacher = Preacher::factory()->create();
        $sermonData = Sermon::factory()->raw([
            'preacher_id' => $preacher->id,
            'audio_file_path' => '',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessage('sermons_audio_file_path_format_check');

        DB::table('sermons')->insert($sermonData);
    }

    #[Test]
    public function sermon_audio_file_path_must_be_trimmed(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('This test requires MySQL for CHECK constraints.');
        }

        $preacher = Preacher::factory()->create();
        $sermonData = Sermon::factory()->raw([
            'preacher_id' => $preacher->id,
            'audio_file_path' => ' path/with/spaces ',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessage('sermons_audio_file_path_format_check');

        DB::table('sermons')->insert($sermonData);
    }

    #[Test]
    public function sermon_audio_file_path_valid_path_is_accepted(): void
    {
        $preacher = Preacher::factory()->create();
        $sermonData = Sermon::factory()->raw([
            'preacher_id' => $preacher->id,
            'audio_file_path' => 'valid/path/audio.mp3',
        ]);

        DB::table('sermons')->insert($sermonData);

        $this->assertDatabaseHas('sermons', [
            'slug' => $sermonData['slug'],
            'audio_file_path' => 'valid/path/audio.mp3',
        ]);
    }
}
