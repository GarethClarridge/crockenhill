@if (Sentry::check())
    <ul class="nav">
        <li class="{{ Request::is('admin/pages*') ? 'active' : null }}">
            <a href="{{ URL::route('admin.pages.index') }}"><i class="icon-book"></i> Pages</a>
        </li>
        <li class="{{ Request::is('admin/sermons*') ? 'active' : null }}">
            <a href="{{ URL::route('admin.sermons.index') }}"><i class="icon-edit"></i> Sermons</a>
        </li>
        <li>
            <a href="{{ URL::route('admin.logout') }}"><i class="icon-lock"></i> Logout</a>
        </li>
    </ul>
@endif
