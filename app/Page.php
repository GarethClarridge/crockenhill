<?php namespace Crockenhill;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Page extends Model {
    use HasFactory;

    protected $table = 'pages';

    protected $fillable = [
        'title',
        'slug',
        'area',
        'heading',
        'description',
        'content',
        'navigation',
    ];
 
}
