<?php

namespace Crockenhill\Http\Controllers;

use Illuminate\Http\Request;
use Crockenhill\Models\Sermon;
use wapmorgan\Mp3Info\Mp3Info;

class RssFeedController extends Controller
{
  public function eveningFeed()
  {
      $sermons = \Crockenhill\Sermon::where('service', 'evening')->orderBy('created_at', 'desc')->
      limit(3)->get();

      foreach ($sermons as $sermon) {
        $audio = new Mp3Info($_SERVER['DOCUMENT_ROOT']."/media/sermons/$sermon->filename.mp3");

        $sermon['duration'] = $audio->duration;
      }

      return response()->view('rss.eveningFeed', compact('sermons'))->header('Content-Type', 'application/xml');
  }

  public function morningFeed()
  {
      $sermons = \Crockenhill\Sermon::where('service', 'morning')->orderBy('created_at', 'desc')->
      limit(3)->get();

      foreach ($sermons as $sermon) {
        $audio = new Mp3Info($_SERVER['DOCUMENT_ROOT']."/media/sermons/$sermon->filename.mp3");

        $sermon['duration'] = $audio->duration;
      }

      return response()->view('rss.eveningFeed', compact('sermons'))->header('Content-Type', 'application/xml');
  }
}
