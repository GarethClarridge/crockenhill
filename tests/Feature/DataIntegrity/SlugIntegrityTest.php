<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

use App\Models\Meeting;
use App\Models\Page;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SlugIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_validates_sermon_slug_format(): void
    {
        $rules = Sermon::validationRules();

        $this->assertTrue(Validator::make(['slug' => 'valid-slug-123'], ['slug' => $rules['slug']])->passes());
        $this->assertFalse(Validator::make(['slug' => 'valid_slug_too'], ['slug' => $rules['slug']])->passes(), 'Underscores should be rejected');
        $this->assertFalse(Validator::make(['slug' => 'invalid slug'], ['slug' => $rules['slug']])->passes());
        $this->assertFalse(Validator::make(['slug' => 'invalid!slug'], ['slug' => $rules['slug']])->passes());
        $this->assertFalse(Validator::make(['slug' => 'invalid.slug'], ['slug' => $rules['slug']])->passes());
        $this->assertFalse(Validator::make(['slug' => 'UPPERCASE-slug'], ['slug' => $rules['slug']])->passes());
    }

    #[Test]
    public function it_validates_preacher_slug_format(): void
    {
        $rules = Preacher::validationRules();

        $this->assertTrue(Validator::make(['slug' => 'valid-slug-123'], ['slug' => $rules['slug']])->passes());
        $this->assertFalse(Validator::make(['slug' => 'invalid slug'], ['slug' => $rules['slug']])->passes());
        $this->assertFalse(Validator::make(['slug' => 'invalid!slug'], ['slug' => $rules['slug']])->passes());
    }

    #[Test]
    public function it_validates_meeting_slug_with_alpha_dash(): void
    {
        $rules = Meeting::validationRules();

        $this->assertTrue(Validator::make(['slug' => 'valid-slug-123'], ['slug' => $rules['slug']])->passes());
        $this->assertFalse(Validator::make(['slug' => 'invalid slug'], ['slug' => $rules['slug']])->passes());
    }

    #[Test]
    public function it_validates_page_slug_with_alpha_dash(): void
    {
        $rules = Page::validationRules();

        $this->assertTrue(Validator::make(['slug' => 'valid-slug-123'], ['slug' => $rules['slug']])->passes());
        $this->assertFalse(Validator::make(['slug' => 'invalid slug'], ['slug' => $rules['slug']])->passes());
    }

    #[Test]
    public function database_rejects_invalid_sermon_slug(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        Sermon::factory()->create([
            'slug' => 'invalid slug'
        ]);
    }

    #[Test]
    public function database_rejects_invalid_preacher_slug(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        Preacher::factory()->create([
            'slug' => 'invalid slug'
        ]);
    }

    #[Test]
    public function database_rejects_invalid_meeting_slug(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        Meeting::factory()->create([
            'slug' => 'invalid slug'
        ]);
    }

    #[Test]
    public function database_rejects_invalid_page_slug(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        Page::factory()->create([
            'slug' => 'invalid slug'
        ]);
    }
}
