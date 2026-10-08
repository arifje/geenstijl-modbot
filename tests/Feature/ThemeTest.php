<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_page_uses_the_modbot_theme(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('css/modbot-theme.css?v=', false)
            ->assertSee('favicon.svg', false)
            ->assertSee('class="modbot-brand"', false);

        $this->assertFileExists(public_path('css/modbot-theme.css'));
        $this->assertFileExists(public_path('favicon.svg'));
    }
}
