<?php

declare(strict_types=1);

namespace Tests\Feature\Sentry;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the release-tagging contract: an error's `release` must resolve from
 * the image's APP_VERSION (the git SHA) unless explicitly overridden, and its
 * `environment` from APP_ENV. This is what lets Sentry flag "regressed in <SHA>".
 *
 * config/sentry.php is re-required directly so the env() precedence is exercised
 * for real, rather than reading the already-resolved (and cached) config.
 */
class SentryConfigurationTest extends TestCase
{
    #[Test]
    public function release_defaults_to_app_version(): void
    {
        $config = $this->resolveSentryConfigWith([
            'SENTRY_RELEASE' => null,
            'APP_VERSION' => 'deploy-sha-abc123',
        ]);

        $this->assertSame('deploy-sha-abc123', $config['release']);
    }

    #[Test]
    public function explicit_sentry_release_wins_over_app_version(): void
    {
        $config = $this->resolveSentryConfigWith([
            'SENTRY_RELEASE' => 'explicit-release',
            'APP_VERSION' => 'deploy-sha-abc123',
        ]);

        $this->assertSame('explicit-release', $config['release']);
    }

    #[Test]
    public function environment_defaults_to_app_env(): void
    {
        $config = $this->resolveSentryConfigWith([
            'SENTRY_ENVIRONMENT' => null,
            'APP_ENV' => 'production',
        ]);

        $this->assertSame('production', $config['environment']);
    }

    #[Test]
    public function it_never_attaches_default_pii_or_request_bodies(): void
    {
        $config = require base_path('config/sentry.php');

        $this->assertFalse($config['send_default_pii']);
        $this->assertSame('none', $config['max_request_body_size']);
    }

    #[Test]
    public function tracing_is_disabled_by_default_so_only_errors_consume_quota(): void
    {
        $config = $this->resolveSentryConfigWith(['SENTRY_TRACES_SAMPLE_RATE' => null]);

        $this->assertNull($config['traces_sample_rate']);
    }

    /**
     * Resolve config/sentry.php under a controlled set of environment variables,
     * restoring the originals afterwards.
     *
     * @param  array<string, string|null>  $env
     * @return array<string, mixed>
     */
    private function resolveSentryConfigWith(array $env): array
    {
        $restore = [];

        foreach ($env as $key => $value) {
            $restore[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];

            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }

        try {
            return require base_path('config/sentry.php');
        } finally {
            foreach ($restore as $key => [$rawEnv, $superEnv, $superServer]) {
                if ($rawEnv === false) {
                    putenv($key);
                } else {
                    putenv("{$key}={$rawEnv}");
                }

                if ($superEnv === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $superEnv;
                }

                if ($superServer === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $superServer;
                }
            }
        }
    }
}
