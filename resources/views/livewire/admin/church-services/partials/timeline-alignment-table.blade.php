<details class="mt-4">
    <summary class="cursor-pointer text-sm text-gray-500 select-none hover:text-gray-700">
        Show detailed alignment table
    </summary>
    <div class="mt-2 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">#</th>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Type</th>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Planned</th>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Source</th>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Detected</th>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Timing</th>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                    <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Publication</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white">
                @foreach($serviceTimeline as $row)
                    @include('livewire.admin.church-services.partials.timeline-alignment-table-row', [
                        'row' => $row,
                        'rowIndex' => $loop->index,
                    ])
                @endforeach
            </tbody>
        </table>
    </div>
</details>
