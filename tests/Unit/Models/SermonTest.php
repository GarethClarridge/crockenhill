<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Sermon;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonTest extends TestCase
{
    #[Test]
    public function it_trims_title_attribute(): void
    {
        $sermon = new Sermon;
        $sermon->title = '  The Grace of God  ';

        $this->assertEquals('The Grace of God', $sermon->title);
    }

    #[Test]
    public function it_trims_preacher_attribute(): void
    {
        $sermon = new Sermon;
        $sermon->preacher = '  John Doe  ';

        $this->assertEquals('John Doe', $sermon->preacher);
    }

    #[Test]
    public function it_trims_series_attribute(): void
    {
        $sermon = new Sermon;
        $sermon->series = '  Grace Series  ';

        $this->assertEquals('Grace Series', $sermon->series);
    }

    #[Test]
    public function it_trims_reference_attribute(): void
    {
        $sermon = new Sermon;
        $sermon->reference = '  John 3:16  ';

        $this->assertEquals('John 3:16', $sermon->reference);
    }

    #[Test]
    public function it_handles_null_values_for_optional_attributes(): void
    {
        $sermon = new Sermon;
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
        $sermon = new Sermon(['transcript_file_path' => null]);
        $this->assertFalse($sermon->hasTranscript());

        $sermon->transcript_file_path = 'path/to/transcript.txt';
        $this->assertTrue($sermon->hasTranscript());
    }

    #[Test]
    public function has_thumbnail_returns_correct_boolean(): void
    {
        $sermon = new Sermon(['thumbnail_file_path' => null]);
        $this->assertFalse($sermon->hasThumbnail());

        $sermon->thumbnail_file_path = 'path/to/thumbnail.webp';
        $this->assertTrue($sermon->hasThumbnail());
    }

    #[Test]
    public function has_video_returns_correct_boolean(): void
    {
        $sermon = new Sermon(['video_file_path' => null]);
        $this->assertFalse($sermon->hasVideo());

        $sermon->video_file_path = 'path/to/video.mp4';
        $this->assertTrue($sermon->hasVideo());
    }

    #[Test]
    public function is_automated_returns_false_for_unsaved_manual_sermon(): void
    {
        $sermon = new Sermon([
            'transcript_file_path' => null,
        ]);

        $this->assertFalse($sermon->isAutomated());
        $this->assertTrue($sermon->isManual());
    }

    #[Test]
    public function is_automated_returns_true_if_transcript_is_present(): void
    {
        $sermon = new Sermon(['transcript_file_path' => 'path.txt']);
        $this->assertTrue($sermon->isAutomated());
        $this->assertFalse($sermon->isManual());
    }

    #[Test]
    public function it_validates_preacher_id_integer_bounds(): void
    {
        $rules = Sermon::validationRules();
        $preacherIdRules = $rules['preacher_id'];

        // Remove 'exists:preachers,id' to avoid DB hit in unit test
        $preacherIdRules = array_filter($preacherIdRules, fn ($rule) => ! is_string($rule) || ! str_starts_with($rule, 'exists:'));

        $validator = Validator::make(
            ['preacher_id' => 9223372036854775808], // Above bigint max
            ['preacher_id' => $preacherIdRules]
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('preacher_id', $validator->errors()->toArray());
    }

    #[Test]
    public function it_validates_slug_format(): void
    {
        $rules = Sermon::validationRules();
        $slugRules = $rules['slug'];

        // Remove Unique rule to avoid DB hit in unit test
        $slugRules = array_filter($slugRules, fn ($rule) => ! $rule instanceof \Illuminate\Validation\Rules\Unique);

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
                ['slug' => $slugRules]
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
                ['slug' => $slugRules]
            );

            $this->assertFalse($validator->fails(), "Slug '{$slug}' should be valid.");
        }
    }
}
