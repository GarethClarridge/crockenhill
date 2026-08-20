<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Data\OosEmailSourceDocument;
use App\Services\Email\OosSemanticAnnotationSchema;
use App\Support\OpenAiJsonSchemaLimits;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OosSemanticAnnotationSchemaTest extends TestCase
{
    #[Test]
    public function every_physical_source_line_is_an_exact_required_annotation_property(): void
    {
        $schema = (new OosSemanticAnnotationSchema)->build(
            OosEmailSourceDocument::fromBody("Morning\n\nSong"),
        );
        $annotations = $schema['properties']['annotations'];

        $this->assertSame(['L001', 'L003'], $annotations['required']);
        $this->assertSame(['L001', 'L003'], array_keys($annotations['properties']));
        $this->assertFalse($annotations['additionalProperties']);
        $this->assertSame(
            ['role', 'service_group_id', 'item_kind', 'continuation_target_line_id', 'uncertainty', 'shared_service_group_ids', 'boundary_also_item'],
            $annotations['properties']['L001']['required'],
        );
    }

    #[Test]
    public function per_line_field_enums_are_declared_once_in_definitions(): void
    {
        $schema = (new OosSemanticAnnotationSchema)->build(
            OosEmailSourceDocument::fromBody("Morning\nSong\nReading"),
        );

        $this->assertContains('item', $schema['$defs']['semantic_role']['enum']);
        $this->assertContains(null, $schema['$defs']['semantic_item_kind']['enum']);

        foreach (['L001', 'L002', 'L003'] as $key) {
            $line = $schema['properties']['annotations']['properties'][$key];

            $this->assertSame(['$ref' => '#/$defs/semantic_role'], $line['properties']['role']);
            $this->assertSame(['$ref' => '#/$defs/semantic_item_kind'], $line['properties']['item_kind']);
            $this->assertSame(['$ref' => '#/$defs/semantic_uncertainty'], $line['properties']['uncertainty']);
        }
    }

    #[Test]
    public function a_continuation_may_only_target_the_immediately_preceding_physical_line(): void
    {
        $schema = (new OosSemanticAnnotationSchema)->build(
            OosEmailSourceDocument::fromBody("Morning\nSong\n\nReading"),
        );
        $lines = $schema['properties']['annotations']['properties'];

        $this->assertSame(
            ['type' => ['integer', 'null'], 'enum' => [1, null]],
            $lines['L002']['properties']['continuation_target_line_id'],
        );
    }

    #[Test]
    public function the_first_line_and_a_line_after_a_blank_may_not_continue_anything(): void
    {
        $schema = (new OosSemanticAnnotationSchema)->build(
            OosEmailSourceDocument::fromBody("Morning\nSong\n\nReading"),
        );
        $lines = $schema['properties']['annotations']['properties'];

        $this->assertSame(['$ref' => '#/$defs/no_continuation_target'], $lines['L001']['properties']['continuation_target_line_id']);
        $this->assertSame(['$ref' => '#/$defs/no_continuation_target'], $lines['L004']['properties']['continuation_target_line_id']);
        $this->assertSame([null], $schema['$defs']['no_continuation_target']['enum']);
    }

    #[Test]
    public function a_long_source_stays_inside_the_provider_enum_budget(): void
    {
        $schema = new OosSemanticAnnotationSchema;
        $short = OpenAiJsonSchemaLimits::measure($schema->build($this->sourceOfLines(40)));
        $long = OpenAiJsonSchemaLimits::measure($schema->build($this->sourceOfLines(140)));

        $this->assertSame(164, $short['enum_values']);
        $this->assertSame(464, $long['enum_values']);
        $this->assertLessThan(OpenAiJsonSchemaLimits::MaxEnumValues, $long['enum_values']);

        // Three enum values per added line — the line's own continuation target and one boundary
        // line ID — rather than the one-per-line-per-line growth that made a 30-line source
        // unsendable.
        $this->assertSame(3 * 100, $long['enum_values'] - $short['enum_values']);
    }

    private function sourceOfLines(int $count): OosEmailSourceDocument
    {
        $lines = [];

        for ($line = 1; $line <= $count; $line++) {
            $lines[] = "Item {$line}";
        }

        return OosEmailSourceDocument::fromBody(implode("\n", $lines));
    }
}
