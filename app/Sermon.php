<?php namespace Crockenhill;

use Illuminate\Database\Eloquent\Model;
use Spatie\Feed\Feedable;
use Spatie\Feed\FeedItem;

class Sermon extends Model
{
    protected $table = 'sermons';

    public $timestamps = false;

}
