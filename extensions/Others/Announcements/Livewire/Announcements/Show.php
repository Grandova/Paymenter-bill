<?php

namespace Paymenter\Extensions\Others\Announcements\Livewire\Announcements;

use Livewire\Component;
use Paymenter\Extensions\Others\Announcements\Models\Announcement;

class Show extends Component
{
    public Announcement $announcement;

    public function mount()
    {
        if (!Announcement::published()->whereKey($this->announcement->id)->exists()) {
            return abort(404);
        }
    }

    public function render()
    {
        return view('announcements::show');
    }
}
