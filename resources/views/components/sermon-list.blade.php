@props(['sermons', 'groupedByDate' => false])

@if($groupedByDate)
  {{-- Date grouped sermons (for index.blade.php and all.blade.php) --}}
  @foreach ($sermons as $date => $dateSermons)
    <section id="week-{{$date}}" class="px-6 max-w-2xl lg:max-w-5xl xl:max-w-7xl mx-auto mb-8">
      <x-h2>
        {{ date_format(date_create($dateSermons[0]->date),'l jS F') }}
      </x-h2>
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-2 justify-center items-start">
        @foreach ($dateSermons as $sermon)
          <x-sermon-card :$sermon/>
        @endforeach
      </div>
    </section>
  @endforeach
@else
  {{-- Simple sermon list (for preacher.blade.php, series.blade.php, service.blade.php) --}}
  <div class="px-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-2 justify-center max-w-2xl lg:max-w-5xl xl:max-w-7xl mx-auto mb-6 items-start">
    @foreach ($sermons as $sermon)
      <x-sermon-card :$sermon/>
    @endforeach
  </div>
@endif
