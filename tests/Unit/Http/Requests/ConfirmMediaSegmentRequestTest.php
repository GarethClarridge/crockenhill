<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\ConfirmMediaSegmentRequest;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConfirmMediaSegmentRequestTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function validation_rules_pass_with_valid_data(): void
    {
        $log = MediaProcessingLog::factory()->create();
        $segment = LivestreamSegment::factory()->forProcessingLog($log->id)->create();

        $data = [
            'segment_id' => $segment->id,
        ];

        $request = new ConfirmMediaSegmentRequest;
        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->passes());
    }

    #[Test]
    public function validation_rules_reject_missing_segment_id(): void
    {
        $data = [];

        $request = new ConfirmMediaSegmentRequest;
        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('segment_id', $validator->errors()->toArray());
    }

    #[Test]
    public function validation_rules_reject_non_integer_segment_id(): void
    {
        $data = [
            'segment_id' => 'not-an-integer',
        ];

        $request = new ConfirmMediaSegmentRequest;
        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('segment_id', $validator->errors()->toArray());
    }

    #[Test]
    public function validation_rules_reject_oversized_segment_id_digit_length(): void
    {
        $data = [
            'segment_id' => '12345678901', // 11 digits
        ];

        $request = new ConfirmMediaSegmentRequest;
        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('segment_id', $validator->errors()->toArray());
    }
}
