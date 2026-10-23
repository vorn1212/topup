<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_privacy_and_faq_pages_open_for_guests(): void
    {
        $this->get('/privacy')->assertOk()->assertSee('Privacy Policy');
        $this->get('/faq')->assertOk()->assertSee('Frequently Asked Questions')->assertSee('FAQPage', false);
    }

    public function test_pages_are_translated_to_khmer(): void
    {
        $this->withSession(['locale' => 'km'])->get('/faq')->assertOk()->assertSee('សំណួរគេសួរញឹកញាប់')->assertDontSee('Frequently Asked Questions');
        $this->withSession(['locale' => 'km'])->get('/privacy')->assertOk()->assertSee('គោលការណ៍ឯកជនភាព');
    }

    public function test_footer_links_to_both_pages(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('href="'.route('privacy').'"', false)
            ->assertSee('href="'.route('faq').'"', false);
    }

    public function test_sitemap_lists_both_pages(): void
    {
        $this->get('/sitemap.xml')->assertOk()
            ->assertSee(route('privacy'), false)
            ->assertSee(route('faq'), false);
    }
}
