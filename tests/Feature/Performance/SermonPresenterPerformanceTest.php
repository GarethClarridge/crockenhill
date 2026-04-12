<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use App\Models\Sermon;
use App\Presenters\SermonViewPresenter;
use App\Presenters\SermonSitemapPresenter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonPresenterPerformanceTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function presenter_can_handle_large_collections_efficiently(): void
    {
        $count = 100;
        $sermons = Sermon::factory()->count($count)->create();

        $presenter = app(SermonViewPresenter::class);
        $sitemapPresenter = app(SermonSitemapPresenter::class);

        $start = microtime(true);

        foreach ($sermons as $sermon) {
            // Trigger multiple presenter calls per sermon as would happen in a real view
            $presenter->displayPreacherName($sermon);
            $presenter->displayReference($sermon);
            $presenter->formattedDuration($sermon);
            $presenter->preacherUrl($sermon);
            $presenter->seriesUrl($sermon);
            $presenter->canonicalUrl($sermon);

            // Re-call to test memoization
            $presenter->displayPreacherName($sermon);
            $presenter->displayReference($sermon);
            $presenter->formattedDuration($sermon);

            // Test API presentation
            $presenter->presentForApi($sermon);

            // Test sitemap presentation
            $sitemapPresenter->toSitemapTag($sermon);
        }

        $duration = microtime(true) - $start;

        $this->assertLessThan(1.0, $duration, "Presenter took too long ({$duration}s) to process {$count} sermons.");
    }
}
