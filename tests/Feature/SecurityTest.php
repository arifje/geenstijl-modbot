<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Settings;
use App\Models\User;
use App\Notifications\LoginCode;
use App\Providers\AppServiceProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_response_tells_search_engines_not_to_index(): void
    {
        $this->get('/admin/login')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);

        $this->get('/up')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function test_new_users_get_email_codes(): void
    {
        $this->assertTrue(User::factory()->create()->fresh()->hasEmailAuthentication());
    }

    public function test_a_correct_password_is_not_enough_to_log_in(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'mod@example.com', 'password' => 'secret-password']);

        $login = Livewire::test(Login::class)
            ->fillForm(['email' => 'mod@example.com', 'password' => 'secret-password'])
            ->call('authenticate');

        $this->assertGuest();
        $code = null;
        Notification::assertSentTo($user, LoginCode::class, function (LoginCode $n) use (&$code) {
            $code = $n->code;

            return strlen($n->code) === 6;
        });
        $this->assertFalse(in_array(ShouldQueue::class, class_implements(LoginCode::class)), 'Login codes must not wait for the queue.');

        // A wrong code does not log in, the right one does.
        $login->set('data.multiFactor.email_code.code', $code === '000000' ? '111111' : '000000')
            ->call('authenticate');
        $this->assertGuest();

        $login->set('data.multiFactor.email_code.code', $code)
            ->call('authenticate');
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_ahasend_mail_driver_sends_through_the_api(): void
    {
        config([
            'modbot.email.api_key' => 'aha-test',
            'modbot.email.account_id' => 'acc-1',
        ]);
        Http::fake(['api.ahasend.com/*' => Http::response(['data' => [['id' => 'msg-1']]], 202)]);

        Mail::mailer('ahasend')->html('<p>Code: 123456</p>', function ($message) {
            $message->to('mod@example.com', 'Mod')->subject('Je inlogcode');
        });

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.ahasend.com/v2/accounts/acc-1/messages'
            && $r['recipients'] === [['email' => 'mod@example.com', 'name' => 'Mod']]
            && $r['subject'] === 'Je inlogcode'
            && str_contains($r['html_content'], '123456')
            && $r['from']['email'] === config('mail.from.address'));
    }

    public function test_without_password_login_an_emailed_code_is_enough(): void
    {
        Notification::fake();
        config(['modbot.auth.password_login' => false]);
        $user = User::factory()->create(['email' => 'mod@example.com']);

        // Step 1: only the email address.
        $login = Livewire::test(Login::class)
            ->assertFormFieldDoesNotExist('password')
            ->fillForm(['email' => 'MOD@example.com'])
            ->call('authenticate');

        $this->assertGuest();
        $code = null;
        Notification::assertSentTo($user, LoginCode::class, function (LoginCode $n) use (&$code) {
            $code = $n->code;

            return true;
        });

        // A wrong code does not log in.
        $login->set('data.multiFactor.email_code.code', '000000')
            ->call('authenticate');
        $this->assertGuest();

        // Step 2: the right code does.
        $login->set('data.multiFactor.email_code.code', $code)
            ->call('authenticate');
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_email_address_is_locked_after_too_many_failed_logins(): void
    {
        Notification::fake();
        config(['modbot.auth.max_failed_logins' => 3, 'modbot.auth.lockout_minutes' => 15]);
        $user = User::factory()->create(['email' => 'mod@example.com', 'password' => 'secret-password']);

        foreach (range(1, 3) as $i) {
            Livewire::test(Login::class)
                ->fillForm(['email' => 'mod@example.com', 'password' => "wrong-{$i}"])
                ->call('authenticate')
                ->assertHasFormErrors(['email']);
        }

        // Locked: even the right password gets no code now.
        Livewire::test(Login::class)
            ->fillForm(['email' => 'MOD@example.com', 'password' => 'secret-password'])
            ->call('authenticate')
            ->assertNotified('Too many failed login attempts');
        Notification::assertNothingSent();

        // Another account from the same IP is not locked yet.
        $other = User::factory()->create(['email' => 'other@example.com', 'password' => 'other-password']);
        Livewire::test(Login::class)
            ->fillForm(['email' => 'other@example.com', 'password' => 'other-password'])
            ->call('authenticate');
        Notification::assertSentTo($other, LoginCode::class);

        // After the lockout the account can log in again.
        $this->travel(16)->minutes();
        Livewire::test(Login::class)
            ->fillForm(['email' => 'mod@example.com', 'password' => 'secret-password'])
            ->call('authenticate');
        Notification::assertSentTo($user, LoginCode::class);
        $this->assertDatabaseHas('log_entries', ['message' => 'Login locked after too many failed attempts']);
    }

    public function test_the_lockout_limits_are_settings(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(Settings::class)
            ->assertFormSet(['max_failed_logins' => 5, 'lockout_minutes' => 15])
            ->fillForm(['max_failed_logins' => 8, 'lockout_minutes' => 30])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(8, config('modbot.auth.max_failed_logins'));
        $this->assertSame(30, config('modbot.auth.lockout_minutes'));
    }

    public function test_wrong_codes_lock_the_account(): void
    {
        Notification::fake();
        config(['modbot.auth.password_login' => false, 'modbot.auth.max_failed_logins' => 3]);
        $user = User::factory()->create(['email' => 'mod@example.com']);

        $login = Livewire::test(Login::class)
            ->fillForm(['email' => 'mod@example.com'])
            ->call('authenticate');
        $code = null;
        Notification::assertSentTo($user, LoginCode::class, function (LoginCode $n) use (&$code) {
            $code = $n->code;

            return true;
        });
        $wrong = $code === '000000' ? '111111' : '000000';

        foreach (range(1, 3) as $i) {
            $login->set('data.multiFactor.email_code.code', $wrong)->call('authenticate');
        }

        // The right code no longer works during the lockout.
        $login->set('data.multiFactor.email_code.code', $code)
            ->call('authenticate')
            ->assertNotified('Too many failed login attempts');
        $this->assertGuest();
    }

    public function test_one_ip_address_is_locked_after_failing_on_many_accounts(): void
    {
        config(['modbot.auth.max_failed_logins' => 2]);

        foreach (range(1, 8) as $i) {
            $this->travel(61)->seconds(); // stay under Filament's 5 attempts per minute
            Livewire::test(Login::class)
                ->fillForm(['email' => "user{$i}@example.com", 'password' => 'guess'])
                ->call('authenticate')
                ->assertHasFormErrors(['email']);
        }

        Livewire::test(Login::class)
            ->fillForm(['email' => 'user9@example.com', 'password' => 'guess'])
            ->call('authenticate')
            ->assertNotified('Too many failed login attempts');
    }

    public function test_without_password_login_unknown_addresses_get_no_code(): void
    {
        Notification::fake();
        config(['modbot.auth.password_login' => false]);

        Livewire::test(Login::class)
            ->fillForm(['email' => 'nobody@example.com'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        Notification::assertNothingSent();
        $this->assertGuest();
    }

    public function test_password_login_can_be_switched_from_the_command_line(): void
    {
        $this->artisan('modbot:password-login off')->assertSuccessful();
        $this->assertFalse(config('modbot.auth.password_login'));
        $this->assertDatabaseHas('settings', ['key' => 'password_login']);

        $this->artisan('modbot:password-login on')->assertSuccessful();
        $this->assertTrue(config('modbot.auth.password_login'));
    }

    public function test_links_use_https_when_app_url_is_https(): void
    {
        config(['app.url' => 'https://modbot.example.ts.net']);
        (new AppServiceProvider(app()))->boot();

        $this->get('http://modbot.example.ts.net/admin/login')
            ->assertOk()
            ->assertSee('https://modbot.example.ts.net/', false)
            ->assertDontSee('http://modbot.example.ts.net/', false);
    }
}
