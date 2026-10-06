<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ConnectionTestsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['name' => 'Arjan', 'email' => 'arjan@example.com']));

        config([
            'modbot.slack.bot_token' => 'xoxb-test',
            'modbot.email.api_key' => 'aha-test',
            'modbot.email.account_id' => 'acc-1',
        ]);
    }

    public function test_it_sends_a_slack_test_message(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true, 'ts' => '1.2'])]);

        Livewire::test(Settings::class)
            ->callAction('testSlack', data: ['channel' => '#development'])
            ->assertNotified('Test message posted to #development');

        Http::assertSent(fn (Request $r) => $r['channel'] === '#development' && str_contains($r['text'], 'Slack-koppeling werkt'));
    }

    public function test_it_explains_slack_errors(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => false, 'error' => 'not_in_channel'])]);

        Livewire::test(Settings::class)
            ->callAction('testSlack', data: ['channel' => '#moderatie'])
            ->assertNotified('Slack test failed');
    }

    public function test_it_sends_a_test_email_to_the_current_user_by_default(): void
    {
        Http::fake(['api.ahasend.com/*' => Http::response(['data' => [['id' => 'msg-9']]], 202)]);

        Livewire::test(Settings::class)
            ->mountAction('testEmail')
            ->assertActionDataSet(['recipients' => 'arjan@example.com'])
            ->callMountedAction()
            ->assertNotified('Test email accepted by AhaSend');

        Http::assertSent(fn (Request $r) => $r['recipients'] === [['email' => 'arjan@example.com']]
            && $r['subject'] === 'GeenStijl ModBot testmail');
    }

    public function test_a_missing_api_key_is_reported(): void
    {
        config(['modbot.email.api_key' => null]);
        Http::fake();

        Livewire::test(Settings::class)
            ->callAction('testEmail', data: ['recipients' => 'arjan@example.com'])
            ->assertNotified('Email test failed');

        Http::assertNothingSent();
    }
}
