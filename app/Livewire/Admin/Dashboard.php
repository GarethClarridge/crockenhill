<?php

namespace App\Livewire\Admin;

use App\Models\Page;
use App\Models\Meeting;
use App\Models\Sermon;
use App\Models\User;
use Livewire\Component;

class Dashboard extends Component
{
    public function render()
    {
        $stats = [
            'pages' => Page::count(),
            'meetings' => Meeting::count(),
            'sermons' => Sermon::count(),
            'users' => User::count(),
        ];

        return view('livewire.admin.dashboard', compact('stats'))
            ->layout('components.layouts.admin', ['title' => 'Dashboard']);
    }
}
