<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarqueeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_home_page_shows_a_default_scrolling_text(): void
    {
        $this->get('/')->assertOk()->assertSee('gg-marquee-track', false)->assertSee(SiteSettings::DEFAULT_MARQUEE);
    }

    public function test_a_saved_text_replaces_the_default_and_is_escaped(): void
    {
        app(SiteSettings::class)->set(['marquee_text' => 'Big sale <b>today</b>']);

        $this->get('/')->assertOk()
            ->assertSee('Big sale &lt;b&gt;today&lt;/b&gt;', false)
            ->assertDontSee('Big sale <b>today</b>', false)
            ->assertDontSee(SiteSettings::DEFAULT_MARQUEE);
    }

    public function test_an_empty_text_hides_the_line(): void
    {
        app(SiteSettings::class)->set(['marquee_text' => '']);

        $this->get('/')->assertOk()->assertDontSee('gg-marquee-track', false);
    }

    public function test_khmer_visitors_get_the_khmer_text_or_the_english_one(): void
    {
        app(SiteSettings::class)->set(['marquee_text' => 'English sale']);
        $this->withSession(['locale' => 'km'])->get('/')->assertOk()->assertSee('English sale');

        app(SiteSettings::class)->set(['marquee_text_km' => 'អត្ថបទខ្មែរ']);
        $this->withSession(['locale' => 'km'])->get('/')->assertOk()->assertSee('អត្ថបទខ្មែរ')->assertDontSee('English sale');
        $this->withSession(['locale' => 'en'])->get('/')->assertOk()->assertSee('English sale');
    }

    public function test_the_admin_form_has_the_text_boxes(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'super_admin', 'status' => 'active'])->save();

        $this->actingAs($admin)->get('/admin/settings')->assertOk()
            ->assertSee('name="marquee_text"', false)
            ->assertSee('name="marquee_text_km"', false);
    }
}
