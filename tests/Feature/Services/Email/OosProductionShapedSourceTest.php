<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Email;

use App\Data\OosEmailSourceDocument;
use App\Models\InboundEmail;
use App\Services\Email\OosEmailParserService;
use App\Services\Email\OosSemanticAnnotationSchema;
use App\Support\OpenAiJsonSchemaLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The Delivery 7 check that the private corpus cannot make: does the parser's deterministic front
 * end survive an email shaped like one a person actually sends?
 *
 * The 38-source evaluation corpus is curated. Measured over it, 31 of 38 sources carry none of the
 * artefacts real mail carries — no reply headers, no quoted lines, no mobile signature, no footer —
 * because curation stripped them before the text was frozen. Every correctness figure in §9 is
 * therefore a figure about *clean* text, and the gap is input shape rather than era: the corpus
 * already spans 2015 to eighteen days before the candidate was scored.
 *
 * Delivery 7 originally asked for a "current-era replay" to close this. That is not achievable and
 * would not close it: Mailgun inbound routing has never been configured, so no real weekly email has
 * ever arrived to replay, and the era the phrase points at is already in the corpus. What is left
 * once that is stripped away is exactly this — noise-shaped input, which is a fixture question and
 * costs nothing to answer.
 *
 * These tests deliberately assert the *deterministic* half only. What the model then makes of a
 * quoted reply chain is a correctness question the corpus and its gates already own; what must hold
 * here is that every such line survives normalisation and stays individually addressable, so the
 * annotator is able to classify it as `forwarded_context` or `supporting_detail` rather than the
 * normaliser having silently thrown it away before anything could.
 */
class OosProductionShapedSourceTest extends TestCase
{
    use RefreshDatabase;

    /** A body carrying the things curation removes: a reply chain, quoting, a signature, a footer. */
    private const NoisyBody = <<<'BODY'
    Hi all,

    Order for Sunday morning below.

    Hymn 100 Praise the Lord
    Reading: Luke 15:1-32
    Sermon: The Word became flesh

    Thanks,
    Jane

    Sent from my iPhone

    -----Original Message-----
    From: Someone Else <someone@example.com>
    Sent: Friday, 1 August 2026 09:14
    To: Jane <jane@example.com>
    Subject: Re: Order of service

    > Could you send the order over when you get a chance?
    > Thanks

    This email and any attachments are confidential.
    BODY;

    #[Test]
    public function every_noisy_line_survives_normalisation_and_stays_addressable(): void
    {
        $source = OosEmailSourceDocument::fromContext('Order of service', self::NoisyBody, '2026-08-02');

        $lines = [];

        foreach ($source->lineIds() as $lineId) {
            $lines[$lineId] = $source->exactLine($lineId);
        }

        $this->assertNotEmpty($lines);

        /**
         * The point of the check: the artefacts are *present and individually addressable*. A
         * normaliser that helpfully stripped a quoted reply would take the decision away from the
         * annotator, and the annotator is the only layer that can tell a forwarded request from the
         * order being requested.
         */
        $joined = implode("\n", array_map(strval(...), $lines));

        foreach ([
            'Sent from my iPhone',
            '-----Original Message-----',
            '> Could you send the order over when you get a chance?',
            'This email and any attachments are confidential.',
            'Hymn 100 Praise the Lord',
        ] as $fragment) {
            $this->assertStringContainsString(
                $fragment,
                $joined,
                "Normalisation dropped {$fragment}, so no annotation could ever classify it.",
            );
        }

        // Line IDs must be unique and every one must resolve, or the annotation schema's per-line
        // required properties cannot be generated from them.
        $this->assertSame(array_values(array_unique($source->lineIds())), array_values($source->lineIds()));

        foreach ($source->lineIds() as $lineId) {
            $this->assertTrue($source->hasLine($lineId));
            $this->assertNotNull($source->exactLine($lineId));
        }
    }

    #[Test]
    public function an_html_only_body_normalises_to_the_same_lines_as_its_plain_equivalent(): void
    {
        $plain = "Order for Sunday\nHymn 100 Praise the Lord\nReading: Luke 15:1-32";
        $html = '<div>Order for Sunday</div><div>Hymn 100 Praise the Lord</div><p>Reading: Luke 15:1-32</p>';

        $fromPlain = $this->preferredBody(InboundEmail::factory()->make([
            'body_plain' => $plain,
            'body_html' => null,
        ]));

        /**
         * `preferredBody()` prefers plain and only falls back to stripping HTML when plain is empty.
         * Locally no stored row has `body_html` populated at all, so this fallback is the least
         * exercised path in the front end and the one most likely to rot unnoticed.
         */
        $fromHtml = $this->preferredBody(InboundEmail::factory()->make([
            'body_plain' => null,
            'body_html' => $html,
        ]));

        $this->assertSame(
            OosEmailSourceDocument::fromBody($plain)->lineRecords(),
            OosEmailSourceDocument::fromBody($fromHtml)->lineRecords(),
            'The HTML fallback must produce the same addressable lines as the plain equivalent.',
        );
        $this->assertSame($plain, $fromPlain);
    }

    #[Test]
    public function a_noisy_source_still_generates_a_schema_within_the_provider_limits(): void
    {
        $source = OosEmailSourceDocument::fromContext('Order of service', self::NoisyBody, '2026-08-02');
        $schema = (new OosSemanticAnnotationSchema)->build($source);

        $counts = OpenAiJsonSchemaLimits::measure($schema);

        /**
         * Noise inflates the schema because every line becomes a required property. A signature and
         * a quoted reply chain are exactly the kind of input that turns a short order into a long
         * document, which is the failure mode the schema-cap fix exists for.
         */
        $this->assertLessThanOrEqual(OpenAiJsonSchemaLimits::MaxEnumValues, $counts['enum_values']);
        $this->assertLessThanOrEqual(OpenAiJsonSchemaLimits::MaxProperties, $counts['properties']);
        $this->assertLessThanOrEqual(OpenAiJsonSchemaLimits::MaxStringLength, $counts['string_length']);
    }

    private function preferredBody(InboundEmail $inboundEmail): string
    {
        $method = new ReflectionMethod(OosEmailParserService::class, 'preferredBody');

        return (string) $method->invoke(app(OosEmailParserService::class), $inboundEmail);
    }
}
