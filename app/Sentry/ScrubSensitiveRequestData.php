<?php

declare(strict_types=1);

namespace App\Sentry;

use Sentry\Event;
use Sentry\EventHint;

/**
 * Sentry `before_send` callback that strips sensitive query-string parameters
 * from an event's request URL before it leaves the server.
 *
 * `send_default_pii => false` already keeps cookies, auth headers, and user
 * identity off the wire, but Sentry still transmits the full request URL
 * including its query string. Signed-URL tokens, Mailgun webhook signatures,
 * and member email addresses all travel as query parameters on this site, so
 * they are redacted here. {@see self::SENSITIVE_QUERY_PARAMETERS} is the single
 * auditable source of truth for what gets scrubbed.
 *
 * Wired onto the client in App\Providers\SentryServiceProvider rather than in
 * config/sentry.php, because the production deploy runs `config:cache`, which
 * cannot serialize a closure.
 */
class ScrubSensitiveRequestData
{
    /**
     * Query parameter names to redact, matched case-insensitively as a
     * substring so `api_key`, `access_token`, and `X-Signature` are all caught
     * by `key`, `token`, and `signature` respectively.
     *
     * @var list<string>
     */
    public const SENSITIVE_QUERY_PARAMETERS = [
        'signature',
        'token',
        'secret',
        'password',
        'email',
        'key',
    ];

    private const REDACTED = '[Filtered]';

    public function __invoke(Event $event, ?EventHint $hint = null): ?Event
    {
        $request = $event->getRequest();

        if ($request === []) {
            return $event;
        }

        if (isset($request['query_string']) && is_string($request['query_string'])) {
            $request['query_string'] = $this->scrubQueryString($request['query_string']);
        }

        if (isset($request['url']) && is_string($request['url'])) {
            $request['url'] = $this->scrubUrl($request['url']);
        }

        $event->setRequest($request);

        return $event;
    }

    private function scrubUrl(string $url): string
    {
        $queryStart = strpos($url, '?');

        if ($queryStart === false) {
            return $url;
        }

        $path = substr($url, 0, $queryStart);
        $scrubbed = $this->scrubQueryString(substr($url, $queryStart + 1));

        return $scrubbed === '' ? $path : "{$path}?{$scrubbed}";
    }

    private function scrubQueryString(string $queryString): string
    {
        if ($queryString === '') {
            return '';
        }

        $pairs = explode('&', $queryString);

        foreach ($pairs as $index => $pair) {
            [$name] = explode('=', $pair, 2);

            if ($this->isSensitive(urldecode($name))) {
                $pairs[$index] = $name.'='.self::REDACTED;
            }
        }

        return implode('&', $pairs);
    }

    private function isSensitive(string $name): bool
    {
        $name = strtolower($name);

        foreach (self::SENSITIVE_QUERY_PARAMETERS as $sensitive) {
            if (str_contains($name, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
