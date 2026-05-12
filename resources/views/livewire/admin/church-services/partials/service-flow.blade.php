@if(empty($serviceFlow))
    <p class="text-sm text-gray-500">No classified sections available for this run yet.</p>
@else
    <ol class="divide-y divide-gray-200">
        @foreach($serviceFlow as $flowItem)
            @include('livewire.admin.church-services.partials.service-flow-row', [
                'item' => $flowItem,
                'rowIndex' => $loop->index,
            ])
        @endforeach
    </ol>
@endif
