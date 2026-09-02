<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;

class FixtureModelWithAttributes extends Model
{
    protected $attributes = [
        'status' => 'pending',
    ];

    protected $fillable = [
        'status',
    ];

    protected function casts(): array
    {
        return [];
    }
}
