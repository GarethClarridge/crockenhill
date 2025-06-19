<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Sermon;
use wapmorgan\Mp3Info\Mp3Info;

class RssFeedController extends Controller
{
  public function eveningFeed()
  {
    $sermons = \App\Sermon::whereYear('date', '>=', '2022')
      ->where('service', 'evening')
      ->orderBy('created_at', 'desc')
      ->get();

    foreach ($sermons as $sermon) {
      if (file_exists($_SERVER['DOCUMENT_ROOT'] . "/media/sermons/$sermon->filename.mp3")) {
        $audio = new Mp3Info($_SERVER['DOCUMENT_ROOT'] . "/media/sermons/$sermon->filename.mp3");
      }

      $sermon['duration'] = $audio->duration;
    }

    return response()->view('rss.eveningFeed', compact('sermons'))->header('Content-Type', 'application/xml');
  }

  public function morningFeed()
  {
    $sermons = \App\Sermon::whereYear('date', '>=', '2022')
      ->where('service', 'morning')
      ->orderBy('created_at', 'desc')
      ->get();

    foreach ($sermons as $sermon) {
      if (file_exists($_SERVER['DOCUMENT_ROOT'] . "/media/sermons/$sermon->filename.mp3")) {
        $audio = new Mp3Info($_SERVER['DOCUMENT_ROOT'] . "/media/sermons/$sermon->filename.mp3");
      }

      $sermon['duration'] = $audio->duration;
    }

    return response()->view('rss.morningFeed', compact('sermons'))->header('Content-Type', 'application/xml');
  }
}
