<h4>More Sermons:</h4>

<br>
<div class="btn-group">
    <button class="btn btn-default btn-lg dropdown-toggle" type="button" data-toggle="dropdown">
        By year <span class="caret"></span>
    </button>

    <ul class="dropdown-menu">
        <li>{{ link_to_route('sermonsbyyear', date('Y'), $parameters = array(date('Y')))}}</li>
        <li>{{ link_to_route('sermonsbyyear', date("Y",strtotime("-1 year")), $parameters = array(date("Y",strtotime("-1 year"))))}}</li>
    </ul>
</div>
<br><br>
<div class="btn-group">
    <button class="btn btn-default btn-lg dropdown-toggle" type="button" data-toggle="dropdown">
        By preacher <span class="caret"></span>
    </button>

    <ul class="dropdown-menu">
        @foreach ($preachers as $preacher)
            <li>{{ link_to_route('sermonsbypreacher', $preacher->preacher, $parameters = array( str_replace(' ', '_', $preacher->preacher)))}}</li>
        @endforeach
    </ul>
</div>
<br><br>
<div class="btn-group">
    <button class="btn btn-default btn-lg dropdown-toggle" type="button" data-toggle="dropdown">
        By series <span class="caret"></span>
    </button>

    <ul class="dropdown-menu">
        @foreach ($serieses as $series)
            <li>{{ link_to_route('sermonsbyseries', $series->series, $parameters = array(str_replace(' ', '_', $series->series)))}}</li>
        @endforeach
    </ul>
</div>
