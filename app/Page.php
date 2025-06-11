<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    use HasFactory;
 
    protected $table = 'pages';

    protected $fillable = [
        'heading',
        'slug',
        'area',
        'markdown',
        'body',
        'description',
        'navigation',
    ];

    protected $casts = [
        'navigation' => 'boolean',
    ];

    /**
     * Get the route key for the model.
     *
     * @return string
     */
    public function getRouteKeyName()
    {
        return 'slug';
    }
}
