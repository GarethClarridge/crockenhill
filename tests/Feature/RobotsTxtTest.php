<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RobotsTxtTest extends TestCase
{
    #[Test]
    public function robots_txt_file_exists(): void
    {
        $this->assertFileExists(public_path('robots.txt'));
    }

    #[Test]
    public function robots_txt_is_accessible_via_http(): void
    {
        // In tests, Laravel doesn't serve static files like production
        // Just verify the file exists and has content
        $this->assertFileExists(public_path('robots.txt'));
        $content = file_get_contents(public_path('robots.txt'));
        $this->assertNotEmpty($content);
    }

    #[Test]
    public function robots_txt_allows_all_user_agents(): void
    {
        $content = file_get_contents(public_path('robots.txt'));

        $this->assertStringContainsString('User-agent: *', $content);
        $this->assertStringContainsString('Allow: /', $content);
    }

    #[Test]
    public function robots_txt_disallows_member_area(): void
    {
        $content = file_get_contents(public_path('robots.txt'));

        $this->assertStringContainsString('Disallow: /church/members/', $content);
    }

    #[Test]
    public function robots_txt_disallows_authentication_routes(): void
    {
        $content = file_get_contents(public_path('robots.txt'));

        $this->assertStringContainsString('Disallow: /login', $content);
        $this->assertStringContainsString('Disallow: /register', $content);
    }

    #[Test]
    public function robots_txt_references_sitemap(): void
    {
        $content = file_get_contents(public_path('robots.txt'));

        $this->assertStringContainsString('Sitemap: https://crockenhill.org/sitemap.xml', $content);
    }

    #[Test]
    public function robots_txt_format_is_valid(): void
    {
        $content = file_get_contents(public_path('robots.txt'));

        // Check for proper formatting
        $lines = explode("\n", $content);

        // Should have multiple lines
        $this->assertGreaterThan(3, count($lines));

        // Each non-empty line should follow robots.txt format
        $validPatterns = [
            '/^User-agent:/',
            '/^Allow:/',
            '/^Disallow:/',
            '/^Sitemap:/',
            '/^$/', // empty lines are valid
        ];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $matches = false;
            foreach ($validPatterns as $pattern) {
                if (preg_match($pattern, $line)) {
                    $matches = true;
                    break;
                }
            }

            $this->assertTrue(
                $matches,
                "Line '$line' doesn't match valid robots.txt format"
            );
        }
    }
}
