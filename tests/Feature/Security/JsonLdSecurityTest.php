<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class JsonLdSecurityTest extends TestCase
{
    /**
     * Test that the organization schema component correctly escapes special characters.
     */
    public function test_organization_schema_escapes_special_characters(): void
    {
        // Set configuration with malicious values
        config(['organization.name' => 'Church </script><script>alert("xss")</script>']);
        config(['organization.description' => 'Description with & and " quotes and \' apostrophes']);

        // Render the component
        $rendered = Blade::render('<x-schema.organization />');

        // Log for debugging
        file_put_contents('rendered_output.txt', $rendered);

        // Assert that the rendered output does not contain the raw malicious tags
        $this->assertStringNotContainsString('</script><script>', $rendered);

        // Assert that it contains the correctly escaped JSON hex entities
        $this->assertStringContainsString('\u003C/script\u003E\u003Cscript\u003E', $rendered);

        // Check for ampersand escaping (JSON_HEX_AMP)
        $this->assertStringContainsString('\u0026', $rendered);

        // Check for quote escaping (JSON_HEX_QUOT)
        $this->assertStringContainsString('\u0022', $rendered);

        // Check for apostrophe escaping (JSON_HEX_APOS)
        $this->assertStringContainsString('\u0027', $rendered);
    }
}
