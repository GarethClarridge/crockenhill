<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Sermon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_trims_title_attribute(): void
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->make();
        $sermon->title = '  The Grace of God  ';

        $this->assertEquals('The Grace of God', $sermon->title);
    }

    #[Test]
    public function it_trims_preacher_attribute(): void
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->make();
        $sermon->preacher = '  John Doe  ';

        $this->assertEquals('John Doe', $sermon->preacher);
    }

    #[Test]
    public function it_trims_series_attribute(): void
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->make();
        $sermon->series = '  Grace Series  ';

        $this->assertEquals('Grace Series', $sermon->series);
    }

    #[Test]
    public function it_trims_reference_attribute(): void
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->make();
        $sermon->reference = '  John 3:16  ';

        $this->assertEquals('John 3:16', $sermon->reference);
    }

    #[Test]
    public function it_handles_null_values_for_optional_attributes(): void
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->make();
        $sermon->series = null;
        $sermon->reference = null;
        $sermon->preacher = null;

        $this->assertNull($sermon->series);
        $this->assertNull($sermon->reference);
        $this->assertNull($sermon->preacher);
    }

    #[Test]
    public function has_transcript_returns_correct_boolean(): void
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->make(['transcript_file_path' => null]);
        $this->assertFalse($sermon->hasTranscript());

        $sermon->transcript_file_path = 'path/to/transcript.txt';
        $this->assertTrue($sermon->hasTranscript());
    }

    #[Test]
    public function has_thumbnail_returns_correct_boolean(): void
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->make(['thumbnail_file_path' => null]);
        $this->assertFalse($sermon->hasThumbnail());

        $sermon->thumbnail_file_path = 'path/to/thumbnail.webp';
        $this->assertTrue($sermon->hasThumbnail());
    }

    #[Test]
    public function has_video_returns_correct_boolean(): void
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->make(['video_file_path' => null]);
        $this->assertFalse($sermon->hasVideo());

        $sermon->video_file_path = 'path/to/video.mp4';
        $this->assertTrue($sermon->hasVideo());
    }

    #[Test]
    public function is_automated_returns_false_for_unsaved_manual_sermon(): void
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->make([
            'transcript_file_path' => null,
        ]);

        $this->assertFalse($sermon->isAutomated());
        $this->assertTrue($sermon->isManual());
    }

    #[Test]
    public function is_automated_returns_true_if_transcript_is_present(): void
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->make(['transcript_file_path' => 'path.txt']);
        $this->assertTrue($sermon->isAutomated());
        $this->assertFalse($sermon->isManual());
    }

    #[Test]
    public function it_validates_preacher_id_integer_bounds(): void
    {
        $rules = Sermon::validationRules();

        $validator = Validator::make(
            ['preacher_id' => 9223372036854775808], // Above bigint max
            ['preacher_id' => $rules['preacher_id']]
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('preacher_id', $validator->errors()->toArray());
    }

    #[Test]
    public function it_validates_slug_format(): void
    {
        $rules = Sermon::validationRules();

        $invalidSlugs = [
            'Invalid Slug',
            'invalid_slug',
            'invalid--slug',
            '-invalid-slug',
            'invalid-slug-',
        ];

        foreach ($invalidSlugs as $slug) {
            $validator = Validator::make(
                ['slug' => $slug],
                ['slug' => $rules['slug']]
            );

            $this->assertTrue($validator->fails(), "Slug '{$slug}' should be invalid.");
        }

        $validSlugs = [
            'valid-slug',
            'valid-slug-123',
            '123-valid',
        ];

        foreach ($validSlugs as $slug) {
            $validator = Validator::make(
                ['slug' => $slug],
                ['slug' => $rules['slug']]
            );

            $this->assertFalse($validator->fails(), "Slug '{$slug}' should be valid.");
        }
    }
}
