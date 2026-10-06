<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use App\Notifications\LoginCode;
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

        Livewire::test(Login::class)
            ->fillForm(['email' => 'mod@example.com', 'password' => 'secret-password'])
            ->call('authenticate');

        $this->assertGuest();
        Notification::assertSentTo($user, LoginCode::class, fn (LoginCode $n) => strlen($n->code) === 6);
        $this->assertFalse(in_array(ShouldQueue::class, class_implements(LoginCode::class)), 'Login codes must not wait for the queue.');
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
}
