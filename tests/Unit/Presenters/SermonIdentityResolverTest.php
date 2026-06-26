<?php

declare(strict_types=1);

namespace Tests\Unit\Presenters;

use App\Enums\SermonService;
use App\Models\Preacher;
use App\Models\ScripturePassage;
use App\Models\Sermon;
use App\Presenters\SermonIdentityResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonIdentityResolverTest extends TestCase
{
    private SermonIdentityResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new SermonIdentityResolver;
    }

    #[Test]
    public function it_resolves_preacher_name_from_profile_when_loaded(): void
    {
        $preacher = Preacher::factory()->make(['name' => 'John Profile']);
        $sermon = Sermon::factory()->make(['preacher' => 'Legacy Name']);
        $sermon->setRelation('preacherProfile', $preacher);

        $this->assertSame('John Profile', $this->resolver->displayPreacherName($sermon));
    }

    #[Test]
    public function it_resolves_preacher_name_from_legacy_column_when_profile_not_loaded(): void
    {
        $sermon = Sermon::factory()->make(['preacher' => 'Legacy Name']);

        $this->assertSame('Legacy Name', $this->resolver->displayPreacherName($sermon));
    }

    #[Test]
    public function it_resolves_preacher_image_url_from_profile_when_loaded(): void
    {
        $preacher = Preacher::factory()->make(['image_path' => 'preachers/john.webp']);
        $sermon = Sermon::factory()->make();
        $sermon->setRelation('preacherProfile', $preacher);

        $this->assertSame($preacher->profile_image_url, $this->resolver->preacherImageUrl($sermon));
    }

    #[Test]
    public function it_returns_null_preacher_image_url_when_profile_not_loaded(): void
    {
        $sermon = Sermon::factory()->make();

        $this->assertNull($this->resolver->preacherImageUrl($sermon));
    }

    #[Test]
    public function it_returns_preacher_url_from_profile_slug_when_loaded(): void
    {
        $preacher = Preacher::factory()->make(['slug' => 'john-profile']);
        $sermon = Sermon::factory()->make();
        $sermon->setRelation('preacherProfile', $preacher);

        $this->assertSame(route('sermons.preacher', ['preacher' => 'john-profile']), $this->resolver->preacherUrl($sermon));
    }

    #[Test]
    public function it_returns_preacher_url_from_legacy_name_when_profile_not_loaded(): void
    {
        $sermon = Sermon::factory()->make(['preacher' => 'Legacy Name']);

        $this->assertSame(route('sermons.preacher', ['preacher' => 'legacy-name']), $this->resolver->preacherUrl($sermon));
    }

    #[Test]
    public function it_resolves_display_reference_from_passage_when_loaded(): void
    {
        $passage = ScripturePassage::factory()->make(['display_reference' => 'John 3:16']);
        $sermon = Sermon::factory()->make(['reference' => 'Legacy Ref']);
        $sermon->setRelation('scripturePassage', $passage);

        $this->assertSame('John 3:16', $this->resolver->displayReference($sermon));
    }

    #[Test]
    public function it_resolves_display_reference_from_legacy_column_when_passage_not_loaded(): void
    {
        $sermon = Sermon::factory()->make(['reference' => 'Legacy Ref']);

        $this->assertSame('Legacy Ref', $this->resolver->displayReference($sermon));
    }

    #[Test]
    public function it_returns_service_label(): void
    {
        $sermon = Sermon::factory()->make(['service' => SermonService::Morning]);

        $this->assertSame('Morning', $this->resolver->serviceLabel($sermon));
    }

    #[Test]
    public function it_returns_null_service_label_when_service_is_missing(): void
    {
        $sermon = Sermon::factory()->make(['service' => null]);

        $this->assertNull($this->resolver->serviceLabel($sermon));
    }

    #[Test]
    public function it_returns_series_url(): void
    {
        $sermon = Sermon::factory()->make(['series' => 'Life Of David']);

        $this->assertSame(route('sermons.series.show', ['series' => 'life-of-david']), $this->resolver->seriesUrl($sermon));
    }

    #[Test]
    public function it_returns_null_series_url_when_series_is_missing(): void
    {
        $sermon = Sermon::factory()->make(['series' => null]);

        $this->assertNull($this->resolver->seriesUrl($sermon));
    }

    #[Test]
    public function it_prefers_loaded_relation_over_a_cached_unloaded_fallback(): void
    {
        // First resolve against the unloaded legacy column, caching the fallback.
        $unloaded = Sermon::factory()->make(['preacher_id' => 7, 'preacher' => 'Legacy Name']);
        $this->assertSame('Legacy Name', $this->resolver->displayPreacherName($unloaded));

        // A later sermon sharing the same preacher identity but with the relation
        // loaded must override the cached fallback with the authoritative name.
        $loaded = Sermon::factory()->make(['preacher_id' => 7, 'preacher' => 'Legacy Name']);
        $loaded->setRelation('preacherProfile', Preacher::factory()->make(['name' => 'Authoritative Name']));

        $this->assertSame('Authoritative Name', $this->resolver->displayPreacherName($loaded));
    }

    #[Test]
    public function it_memoizes_by_identity_across_sermons(): void
    {
        $preacher = Preacher::factory()->make(['name' => 'Shared Preacher']);

        $first = Sermon::factory()->make(['preacher_id' => 42]);
        $first->setRelation('preacherProfile', $preacher);
        $this->assertSame('Shared Preacher', $this->resolver->displayPreacherName($first));

        // A different sermon by the same preacher identity reuses the memoized
        // value even though its (unloaded) relation would resolve differently.
        $second = Sermon::factory()->make(['preacher_id' => 42, 'preacher' => 'Different Legacy']);
        $this->assertSame('Shared Preacher', $this->resolver->displayPreacherName($second));
    }

    #[Test]
    public function it_clears_the_identity_cache(): void
    {
        $sermon = Sermon::factory()->make(['series' => 'Cache Clear Test']);
        $first = $this->resolver->seriesUrl($sermon);

        $this->resolver->clearCache();

        $sermon->series = 'New Series';
        $second = $this->resolver->seriesUrl($sermon);

        $this->assertNotSame($first, $second);
        $this->assertStringContainsString('new-series', (string) $second);
    }
}
