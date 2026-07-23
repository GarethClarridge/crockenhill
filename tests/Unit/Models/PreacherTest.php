<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Preacher;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PreacherTest extends TestCase
{
    #[Test]
    public function it_trims_name_attribute(): void
    {
        /** @var Preacher $preacher */
        $preacher = Preacher::factory()->make();
        $preacher->name = '  John Smith  ';

        $this->assertEquals('John Smith', $preacher->name);
    }

    #[Test]
    public function profile_image_url_returns_null_when_path_is_blank(): void
    {
        /** @var Preacher $preacher */
        $preacher = Preacher::factory()->make(['image_path' => null]);
        $this->assertNull($preacher->profile_image_url);

        $preacher->image_path = '';
        $this->assertNull($preacher->profile_image_url);
    }

    #[Test]
    public function profile_image_url_returns_absolute_url_as_is(): void
    {
        $url = 'https://example.com/image.jpg';
        /** @var Preacher $preacher */
        $preacher = Preacher::factory()->make(['image_path' => $url]);

        $this->assertEquals($url, $preacher->profile_image_url);
    }

    #[Test]
    public function profile_image_url_returns_storage_url_for_local_path(): void
    {
        Storage::fake('public');

        $path = 'preachers/john-smith.jpg';
        /** @var Preacher $preacher */
        $preacher = Preacher::factory()->make(['image_path' => $path]);

        $this->assertEquals(Storage::disk('public')->url($path), $preacher->profile_image_url);
    }

    #[Test]
    public function it_validates_basic_rules(): void
    {
        $rules = $this->filterRules(Preacher::validationRules());

        $validator = Validator::make([
            'name' => 'John Smith',
            'slug' => 'john-smith',
            'is_active' => true,
        ], $rules);

        $this->assertFalse($validator->fails(), 'Validation should pass with valid data');

        $validator = Validator::make([
            'name' => '',
            'slug' => 'Invalid Slug!',
            'is_active' => 'not-a-boolean',
        ], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());
        $this->assertArrayHasKey('slug', $validator->errors()->toArray());
        $this->assertArrayHasKey('is_active', $validator->errors()->toArray());
    }

    /**
     * @param  array<string, array<int, mixed>>  $rules
     * @return array<string, array<int, mixed>>
     */
    private function filterRules(array $rules): array
    {
        return array_map(function ($fieldRules) {
            return array_filter($fieldRules, function ($rule) {
                $ruleString = (string) $rule;

                return ! str_starts_with($ruleString, 'unique') && ! str_starts_with($ruleString, 'exists');
            });
        }, $rules);
    }
}
