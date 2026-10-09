<?php

namespace Paymenter\Extensions\Others\Announcements\Livewire\Announcements;

use Livewire\Component;
use Paymenter\Extensions\Others\Announcements\Models\Announcement;

class Index extends Component
{
    public function mount()
    {
        if (Announcement::published()->count() == 0) {
            return abort(404);
        }
    }

    public function render()
    {
        return view('announcements::index', [
            'announcements' => Announcement::published()->orderBy('published_at', 'desc')->get(),
        ]);
    }
}
