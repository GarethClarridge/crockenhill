<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\MediaProcessingAccess;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\HttpException;

abstract class MediaProcessingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Provides Defense in Depth alongside the media.process middleware.
     */
    public function authorize(): bool
    {
        return app(MediaProcessingAccess::class)->allows($this);
    }

    protected function prepareForValidation(): void
    {
        if ($this->route('processingId') !== null) {
            $this->assertProcessingIdShape();
        }
    }

    /**
     * Reject malformed route-supplied processingId values with HTTP 400.
     *
     * External uploader tools predate the Form Request migration and already
     * handle 400 for a malformed processingId. Preserving that contract here
     * means only body-field validation failures surface as 422.
     *
     * @throws HttpException
     */
    protected function assertProcessingIdShape(): void
    {
        $processingId = $this->route('processingId');

        /**
         * Security: Strict length and format validation for the route parameter.
         * Explicitly bounding the length to 36 characters provides Defense in Depth
         * against extremely large input strings before the regex is even evaluated.
         */
        if (
            ! is_string($processingId)
            || strlen($processingId) !== 36
            || preg_match('/^[0-9a-fA-F-]{36}$/', $processingId) !== 1
        ) {
            throw new HttpException(400, 'Invalid processing ID format.');
        }
    }
}
