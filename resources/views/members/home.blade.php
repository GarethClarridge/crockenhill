@extends('layouts/main')

@section('title', 'Members')

@section('content')
<main id="main-content" class="mb-3" tabindex="-1">
  <article>

    <x-h1>Members</x-h1>

    <x-content-wrapper>
      <div class="space-y-6">

        {{-- Welcome Header --}}
        <div class="bg-cbc-teal/10 border border-cbc-teal/25 rounded-lg p-4">
          <div class="flex items-center gap-3">
            <x-heroicon-s-user-circle class="h-7 w-7 text-cbc-teal flex-shrink-0" aria-hidden="true" />
            <div>
              <h2 class="font-display text-xl text-cbc-teal">
                Welcome back, {{ auth()->user()->name }}
              </h2>
              <p class="text-sm text-gray-600">What would you like to do today?</p>
            </div>
          </div>
        </div>

        {{-- Sermons Section --}}
        @if (auth()->user()?->canAccessAdmin())
        <div class="rounded-lg shadow bg-white border border-gray-300 overflow-hidden">
          <div class="bg-gray-50 border-b border-gray-200 px-4 py-3">
            <h3 class="font-display text-lg text-gray-900 flex items-center gap-2">
              <x-heroicon-o-microphone class="h-5 w-5 text-gray-500" aria-hidden="true" />
              Services, sermons and songs
            </h3>
          </div>
          <div class="p-4 grid grid-cols-2 gap-2">
            <x-button link="{{ route('admin.sermon-upload.create') }}" icon="arrow-up-tray" iconStyle="solid">
              Upload sermon
            </x-button>

            <x-button link="{{ route('admin.sermons.index') }}" icon="pencil-square" iconStyle="solid">
              Manage sermons
            </x-button>

            <x-button link="{{ route('admin.services.upload') }}" icon="arrow-up-tray" iconStyle="solid">
              Upload service order
            </x-button>

            <x-button link="{{ route('admin.services.index') }}" icon="queue-list" iconStyle="solid">
              View services
            </x-button>

            <x-button link="{{ route('admin.services.inbound-emails') }}" icon="envelope" iconStyle="solid">
              Review inbound emails
              @if(($pendingInboundEmailCount ?? 0) > 0)
              <span class="rounded-full bg-white/20 px-2 py-0.5 text-xs font-semibold">
                {{ $pendingInboundEmailCount }}
              </span>
              @endif
            </x-button>

            <x-button link="{{ route('admin.services.processing.review.index') }}" icon="video-camera" iconStyle="solid">
              Sermon review
              @if(($pendingSermonReviewCount ?? 0) > 0)
              <span class="rounded-full bg-white/20 px-2 py-0.5 text-xs font-semibold">
                {{ $pendingSermonReviewCount }}
              </span>
              @endif
            </x-button>

            <x-button link="{{ route('admin.services.songs.index') }}" icon="musical-note" iconStyle="solid">
              Song catalogue
            </x-button>
          </div>
        </div>
        @endif

        {{-- Calendar & Meetings Section --}}
        @if (auth()->user()?->canAccessAdmin())
        <div class="rounded-lg shadow bg-white border border-gray-300 overflow-hidden">
          <div class="bg-gray-50 border-b border-gray-200 px-4 py-3">
            <h3 class="font-display text-lg text-gray-900 flex items-center gap-2">
              <x-heroicon-o-calendar-days class="h-5 w-5 text-gray-500" aria-hidden="true" />
              Meetings and events
            </h3>
          </div>
          <div class="p-4 grid grid-cols-2 gap-2">
            <x-button link="{{ route('admin.meetings.index') }}" icon="pencil-square" iconStyle="solid">
              Edit meetings
            </x-button>

            <x-button link="{{ route('admin.calendar.patterns') }}" icon="list-bullet" iconStyle="solid">
              Event:meeting patterns
            </x-button>

            <x-button link="{{ route('admin.calendar-events.index') }}" icon="tag" iconStyle="solid">
              Categorise events
            </x-button>

            <form method="POST" action="{{ route('admin.calendar.sync') }}" class="block">
              @csrf
              <x-form-button class="w-full" icon="arrow-path">
                Sync Google Calendar
              </x-form-button>
            </form>
          </div>
        </div>
        @endif

        {{-- Content Section --}}
        @if (auth()->user()?->canAccessAdmin())
        <div class="rounded-lg shadow bg-white border border-gray-300 overflow-hidden">
          <div class="bg-gray-50 border-b border-gray-200 px-4 py-3">
            <h3 class="font-display text-lg text-gray-900 flex items-center gap-2">
              <x-heroicon-o-document-text class="h-5 w-5 text-gray-500" aria-hidden="true" />
              Content
            </h3>
          </div>
          <div class="p-4">
            <x-button link="/admin/pages" icon="pencil-square" iconStyle="solid">
              Edit pages
            </x-button>
          </div>
        </div>
        @endif

        {{-- Log out --}}
        <form action="/logout" method="post">
          @csrf
          <x-form-button variant="danger" class="w-full" icon="arrow-right-start-on-rectangle" name="logout">
            Log out
          </x-form-button>
        </form>

      </div>
    </x-content-wrapper>

  </article>
</main>
@stop
