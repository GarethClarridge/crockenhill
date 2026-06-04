<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonDownloadCountIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function sermon_has_download_count_column_with_default_zero(): void
    {
        $this->assertTrue(Schema::hasColumn('sermons', 'download_count'), 'The sermons table should have a download_count column.');

        $sermon = Sermon::factory()->create();
        $this->assertSame(0, $sermon->download_count, 'The default download_count should be 0.');
    }

    #[Test]
    public function sermon_can_save_positive_download_count(): void
    {
        $sermon = Sermon::factory()->create(['download_count' => 10]);
        $this->assertEquals(10, $sermon->download_count);

        $sermon->update(['download_count' => 20]);
        $this->assertEquals(20, $sermon->fresh()->download_count);
    }

    #[Test]
    public function download_count_validation_rejects_negative_values(): void
    {
        $validator = Validator::make(
            ['download_count' => -1],
            ['download_count' => 'required|integer|min:0']
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('download_count', $validator->errors()->toArray());
    }

    #[Test]
    public function download_count_validation_accepts_zero_and_positive_values(): void
    {
        foreach ([0, 1, 100] as $value) {
            $validator = Validator::make(
                ['download_count' => $value],
                ['download_count' => 'required|integer|min:0']
            );

            $this->assertFalse($validator->fails(), "Expected {$value} to pass validation.");
        }
    }
}
