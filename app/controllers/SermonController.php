<?php

class SermonController extends BaseController {

    public function recent()
    {
        //get the latest 20 OldSermons from the database
        $evenings = OldSermon::where('service', 'evening')->orderBy('filename', 'desc')->take(10)->get();
        $mornings = OldSermon::where('service', 'morning')->orderBy('filename', 'desc')->take(10)->get();

        // and create a view which we return - note dot syntax to go into folder
        return View::make('pages.sermons', array('evenings' => $evenings, 'mornings' => $mornings));
    }

    public function current($sermon)
    {
        //get the current OldSermon
        $current_sermon = OldSermon::where('filename', $sermon)->first();

        // and create a view to return
        return View::make('pages.sermons.sermon', array('current_sermon' => $current_sermon));
    }

    public function preacher($preacher)
    {
        $preacher = str_replace('_', ' ', $preacher);
        $morning_sermons = OldSermon::where('preacher', $preacher)->where('service', 'morning')->orderBy('filename', 'desc')->get();
        $evening_sermons = OldSermon::where('preacher', $preacher)->where('service', 'evening')->orderBy('filename', 'desc')->get();

        return View::make('pages.sermons.sermonsbypreacher', array('preacher' => $preacher, 'morning_sermons' => $morning_sermons, 'evening_sermons' => $evening_sermons));
    }

    public function year($year)
    {
        $morning_sermons = OldSermon::where('date', 'LIKE', '%'.$year.'%')->where('service', 'morning')->orderBy('filename', 'desc')->get();
        $evening_sermons = OldSermon::where('date', 'LIKE', '%'.$year.'%')->where('service', 'evening')->orderBy('filename', 'desc')->get();

        return View::make('pages.sermons.sermonsbyyear', array('morning_sermons' => $morning_sermons, 'evening_sermons' => $evening_sermons, 'year' => $year));
    }

    public function series($series)
    {
        $series = str_replace('_', ' ', $series);
        $sermons = OldSermon::where('series', $series)->orderBy('filename', 'desc')->get();

        return View::make('pages.sermons.sermonsbyseries', array('sermons' => $sermons, 'series' => $series));
    }
}
?>
