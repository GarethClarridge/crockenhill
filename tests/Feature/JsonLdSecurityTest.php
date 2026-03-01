<?php

namespace Tests\Feature;

use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JsonLdSecurityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function sermon_page_json_ld_is_correctly_hex_encoded_to_prevent_xss()
    {
        // Malicious string that attempts to break out of script tag
        $maliciousTitle = 'Test Sermon </script><script>alert("XSS")</script>';

        $sermon = Sermon::factory()->create([
            'title' => $maliciousTitle,
            'date' => '2023-10-22',
        ]);

        $year = $sermon->date->format('Y');
        $month = $sermon->date->format('m');
        $url = "/christ/sermons/{$year}/{$month}/{$sermon->slug}";

        $response = $this->get($url);

        $response->assertStatus(200);

        // Verify that the raw script tag is NOT present
        $response->assertDontSee('</script><script>alert("XSS")</script>', false);

        // Verify that it is hex-encoded
        // </script> becomes \u003C\/script\u003E or similar depending on options,
        // but JSON_HEX_TAG specifically targets < and >
        // < becomes \u003C, > becomes \u003E
        $response->assertSee('\u003C/script\u003E\u003Cscript\u003Ealert(\u0022XSS\u0022)\u003C/script\u003E', false);
    }

    #[Test]
    public function breadcrumb_json_ld_is_correctly_hex_encoded()
    {
        $maliciousHeading = 'Page </script><script>alert(1)</script>';

        $sermon = Sermon::factory()->create([
            'title' => $maliciousHeading,
            'date' => '2023-10-22',
        ]);

        $year = $sermon->date->format('Y');
        $month = $sermon->date->format('m');
        $url = "/christ/sermons/{$year}/{$month}/{$sermon->slug}";

        $response = $this->get($url);

        $response->assertStatus(200);

        // The breadcrumb component uses the heading
        $response->assertDontSee('</script><script>alert(1)</script>', false);
        $response->assertSee('\u003C/script\u003E\u003Cscript\u003Ealert(1)\u003C/script\u003E', false);
    }
}
