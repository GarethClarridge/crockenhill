<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\CalendarEvent;
use App\Models\Meeting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JsonLdConsistencyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function calendar_page_json_ld_is_secure_and_pretty_printed(): void
    {
        CalendarEvent::factory()->create([
            'title' => 'Event <script> " & \'',
            'description' => 'Event description',
            'start_datetime' => now()->addDay(),
        ]);

        $response = $this->get(route('calendar.index'));

        $response->assertStatus(200);
        $this->assertJsonLdIsSecureAndPretty($response);
        $this->assertJsonLdHasHexEncodedMaliciousChars($response);
    }

    #[Test]
    public function meeting_events_page_json_ld_is_secure_and_pretty_printed(): void
    {
        $meeting = Meeting::factory()->create();
        CalendarEvent::factory()->create([
            'meeting_slug' => $meeting->slug,
            'title' => 'Meeting Event <script> " & \'',
            'description' => 'Meeting description',
            'start_datetime' => now()->addDay(),
        ]);

        $response = $this->get(route('meetings.events', $meeting));

        $response->assertStatus(200);
        $this->assertJsonLdIsSecureAndPretty($response);
        $this->assertJsonLdHasHexEncodedMaliciousChars($response);
    }

    #[Test]
    public function meeting_detail_page_json_ld_is_secure_and_pretty_printed(): void
    {
        $meeting = Meeting::factory()->create([
            'slug' => 'test-meeting-with-quotes-'.mt_rand(),
        ]);

        CalendarEvent::factory()->create([
            'meeting_slug' => $meeting->slug,
            'title' => 'Upcoming Event <script> " & \'',
            'description' => 'Upcoming description',
            'start_datetime' => now()->addDay(),
        ]);

        $response = $this->get(route('meetings.show', $meeting));

        $response->assertStatus(200);
        $this->assertJsonLdIsSecureAndPretty($response);
        $this->assertJsonLdHasHexEncodedMaliciousChars($response);
    }

    #[Test]
    public function christmas_page_json_ld_is_secure_and_pretty_printed(): void
    {
        $response = $this->get(route('pages.christmas'));

        $response->assertStatus(200);
        $this->assertJsonLdIsSecureAndPretty($response);
    }

    private function assertJsonLdIsSecureAndPretty(TestResponse $response): void
    {
        $content = $response->getContent();

        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $content, $matches);

        $this->assertNotEmpty($matches[1], 'No JSON-LD blocks found in response');

        foreach ($matches[1] as $index => $jsonLd) {
            $context = "Block #{$index}: ".substr($jsonLd, 0, 100);

            $this->assertStringNotContainsString('<?php', $jsonLd, "{$context}: JSON-LD contains raw PHP tags");
            $this->assertStringNotContainsString('$__contextArgs', $jsonLd, "{$context}: JSON-LD contains internal context placeholders");

            $this->assertStringNotContainsString('<script', $jsonLd, "{$context}: JSON-LD contains unescaped <script tag");
            $this->assertStringNotContainsString('&', $jsonLd, "{$context}: JSON-LD contains unescaped ampersand");

            $this->assertStringContainsString("\n", $jsonLd, "{$context}: JSON-LD is not pretty-printed (missing newlines)");
            $this->assertStringContainsString('    ', $jsonLd, "{$context}: JSON-LD is not pretty-printed (missing indentation)");
        }
    }

    private function assertJsonLdHasHexEncodedMaliciousChars(TestResponse $response): void
    {
        $content = $response->getContent();
        $this->assertStringContainsString('\u003Cscript\u003E', $content, 'Response does not have hex-encoded <script>');
        $this->assertStringContainsString('\u0026', $content, 'Response does not have hex-encoded ampersand');
        $this->assertStringContainsString('\u0027', $content, 'Response does not have hex-encoded single quote');
        $this->assertStringContainsString('\u0022', $content, 'Response does not have hex-encoded double quote');
    }
}
