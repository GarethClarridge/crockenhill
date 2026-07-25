<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Models\Sermon;
use App\Services\Sermon\SermonSlugGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonSlugGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private SermonSlugGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = $this->app->make(SermonSlugGenerator::class);
    }

    #[Test]
    public function it_slugifies_a_title(): void
    {
        $this->assertSame(
            'draw-near-by-jesus-blood',
            $this->generator->generate('Draw near by Jesus’ blood')
        );
    }

    #[Test]
    public function it_suffixes_on_collision(): void
    {
        Sermon::factory()->create(['slug' => 'the-good-shepherd']);
        Sermon::factory()->create(['slug' => 'the-good-shepherd-1']);

        $this->assertSame('the-good-shepherd-2', $this->generator->generate('The Good Shepherd'));
    }

    #[Test]
    public function it_does_not_collide_with_the_sermon_being_reslugged(): void
    {
        $sermon = Sermon::factory()->create(['slug' => 'the-good-shepherd']);

        $this->assertSame(
            'the-good-shepherd',
            $this->generator->generate('The Good Shepherd', $sermon->id)
        );
    }

    #[Test]
    public function it_recognises_a_slug_derived_from_its_title(): void
    {
        $this->assertTrue($this->generator->isDerivedFrom('the-good-shepherd', 'The Good Shepherd'));
        $this->assertTrue($this->generator->isDerivedFrom('the-good-shepherd-2', 'The Good Shepherd'));
        $this->assertFalse($this->generator->isDerivedFrom('a-slug-the-office-chose', 'The Good Shepherd'));
    }

    #[Test]
    public function it_treats_an_unslugifiable_title_as_not_derived(): void
    {
        $this->assertFalse($this->generator->isDerivedFrom('anything', '—'));
    }

    #[Test]
    #[DataProvider('placeholderSlugs')]
    public function it_recognises_pipeline_placeholder_slugs(string $slug, bool $expected): void
    {
        $this->assertSame($expected, $this->generator->isPlaceholderSlug($slug));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function placeholderSlugs(): array
    {
        return [
            'morning placeholder' => ['morning-sermon-january-18-2026', true],
            'evening placeholder' => ['evening-sermon-may-3-2026', true],
            'other placeholder' => ['other-sermon-december-25-2025', true],
            'serviceless placeholder' => ['sermon-january-16-2022', true],
            'placeholder with collision suffix' => ['evening-sermon-may-3-2026-1', true],
            'real title' => ['called-to-a-godly-life', false],
            'real title mentioning sermon' => ['sermon-on-the-mount', false],
            'real title with a year' => ['looking-back-on-2026', false],
        ];
    }
}
