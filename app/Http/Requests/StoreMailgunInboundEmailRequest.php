<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\InboundEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Validator;

class StoreMailgunInboundEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Security: Explicit length constraints are enforced on all text fields to provide
     * Defense in Depth against Denial of Service (DoS) attempts with oversized payloads.
     *
     * @return array<string, array<int, string>|string>
     */
    public function rules(): array
    {
        $modelRules = InboundEmail::validationRules();

        return [
            'timestamp' => ['required', 'string', 'max:50'],
            'token' => ['required', 'string', 'max:100'],
            'signature' => ['required', 'string', 'max:128'],
            'from' => $modelRules['from'],
            'subject' => $modelRules['subject'],
            // The Message-Id can be in the direct POST data or nested in message-headers.
            // If it is in the POST data, we validate it against model rules (excluding required).
            // We also exclude the unique rule because the controller handles duplicates gracefully.
            'Message-Id' => [
                'nullable',
                ...array_filter($modelRules['message_id'], fn ($rule) => $rule !== 'required' && ! ($rule instanceof Unique)),
            ],
            'message-headers' => ['nullable', 'string', 'max:100000'],
            'body-plain' => ['nullable', 'string', 'max:500000'],
            'body-html' => ['nullable', 'string', 'max:500000'],
            'Date' => ['nullable', 'string', 'max:128'],
            'recipient' => ['nullable', 'email', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->messageId() === null) {
                $validator->errors()->add('Message-Id', 'The Message-Id field is required.');
            }

            if ($this->bodyPlain() === null && $this->bodyHtml() === null) {
                $validator->errors()->add('body-plain', 'The body-plain field is required when body-html is not present.');
            }
        });
    }

    public function messageId(): ?string
    {
        $messageId = $this->normaliseString($this->input('Message-Id'));
        if ($messageId !== null) {
            return $messageId;
        }

        foreach ($this->parsedMessageHeaders() as $header) {
            [$name, $value] = $header;

            if (strtolower($name) === 'message-id') {
                return $this->normaliseString($value);
            }
        }

        return null;
    }

    public function bodyPlain(): ?string
    {
        return $this->normaliseString($this->input('body-plain'));
    }

    public function bodyHtml(): ?string
    {
        return $this->normaliseString($this->input('body-html'));
    }

    public function receivedAt(): Carbon
    {
        $dateHeader = $this->normaliseString($this->input('Date'));

        if ($dateHeader === null) {
            return now();
        }

        try {
            return Carbon::parse($dateHeader);
        } catch (\Throwable) {
            return now();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function processingMetadata(): array
    {
        $metadata = Arr::except($this->all(), [
            'timestamp',
            'token',
            'signature',
            'from',
            'subject',
            'body-plain',
            'body-html',
            'recipient',
        ]);

        $metadata['resolved_message_id'] = $this->messageId();
        $metadata['parsed_message_headers'] = $this->parsedMessageHeaders();

        return $metadata;
    }

    /**
     * @return array<int, array{0:string,1:string}>
     */
    private function parsedMessageHeaders(): array
    {
        $headers = $this->input('message-headers');
        if (! is_string($headers) || trim($headers) === '') {
            return [];
        }

        $decoded = json_decode($headers, true);
        if (! is_array($decoded)) {
            return [];
        }

        $parsed = [];

        foreach ($decoded as $header) {
            if (
                ! is_array($header)
                || ! array_key_exists(0, $header)
                || ! array_key_exists(1, $header)
                || ! is_string($header[0])
                || ! is_string($header[1])
            ) {
                continue;
            }

            $parsed[] = [
                $header[0],
                $header[1],
            ];
        }

        return $parsed;
    }

    private function normaliseString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
