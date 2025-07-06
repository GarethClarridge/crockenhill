<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder; // For scope return types
use Illuminate\Support\Carbon; // For type hinting Carbon instances
use App\Enums\PageArea; // Added Enum import

/**
 * App\Models\Page
 *
 * @property int $id
 * @property string $slug
 * @property string $heading
 * @property string $description
 * @property PageArea $area
 * @property string $body
 * @property ?string $markdown
 * @property bool $navigation
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read ?string $route
 * @method static \Database\Factories\PageFactory factory(...$parameters)
 * @method static Builder|Page newModelQuery()
 * @method static Builder|Page newQuery()
 * @method static Builder|Page query()
 * @method static Builder|Page inArea(string $area)
 * @method static Builder|Page isNavigation(bool $isNavigation = true)
 * @mixin \Eloquent
 */
class Page extends Model
{
  use HasFactory;

  protected string $table = 'pages';

  /**
   * The attributes that are mass assignable.
   *
   * @var array<int, string>
   */
  protected $fillable = [
    'heading',
    'slug',
    'area',
    'markdown',
    'body',
    'description',
    'navigation',
    // 'admin' field from migration is not included here, assuming it's not mass assignable
  ];

  /**
   * The attributes that should be cast.
   *
   * @var array<string, string>
   */
  protected $casts = [
    'navigation' => 'boolean',
    'area' => PageArea::class,
    // 'admin' => 'string', // Or a custom cast if 'yes'/'no' needs specific handling
  ];

  /**
   * Get the route key for the model.
   */
  public function getRouteKeyName(): string
  {
    return 'slug';
  }

  /**
   * Get the page's full route path.
   */
  public function getRouteAttribute(): ?string
  {
    if ($this->area && $this->slug) {
      return '/' . trim($this->area, '/') . '/' . trim($this->slug, '/');
    }
    return null; // Or some default/error handling if area or slug is missing
  }

  public function scopeInArea(Builder $query, string $area): Builder
  {
    return $query->where('area', $area);
  }

  public function scopeIsNavigation(Builder $query, bool $isNavigation = true): Builder
  {
    return $query->where('navigation', $isNavigation);
  }
}
