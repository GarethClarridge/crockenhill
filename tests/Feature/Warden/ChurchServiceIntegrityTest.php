<?php

declare(strict_types=1);

namespace Tests\Feature\Warden;

use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function church_service_validation_rules_reject_invalid_data(): void
    {
        $rules = ChurchService::validationRules();

        // String length overflow
        $longString = str_repeat('a', 256);
        $this->assertTrue(Validator::make(['original_filename' => $longString], ['original_filename' => $rules['original_filename']])->fails());
        $this->assertTrue(Validator::make(['pending_structure_merge_source' => $longString], ['pending_structure_merge_source' => $rules['pending_structure_merge_source']])->fails());
    }

    #[Test]
    public function church_service_item_validation_rules_reject_invalid_data(): void
    {
        $rules = ChurchServiceItem::validationRules();

        // Integer bounding
        $this->assertTrue(Validator::make(['song_id' => 4294967296], ['song_id' => $rules['song_id']])->fails());
        $this->assertTrue(Validator::make(['livestream_service_section_id' => 9223372036854775808], ['livestream_service_section_id' => $rules['livestream_service_section_id']])->fails());

        // String length overflow
        $longString = str_repeat('a', 256);
        $this->assertTrue(Validator::make(['source_title' => $longString], ['source_title' => $rules['source_title']])->fails());
        $this->assertTrue(Validator::make(['openlp_search_title' => $longString], ['openlp_search_title' => $rules['openlp_search_title']])->fails());
        $this->assertTrue(Validator::make(['type' => $longString], ['type' => $rules['type']])->fails());

        // Non-existent processing ID
        $this->assertTrue(Validator::make(['livestream_processing_id' => (string) Str::uuid()], ['livestream_processing_id' => $rules['livestream_processing_id']])->fails());
    }

    #[Test]
    public function media_processing_log_validation_rules_reject_invalid_data(): void
    {
        $rules = MediaProcessingLog::validationRules();

        // Extracted identity
        $this->assertTrue(Validator::make(['extracted_date' => 'not-a-date'], ['extracted_date' => $rules['extracted_date']])->fails());
        $this->assertTrue(Validator::make(['extracted_service' => 'invalid-service'], ['extracted_service' => $rules['extracted_service']])->fails());

        // File size bigint bound
        $this->assertTrue(Validator::make(['file_size' => -1], ['file_size' => $rules['file_size']])->fails());

        // Stored file path
        $longString = str_repeat('a', 256);
        $this->assertTrue(Validator::make(['stored_file_path' => $longString], ['stored_file_path' => $rules['stored_file_path']])->fails());
    }
}
