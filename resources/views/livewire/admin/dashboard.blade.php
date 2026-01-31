<div>
    <x-mary-header title="Dashboard" subtitle="Welcome to Crockenhill Admin" />

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mt-6">
        <x-mary-stat
            title="Pages"
            :value="$stats['pages']"
            icon="o-document-text"
            color="text-primary"
        />

        <x-mary-stat
            title="Meetings"
            :value="$stats['meetings']"
            icon="o-calendar"
            color="text-secondary"
        />

        <x-mary-stat
            title="Sermons"
            :value="$stats['sermons']"
            icon="o-microphone"
            color="text-accent"
        />

        <x-mary-stat
            title="Users"
            :value="$stats['users']"
            icon="o-users"
            color="text-info"
        />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
        <x-mary-card title="Quick Actions">
            <div class="space-y-3">
                <x-mary-button label="Create Page" icon="o-plus" link="{{ route('admin.pages.create') }}" class="btn-block" />
                <x-mary-button label="Create Meeting" icon="o-plus" link="{{ route('admin.meetings.create') }}" class="btn-block" />
                <x-mary-button label="Upload Sermon" icon="o-cloud-arrow-up" link="{{ route('sermon.upload') }}" class="btn-block" />
            </div>
        </x-mary-card>

        <x-mary-card title="System">
            <div class="space-y-2">
                <p class="text-sm"><span class="font-semibold">Laravel:</span> {{ app()->version() }}</p>
                <p class="text-sm"><span class="font-semibold">PHP:</span> {{ PHP_VERSION }}</p>
                <p class="text-sm"><span class="font-semibold">Environment:</span> {{ app()->environment() }}</p>
            </div>
        </x-mary-card>
    </div>
</div>
