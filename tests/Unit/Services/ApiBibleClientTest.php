<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ApiBibleBudgetExhaustedException;
use App\Services\ApiBibleClient;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiBibleClientTest extends TestCase
{
    use DatabaseTransactions;

    private ApiBibleClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.api_bible.key', 'test-key');
        Config::set('services.api_bible.default_bible_id', 'test-bible-id');
        Config::set('services.api_bible.daily_budget', 10);

        $this->client = new ApiBibleClient;

        Cache::flush();
    }

    #[Test]
    public function has_daily_budget_returns_true_when_under_limit(): void
    {
        $this->assertTrue($this->client->hasDailyBudget());
    }

    #[Test]
    public function has_daily_budget_returns_false_when_at_limit(): void
    {
        $key = 'api_bible_daily_calls_'.now()->format('Y-m-d');
        Cache::put($key, 10);

        $this->assertFalse($this->client->hasDailyBudget());
    }

    #[Test]
    public function record_call_increments_cache_counter(): void
    {
        $key = 'api_bible_daily_calls_'.now()->format('Y-m-d');

        $this->client->recordCall();
        $this->assertEquals(1, Cache::get($key));

        $this->client->recordCall();
        $this->assertEquals(2, Cache::get($key));
    }

    #[Test]
    public function search_passage_throws_exception_when_budget_exhausted(): void
    {
        $key = 'api_bible_daily_calls_'.now()->format('Y-m-d');
        Cache::put($key, 10);

        $this->expectException(ApiBibleBudgetExhaustedException::class);
        $this->client->searchPassage('John 3:16');
    }

    #[Test]
    public function search_passage_returns_result_on_success(): void
    {
        Http::fake([
            '*/bibles/test-bible-id/search*' => Http::response([
                'data' => [
                    'passages' => [
                        [
                            'id' => 'JHN.3.16',
                            'reference' => 'John 3:16',
                            'content' => '<p>For God so loved the world</p>',
                            'copyright' => 'NIV',
                        ],
                    ],
                ],
                'meta' => [
                    'fumsToken' => 'test-fums-token',
                ],
            ]),
        ]);

        $result = $this->client->searchPassage('John 3:16');

        $this->assertNotNull($result);
        $this->assertEquals('JHN.3.16', $result->passageId);
        $this->assertEquals('John 3:16', $result->displayReference);
        $this->assertEquals('<p>For God so loved the world</p>', $result->htmlContent);
        $this->assertEquals('NIV', $result->copyright);
        $this->assertEquals('test-fums-token', $result->fumsToken);
    }

    #[Test]
    public function search_passage_returns_null_when_no_passages_found(): void
    {
        Http::fake([
            '*/bibles/test-bible-id/search*' => Http::response([
                'data' => [
                    'passages' => [],
                ],
            ]),
        ]);

        $result = $this->client->searchPassage('NonExistent 1:1');

        $this->assertNull($result);
    }

    #[Test]
    public function search_passage_throws_runtime_exception_on_429_after_retries(): void
    {
        Http::fake([
            '*/bibles/test-bible-id/search*' => Http::response([], 429),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('failed with status 429');

        $this->client->searchPassage('John 3:16');
    }

    #[Test]
    public function search_passage_returns_null_on_terminal_client_error(): void
    {
        Http::fake([
            '*/bibles/test-bible-id/search*' => Http::response([], 400),
        ]);

        $result = $this->client->searchPassage('John 3:16');

        $this->assertNull($result);
    }

    #[Test]
    public function fetch_passage_by_id_returns_result_on_success(): void
    {
        Http::fake([
            '*/bibles/test-bible-id/passages/JHN.3.16*' => Http::response([
                'data' => [
                    'id' => 'JHN.3.16',
                    'reference' => 'John 3:16',
                    'content' => '<p>For God so loved the world</p>',
                    'copyright' => 'NIV',
                ],
                'meta' => [
                    'fumsToken' => 'test-fums-token',
                ],
            ]),
        ]);

        $result = $this->client->fetchPassageById('JHN.3.16');

        $this->assertNotNull($result);
        $this->assertEquals('JHN.3.16', $result->passageId);
        $this->assertEquals('John 3:16', $result->displayReference);
        $this->assertEquals('<p>For God so loved the world</p>', $result->htmlContent);
    }

    #[Test]
    public function fetch_passage_by_id_throws_exception_when_budget_exhausted(): void
    {
        $key = 'api_bible_daily_calls_'.now()->format('Y-m-d');
        Cache::put($key, 10);

        $this->expectException(ApiBibleBudgetExhaustedException::class);
        $this->client->fetchPassageById('JHN.3.16');
    }

    #[Test]
    public function fetch_passage_by_id_returns_null_on_404(): void
    {
        Http::fake([
            '*/bibles/test-bible-id/passages/INVALID*' => Http::response([], 404),
        ]);

        $result = $this->client->fetchPassageById('INVALID');

        $this->assertNull($result);
    }

    #[Test]
    public function it_retries_on_connection_exception(): void
    {
        Http::fake([
            '*/bibles/test-bible-id/search*' => Http::sequence()
                ->pushStatus(500) // First attempt fails
                ->pushStatus(500) // Second attempt fails
                ->push([          // Third attempt succeeds
                    'data' => [
                        'passages' => [['id' => 'JHN.3.16', 'content' => 'Success', 'reference' => 'John 3:16', 'copyright' => 'NIV']],
                    ],
                ]),
        ]);

        $result = $this->client->searchPassage('John 3:16');

        $this->assertNotNull($result);
        $this->assertEquals('Success', $result->htmlContent);
    }
}
