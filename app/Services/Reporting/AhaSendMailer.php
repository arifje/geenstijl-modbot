<?php

namespace App\Services\Reporting;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

class AhaSendMailer
{
    /**
     * Sends the report and returns what was sent, for the log.
     *
     * @return array{id: ?string, recipients: list<string>, subject: string, sandbox: bool}
     */
    public function send(Report $report): array
    {
        $recipients = $this->recipients((string) config('modbot.email.recipients'));
        $subject = 'GeenStijl '.Str::lcfirst($report->title());

        $id = $this->deliver(
            $recipients,
            $subject,
            view('emails.report', ['report' => $report])->render(),
            view('emails.report-text', ['report' => $report])->render(),
        );

        return [
            'id' => $id,
            'recipients' => array_column($recipients, 'email'),
            'subject' => $subject,
            'sandbox' => (bool) config('modbot.email.sandbox'),
        ];
    }

    /**
     * Sends a short test message through Laravel's default mailer, the same
     * route as the login codes. Returns the mailer that was used.
     */
    public function sendTest(string $recipients): string
    {
        $to = $this->recipients($recipients);

        if ($to === []) {
            throw new RuntimeException('No email recipients given.');
        }

        Mail::html(view('emails.test')->render(), function ($message) use ($to) {
            foreach ($to as $recipient) {
                $message->to($recipient['email'], $recipient['name'] ?? null);
            }

            $message->subject('GeenStijl ModBot testmail');
        });

        return config('mail.default');
    }

    /**
     * @param  list<array{email: string, name?: string}>  $recipients
     * @param  array{email: string, name?: string}|null  $from  defaults to the configured sender
     */
    public function deliver(array $recipients, string $subject, ?string $html, ?string $text, ?array $from = null): ?string
    {
        $config = config('modbot.email');

        if ($recipients === []) {
            throw new RuntimeException('No email recipients configured.');
        }

        if (blank($config['api_key']) || blank($config['account_id'])) {
            throw new RuntimeException('AHASEND_API_KEY or AHASEND_ACCOUNT_ID is not set.');
        }

        $response = Http::withToken($config['api_key'])
            ->asJson()
            ->acceptJson()
            ->timeout(40)
            ->post("https://api.ahasend.com/v2/accounts/{$config['account_id']}/messages", array_filter([
                'from' => $from ?? ['email' => $config['from_email'], 'name' => $config['from_name']],
                'recipients' => $recipients,
                'subject' => $subject,
                'html_content' => $html,
                'text_content' => $text,
                'sandbox' => $config['sandbox'],
            ], fn ($value) => $value !== null))
            ->throw()
            ->json();

        return $response['data'][0]['id'] ?? null;
    }

    /**
     * Parses "Name <email>, other@example.com".
     *
     * @return list<array{email: string, name?: string}>
     */
    public function recipients(string $value): array
    {
        return collect(explode(',', $value))
            ->map(fn (string $entry) => trim($entry))
            ->filter()
            ->map(function (string $entry) {
                if (preg_match('/^(.*)<([^>]+)>$/', $entry, $m)) {
                    return array_filter(['email' => trim($m[2]), 'name' => trim($m[1])]);
                }

                return ['email' => $entry];
            })
            ->values()
            ->all();
    }
}
