<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Exceptions\ApiBibleBudgetExhaustedException;
use App\Services\Scripture\ApiBibleClient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiBibleClientTest extends TestCase
{
    use RefreshDatabase;

    private ApiBibleClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-27');

        Config::set('services.api_bible.key', 'test-key');
        Config::set('services.api_bible.default_bible_id', 'test-bible-id');
        Config::set('services.api_bible.daily_budget', 10);

        $this->client = new ApiBibleClient;

        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function has_daily_budget_returns_true_when_under_limit(): void
    {
        $this->assertTrue($this->client->hasDailyBudget());
    }

    #[Test]
    public function has_daily_budget_returns_false_when_at_limit(): void
    {
        $key = $this->dailyBudgetCacheKey();
        Cache::put($key, 10);

        $this->assertFalse($this->client->hasDailyBudget());
    }

    #[Test]
    public function daily_budget_counter_increments_on_each_successful_call(): void
    {
        Http::fake([
            '*/bibles/test-bible-id/search*' => Http::response([
                'data' => ['passages' => [
                    ['id' => 'JHN.3.16', 'reference' => 'John 3:16', 'content' => '<p>text</p>', 'copyright' => 'NIV'],
                ]],
            ]),
        ]);

        $key = $this->dailyBudgetCacheKey();

        $this->client->searchPassage('John 3:16');
        $this->assertEquals(1, Cache::get($key));

        $this->client->searchPassage('John 3:16');
        $this->assertEquals(2, Cache::get($key));
    }

    #[Test]
    public function search_passage_throws_exception_when_budget_exhausted(): void
    {
        $key = $this->dailyBudgetCacheKey();
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
        $key = $this->dailyBudgetCacheKey();
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

    #[Test]
    public function budget_is_incremented_once_for_a_successful_single_attempt(): void
    {
        Http::fake([
            '*/bibles/test-bible-id/search*' => Http::response([
                'data' => [
                    'passages' => [
                        ['id' => 'JHN.3.16', 'reference' => 'John 3:16', 'content' => '<p>For God</p>', 'copyright' => 'NIV'],
                    ],
                ],
            ]),
        ]);

        $this->client->searchPassage('John 3:16');

        $key = $this->dailyBudgetCacheKey();
        $this->assertEquals(1, Cache::get($key));
    }

    #[Test]
    public function budget_counts_each_retry_attempt_on_server_error(): void
    {
        // When the HTTP client retries a 500 twice before succeeding, the budget
        // counter must reach 3 (1 first attempt + 2 retries) — not 1.
        Http::fake([
            '*/bibles/test-bible-id/search*' => Http::sequence()
                ->pushStatus(500) // attempt 1 — counted by makeRequest() before the call
                ->pushStatus(500) // attempt 2 — counted by retry callback
                ->push([          // attempt 3 — counted by retry callback
                    'data' => [
                        'passages' => [
                            ['id' => 'JHN.3.16', 'reference' => 'John 3:16', 'content' => '<p>For God</p>', 'copyright' => 'NIV'],
                        ],
                    ],
                ]),
        ]);

        $result = $this->client->searchPassage('John 3:16');

        $this->assertNotNull($result);

        $key = $this->dailyBudgetCacheKey();
        $this->assertEquals(3, Cache::get($key));
    }

    #[Test]
    public function budget_counts_all_attempts_when_all_retries_fail(): void
    {
        // Even when all retries fail, every outbound attempt must be counted.
        Config::set('services.api_bible.max_retries', 2);
        $this->client = new ApiBibleClient;

        Http::fake([
            '*/bibles/test-bible-id/search*' => Http::response([], 500),
        ]);

        try {
            $this->client->searchPassage('John 3:16');
        } catch (\RuntimeException) {
            // Expected — 500 after retries exhausted throws RuntimeException
        }

        // max_retries=2 means 1 initial + 2 retries = 3 total attempts
        $key = $this->dailyBudgetCacheKey();
        $this->assertEquals(3, Cache::get($key));
    }

    #[Test]
    public function budget_is_not_incremented_when_budget_already_exhausted(): void
    {
        // assertDailyBudget() throws before makeRequest() is ever called,
        // so an exhausted budget must not consume any further quota.
        $key = $this->dailyBudgetCacheKey();
        Cache::put($key, 10); // budget = 10, at limit

        try {
            $this->client->searchPassage('John 3:16');
        } catch (ApiBibleBudgetExhaustedException) {
            // Expected
        }

        // Counter must remain at 10 — no attempt was made
        $this->assertEquals(10, Cache::get($key));
    }

    #[Test]
    public function budget_counts_each_retry_for_passage_fetch(): void
    {
        Http::fake([
            '*/bibles/test-bible-id/passages/JHN.3.16*' => Http::sequence()
                ->pushStatus(500)
                ->push([
                    'data' => [
                        'id' => 'JHN.3.16',
                        'reference' => 'John 3:16',
                        'content' => '<p>For God</p>',
                        'copyright' => 'NIV',
                    ],
                ]),
        ]);

        $result = $this->client->fetchPassageById('JHN.3.16');

        $this->assertNotNull($result);

        $key = $this->dailyBudgetCacheKey();
        $this->assertEquals(2, Cache::get($key));
    }

    private function dailyBudgetCacheKey(): string
    {
        return 'api_bible_daily_calls_'.now()->format('Y-m-d');
    }
}
