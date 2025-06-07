<?php namespace Crockenhill;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model; // It should extend Model

class Meeting extends Model
{
  use HasFactory;

  // It's good practice to define fillable properties
  protected $fillable = [
      'name',
        'slug',
        'type',
      'description',
      'day',
        'StartTime',
        'EndTime',
      'location',
        'who',
        'LeadersPhone',
        'LeadersEmail',
        'pictures',
  ];
}
