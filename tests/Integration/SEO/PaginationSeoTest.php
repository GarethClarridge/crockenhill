<?php

declare(strict_types=1);

namespace Tests\Integration\SEO;

use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaginationSeoTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function sermon_archive_page_2_has_correct_title(): void
    {
        Sermon::factory()->count(30)->create();

        $response = $this->get('/christ/sermons?page=2');

        $response->assertStatus(200);

        // Assert title tag is present with correct page 2 suffix, allowing whitespace variation
        $this->assertMatchesRegularExpression(
            '/<title>\s*Sermons \(Page 2\) \| Crockenhill Baptist Church\s*<\/title>/i',
            $response->getContent()
        );

        // Assert meta description is present in an attribute-order-independent manner
        $this->assertMatchesRegularExpression(
            '/<meta\s+(?=[^>]*name="description")(?=[^>]*content="Explore the sermon archive at Crockenhill Baptist Church\. Watch or listen to Bible teaching from our Sunday services, filtered by scripture, preacher, or series\. - Page 2")/i',
            $response->getContent()
        );
    }

    #[Test]
    public function song_archive_page_2_has_correct_title(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $response = $this->actingAs($user)->get('/church/songs?page=2');

        $response->assertStatus(200);

        // Assert title tag is present with correct page 2 suffix, allowing whitespace variation
        $this->assertMatchesRegularExpression(
            '/<title>\s*Recent Songs \(Page 2\) \| Crockenhill Baptist Church\s*<\/title>/i',
            $response->getContent()
        );

        // Assert meta description is present in an attribute-order-independent manner
        $this->assertMatchesRegularExpression(
            '/<meta\s+(?=[^>]*name="description")(?=[^>]*content="Browse the songs most recently sung at Crockenhill Baptist Church\. - Page 2")/i',
            $response->getContent()
        );
    }
}
