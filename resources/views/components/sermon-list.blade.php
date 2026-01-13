@props(['sermons', 'groupedByDate' => false])

@if($groupedByDate)
  {{-- Date grouped sermons (for index.blade.php and all.blade.php) --}}
  @foreach ($sermons as $date => $dateSermons)
    <section id="week-{{$date}}" class="px-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-2 justify-center max-w-2xl lg:max-w-5xl xl:max-w-7xl mx-auto mb-6 items-start">
      <h3 class="w-full font-display text-center text-xl mt-12 mb-6">
        {{ date_format(date_create($dateSermons[0]->date),'l jS F Y') }}
      </h3>
      @foreach ($dateSermons as $sermon)
        <x-sermon-card :$sermon/>
      @endforeach
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