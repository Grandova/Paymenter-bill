<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Paymenter\Extensions\Others\Announcements\Announcements;
use Paymenter\Extensions\Others\Announcements\Livewire\Announcements\Widget;
use Paymenter\Extensions\Others\Announcements\Models\Announcement;
use Tests\TestCase;

class AnnouncementsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => 'extensions/Others/Announcements/database/migrations', '--force' => true]);
        (new Announcements)->boot();
        $this->travelTo(now()->startOfDay()->addHours(12));
    }

    public function test_only_published_announcements_are_visible_everywhere(): void
    {
        foreach ([['visible', true, now()->subMinute()], ['future', true, now()->addHour()], ['draft', false, now()->subHour()], ['undated', true, null]] as [$slug, $active, $date]) {
            Announcement::create(['title' => $slug . '-title', 'slug' => $slug, 'description' => $slug . '-description', 'content' => '<p>公告正文</p>', 'is_active' => $active, 'published_at' => $date]);
        }
        $this->assertSame(['visible'], Announcement::published()->pluck('slug')->all());
        Livewire::test(Widget::class)->assertSee('visible-title')->assertDontSee('future-title')->assertDontSee('draft-title')->assertDontSee('undated-title');
        $this->get('/')->assertOk()->assertSee('visible-title')->assertDontSee('future-title');
        $this->get('/announcements')->assertOk()->assertSee('visible-title')->assertDontSee('draft-title');
        $this->get('/announcements/visible')->assertOk()->assertSee('公告正文');
        foreach (['future', 'draft', 'undated'] as $slug) {
            $this->get('/announcements/' . $slug)->assertNotFound();
        }
        $html = view('announcements::widget', ['announcements' => Announcement::published()->get()])->render();
        $this->assertStringContainsString('/announcements/visible', $html);
        $this->travel(2)->hours();
        $this->get('/announcements/future')->assertOk();
    }

    public function test_empty_widget_does_not_abort_the_client_page(): void
    {
        Livewire::test(Widget::class)->assertOk();
        $this->get('/')->assertOk();
        $this->get('/announcements')->assertNotFound();
    }
}
