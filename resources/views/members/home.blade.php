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
            <h2 class="font-display text-lg text-gray-900 flex items-center gap-2">
              <x-heroicon-o-microphone class="h-5 w-5 text-gray-500" aria-hidden="true" />
              Services, sermons and songs
            </h2>
          </div>
          <div class="p-4 grid grid-cols-2 gap-2">
            @if($serviceTrackingEnabled ?? true)
            <x-button link="{{ route('admin.services.index') }}" icon="queue-list" iconStyle="solid">
              Services
            </x-button>

            <x-button link="{{ route('admin.services.add') }}" icon="plus" iconStyle="solid">
              Add to service
            </x-button>

            <x-button link="{{ route('admin.services.inbox') }}" icon="inbox" iconStyle="solid">
              Review inbox
              @if(($reviewInboxCount ?? 0) > 0)
              <span class="rounded-full bg-white/20 px-2 py-0.5 text-xs font-semibold">
                {{ $reviewInboxCount }}
              </span>
              @endif
            </x-button>
            @else
            {{-- Recording upload is gated only by the Sermon create Gate, so it
                 stays reachable when service tracking is switched off. --}}
            <x-button link="{{ route('admin.services.upload-recording') }}" icon="arrow-up-tray" iconStyle="solid">
              Upload sermon
            </x-button>
            @endif

            <x-button link="{{ route('admin.sermons.index') }}" icon="pencil-square" iconStyle="solid">
              Manage sermons
            </x-button>

            @if($serviceTrackingEnabled ?? true)
            <x-button link="{{ route('admin.services.songs.index') }}" icon="musical-note" iconStyle="solid">
              Song catalogue
            </x-button>
            @endif
          </div>
        </div>
        @endif

        {{-- Calendar & Meetings Section --}}
        @if (auth()->user()?->canAccessAdmin())
        <div class="rounded-lg shadow bg-white border border-gray-300 overflow-hidden">
          <div class="bg-gray-50 border-b border-gray-200 px-4 py-3">
            <h2 class="font-display text-lg text-gray-900 flex items-center gap-2">
              <x-heroicon-o-calendar-days class="h-5 w-5 text-gray-500" aria-hidden="true" />
              Meetings and events
            </h2>
          </div>
          <div class="p-4 grid grid-cols-2 gap-2">
            <x-button link="{{ route('admin.meetings.index') }}" icon="pencil-square" iconStyle="solid">
              Edit meetings
            </x-button>

            <x-button link="{{ route('admin.calendar-events.index') }}" icon="tag" iconStyle="solid">
              Categorise events
            </x-button>
          </div>
        </div>
        @endif

        {{-- Content Section --}}
        @if (auth()->user()?->canAccessAdmin())
        <div class="rounded-lg shadow bg-white border border-gray-300 overflow-hidden">
          <div class="bg-gray-50 border-b border-gray-200 px-4 py-3">
            <h2 class="font-display text-lg text-gray-900 flex items-center gap-2">
              <x-heroicon-o-document-text class="h-5 w-5 text-gray-500" aria-hidden="true" />
              Content
            </h2>
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
