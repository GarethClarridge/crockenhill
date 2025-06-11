<?php namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;
use Spatie\Feed\Feedable;
use Spatie\Feed\FeedItem;

class Sermon extends Model
{
    use HasFactory;

    protected $table = 'sermons';

    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'title',
        'filename',
        'date',
        'service',
        'slug',
        'series',
        'reference',
        'preacher',
        'points',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'date' => 'date',
        'points' => 'array',
    ];

    public function getSeriesUrlAttribute()
    {
        return '/series/' . Str::slug($this->series);
    }

    public function getPreacherUrlAttribute()
    {
        return '/preachers/' . Str::slug($this->preacher);
    }
}
