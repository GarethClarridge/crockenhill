<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InboundEmailStatus;
use Database\Factories\InboundEmailFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * @property int $id
 * @property string $message_id
 * @property string $from
 * @property string $subject
 * @property string|null $body_plain
 * @property string|null $body_html
 * @property Carbon $received_at
 * @property InboundEmailStatus $status
 * @property array<string, mixed>|null $processing_metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static \Database\Factories\InboundEmailFactory factory(...$parameters)
 * @method static Builder<InboundEmail> newModelQuery()
 * @method static Builder<InboundEmail> newQuery()
 * @method static Builder<InboundEmail> query()
 *
 * @mixin \Eloquent
 */
class InboundEmail extends Model
{
    /** @use HasFactory<InboundEmailFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'message_id',
        'from',
        'subject',
        'body_plain',
        'body_html',
        'received_at',
        'status',
        'processing_metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'status' => InboundEmailStatus::class,
            'processing_metadata' => 'array',
        ];
    }

    /**
     * @return Attribute<string, string>
     */
    protected function from(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => trim($value),
        );
    }

    /**
     * @return Attribute<string, string>
     */
    protected function subject(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => trim($value),
        );
    }

    /**
     * @return array<string, list<string|mixed>>
     */
    public static function validationRules(?self $inboundEmail = null): array
    {
        $messageIdRule = ['required', 'string', 'max:512'];
        $uniqueMessageId = Rule::unique('inbound_emails', 'message_id');
        if ($inboundEmail) {
            $uniqueMessageId->ignore($inboundEmail->id);
        }
        $messageIdRule[] = $uniqueMessageId;

        return [
            'message_id' => $messageIdRule,
            'from' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'body_plain' => ['nullable', 'string', 'max:500000'],
            'body_html' => ['nullable', 'string', 'max:500000'],
            'received_at' => ['required', 'date'],
            'status' => ['required', Rule::enum(InboundEmailStatus::class)],
        ];
    }
}
