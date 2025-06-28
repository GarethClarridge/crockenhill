<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    use HasFactory;
    protected $guarded = [];

    /**
     * Get the service's formatted name.
     *
     * @return string
     */
    public function getFormattedNameAttribute()
    {
        return ucfirst($this->type) . ' Service';
    }

    /**
     * Get the sermons associated with this specific service (same date and type).
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getSermons()
    {
        return Sermon::where('date', $this->date)
                     ->where('service', $this->type)
                     ->get();
    }
}
