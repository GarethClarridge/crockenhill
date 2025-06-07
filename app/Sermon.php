<?php namespace Crockenhill;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
// use Spatie\Feed\Feedable; // Commenting out as Feedable/FeedItem might not be installed/configured
// use Spatie\Feed\FeedItem;  // and are not essential for basic CRUD tests

class Sermon extends Model
{
    use HasFactory;

    protected $table = 'sermons';

    public $timestamps = false;

    protected $fillable = [
        'title',
        'filename',
        'filetype',
        'date',
        'service',
        'slug',
        'series',
        'reference',
        'preacher',
        'points',
    ];
}
