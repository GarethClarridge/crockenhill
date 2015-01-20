@if (Auth::check())

    @if (Auth::user()->can('manage_pages'))
        <aside class="card">
            
            <div class="header-container">
                <h3><a href="/members/pages">Pages</a></h3>
            </div>
            Create and edit the pages of the website.
            <div class="read-more"><a href="/members/pages">Read more ...</a></div>

        </aside>
    @endif

    @if (Auth::user()->can('manage_sermons'))
        <aside class="card">
            
            <div class="header-container">
                <h3><a href="/members/sermons">Sermons</a></h3>
            </div>
            Upload new sermons and edit old ones.
            <div class="read-more"><a href="/members/sermons">Read more ...</a></div>

        </aside>
    @endif

    @if (Auth::user()->can('manage_users'))
        <aside class="card">
            
            <div class="header-container">
                <h3><a href="/members/create">New Member</a></h3>
            </div>
            Create a new member account
            <div class="read-more"><a href="/members/create">Read more ...</a></div>

        </aside>
    @endif
@endif