<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SermonScriptureFilterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $sermon_id
 * @property string $bible_book
 * @property int $bible_chapter
 * @property-read Sermon $sermon
 *
 * @method static SermonScriptureFilterFactory factory(...$parameters)
 *
 * @mixin \Eloquent
 */
class SermonScriptureFilter extends Model
{
    /** @use HasFactory<SermonScriptureFilterFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'sermon_id',
        'bible_book',
        'bible_chapter',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'sermon_id' => 'integer',
            'bible_chapter' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Sermon, $this>
     */
    public function sermon(): BelongsTo
    {
        return $this->belongsTo(Sermon::class);
    }
}
