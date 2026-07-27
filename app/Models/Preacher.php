<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Path;
use Database\Factories\PreacherFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * App\Models\Preacher
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property ?string $image_path
 * @property ?string $bio
 * @property bool $is_active
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read Sermon|null $latestSermon
 *
 * @method static \Database\Factories\PreacherFactory factory(...$parameters)
 * @method static Builder|Preacher newModelQuery()
 * @method static Builder|Preacher newQuery()
 * @method static Builder|Preacher query()
 * @method static Builder|Preacher active()
 *
 * @mixin \Eloquent
 */
class Preacher extends Model
{
    /** @use HasFactory<PreacherFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'image_path',
        'bio',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return Attribute<string, string>
     */
    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => trim($value),
        );
    }

    /**
     * @return array<string, list<string|mixed>>
     */
    public static function validationRules(?self $preacher = null): array
    {
        $nameRule = ['required', 'string', 'max:255'];
        $slugRule = ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'];

        $uniqueName = Rule::unique('preachers', 'name');
        $uniqueSlug = Rule::unique('preachers', 'slug');

        if ($preacher) {
            $uniqueName->ignore($preacher->id);
            $uniqueSlug->ignore($preacher->id);
        }

        $nameRule[] = $uniqueName;
        $slugRule[] = $uniqueSlug;

        return [
            'name' => $nameRule,
            'slug' => $slugRule,
            'image_path' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:10000'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * Get the preacher's profile image URL.
     * Handles absolute URLs and local storage paths automatically.
     *
     * @return Attribute<string|null, never>
     */
    protected function profileImageUrl(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                if (blank($this->image_path)) {
                    return null;
                }

                if (Path::isAlreadyResolvableUrl($this->image_path)) {
                    return $this->image_path;
                }

                return Storage::disk('public')->url($this->image_path);
            }
        )->shouldCache();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return HasMany<Sermon, $this>
     */
    public function sermons(): HasMany
    {
        return $this->hasMany(Sermon::class);
    }

    /**
     * @return HasOne<Sermon, $this>
     */
    public function latestSermon(): HasOne
    {
        return $this->hasOne(Sermon::class)->whereSermon()->latestOfMany(['date', 'id']);
    }

    /**
     * @return HasMany<PreacherAlias, $this>
     */
    public function aliases(): HasMany
    {
        return $this->hasMany(PreacherAlias::class);
    }

    /**
     * @return HasMany<SpeakerProfile, $this>
     */
    public function speakerProfiles(): HasMany
    {
        return $this->hasMany(SpeakerProfile::class);
    }

    /**
     * @param  Builder<Preacher>  $query
     * @return Builder<Preacher>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
