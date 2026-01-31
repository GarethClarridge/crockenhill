<!DOCTYPE html>
<html lang="en" data-theme="crockenhill">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Admin' }} - Crockenhill</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-base-200">
    {{-- Sidebar --}}
    <x-mary-main full-width>
        <x-slot:sidebar drawer="main-drawer" collapsible class="bg-base-100">
            <x-mary-menu activate-by-route>
                <x-mary-menu-item title="Dashboard" icon="o-home" link="{{ route('admin.dashboard') }}" />

                <x-mary-menu-sub title="Content" icon="o-document-text">
                    <x-mary-menu-item title="Pages" link="{{ route('admin.pages.index') }}" />
                    <x-mary-menu-item title="Sermons" link="{{ route('admin.sermons.index') }}" />
                </x-mary-menu-sub>

                <x-mary-menu-sub title="Calendar" icon="o-calendar">
                    <x-mary-menu-item title="Meetings" link="{{ route('admin.meetings.index') }}" />
                    <x-mary-menu-item title="Events" link="{{ route('admin.calendar-events.index') }}" />
                </x-mary-menu-sub>

                <x-mary-menu-sub title="System" icon="o-cog-6-tooth">
                    <x-mary-menu-item title="Users" link="{{ route('admin.users.index') }}" />
                </x-mary-menu-sub>

                <x-mary-menu-separator />

                <x-mary-menu-item title="Upload Sermon" icon="o-cloud-arrow-up"
                    link="{{ route('sermon.upload') }}" />
                <x-mary-menu-item title="View Site" icon="o-arrow-top-right-on-square"
                    link="/" external />
            </x-mary-menu>
        </x-slot:sidebar>

        <x-slot:content>
            <x-mary-nav sticky>
                <x-slot:brand>
                    <x-mary-button label="Menu" icon="o-bars-3" class="lg:hidden"
                        @click="$dispatch('toggle-drawer', 'main-drawer')" />
                    <span class="font-bold text-lg">Crockenhill Admin</span>
                </x-slot:brand>
                <x-slot:actions>
                    <span class="text-sm text-gray-500">{{ auth()->user()->name }}</span>
                    <x-mary-button label="Logout" icon="o-arrow-right-on-rectangle"
                        link="{{ route('logout') }}" class="btn-ghost btn-sm" />
                </x-slot:actions>
            </x-mary-nav>

            <div class="p-4 lg:p-6">
                {{ $slot }}
            </div>
        </x-slot:content>
    </x-mary-main>

    <x-mary-toast />
</body>
</html>
