<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InboundEmailStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $message_id
 * @property string $from
 * @property string $subject
 * @property string|null $body_plain
 * @property string|null $body_html
 * @property \Illuminate\Support\Carbon $received_at
 * @property InboundEmailStatus $status
 * @property array<string, mixed>|null $processing_metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
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
    /** @use HasFactory<\Database\Factories\InboundEmailFactory> */
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
}
