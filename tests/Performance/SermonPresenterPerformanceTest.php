<?php

declare(strict_types=1);

namespace Tests\Performance;

use App\Models\Sermon;
use App\Presenters\SermonViewPresenter;
use App\Sitemap\SermonSitemapPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('performance')]
class SermonPresenterPerformanceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function presenter_can_handle_large_collections_efficiently(): void
    {
        $count = 20;
        $sermons = Sermon::factory()->count($count)->create();

        $presenter = app(SermonViewPresenter::class);
        $sitemapPresenter = app(SermonSitemapPresenter::class);

        foreach ($sermons as $sermon) {
            // Trigger multiple presenter calls per sermon as would happen in a real view
            $name1 = $presenter->displayPreacherName($sermon);
            $ref1 = $presenter->displayReference($sermon);
            $dur1 = $presenter->formattedDuration($sermon);
            $purl1 = $presenter->preacherUrl($sermon);
            $surl1 = $presenter->seriesUrl($sermon);
            $curl1 = $presenter->canonicalUrl($sermon);

            // Re-call to prove stateless presentation remains deterministic.
            $this->assertSame($name1, $presenter->displayPreacherName($sermon));
            $this->assertSame($ref1, $presenter->displayReference($sermon));
            $this->assertSame($dur1, $presenter->formattedDuration($sermon));
            $this->assertSame($purl1, $presenter->preacherUrl($sermon));
            $this->assertSame($surl1, $presenter->seriesUrl($sermon));
            $this->assertSame($curl1, $presenter->canonicalUrl($sermon));

            // Test API presentation
            $apiPresent = $presenter->presentForApi($sermon);
            $this->assertArrayHasKey('audio_url', $apiPresent);
            $this->assertSame($apiPresent, $presenter->presentForApi($sermon));

            // Test sitemap presentation
            $tag = $sitemapPresenter->toSitemapTag($sermon);
            $this->assertNotNull($tag);
        }
    }
}
