<?php

declare(strict_types=1);

namespace Tests\Unit\Sentry;

use App\Sentry\ScrubSensitiveRequestData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sentry\Event;

class ScrubSensitiveRequestDataTest extends TestCase
{
    #[Test]
    public function it_redacts_sensitive_parameters_from_the_url_and_query_string(): void
    {
        $event = Event::createEvent();
        $event->setRequest([
            'url' => 'https://crockenhill.test/webhook?signature=abc123&token=secrettoken&page=2',
            'query_string' => 'signature=abc123&token=secrettoken&page=2',
            'method' => 'GET',
        ]);

        $scrubbed = (new ScrubSensitiveRequestData)($event);

        $this->assertNotNull($scrubbed);
        $request = $scrubbed->getRequest();

        $this->assertSame(
            'https://crockenhill.test/webhook?signature=[Filtered]&token=[Filtered]&page=2',
            $request['url']
        );
        $this->assertSame(
            'signature=[Filtered]&token=[Filtered]&page=2',
            $request['query_string']
        );
    }

    #[Test]
    public function it_matches_sensitive_parameter_names_case_insensitively_and_by_substring(): void
    {
        $event = Event::createEvent();
        $event->setRequest([
            'query_string' => 'API_KEY=xyz&access_token=t&Email=a%40b.com&safe=ok',
        ]);

        $request = (new ScrubSensitiveRequestData)($event)->getRequest();

        $this->assertSame(
            'API_KEY=[Filtered]&access_token=[Filtered]&Email=[Filtered]&safe=ok',
            $request['query_string']
        );
    }

    #[Test]
    public function it_leaves_a_request_without_a_query_string_untouched(): void
    {
        $event = Event::createEvent();
        $event->setRequest([
            'url' => 'https://crockenhill.test/sermons',
            'method' => 'GET',
        ]);

        $request = (new ScrubSensitiveRequestData)($event)->getRequest();

        $this->assertSame('https://crockenhill.test/sermons', $request['url']);
    }

    #[Test]
    public function it_returns_an_event_with_no_request_unchanged(): void
    {
        $event = Event::createEvent();

        $scrubbed = (new ScrubSensitiveRequestData)($event);

        $this->assertSame([], $scrubbed->getRequest());
    }
}
