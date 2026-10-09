<?php

namespace Paymenter\Extensions\Others\Announcements\Livewire\Announcements;

use Livewire\Component;
use Paymenter\Extensions\Others\Announcements\Models\Announcement;

class Widget extends Component
{
    public function render()
    {
        return view('announcements::widget', [
            'announcements' => Announcement::published()->orderBy('published_at', 'desc')->get(),
        ]);
    }
}
