<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Data\OosEmailSourceDocument;
use App\Services\Email\OosSemanticAnnotationSchema;
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
}
