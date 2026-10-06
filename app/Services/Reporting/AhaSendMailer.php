<?php

namespace App\Services\Reporting;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class AhaSendMailer
{
    /**
     * Sends the report and returns the AhaSend message id.
     */
    public function send(Report $report): ?string
    {
        return $this->post(
            config('modbot.email.recipients'),
            'GeenStijl '.Str::lcfirst($report->title()),
            view('emails.report', ['report' => $report])->render(),
            view('emails.report-text', ['report' => $report])->render(),
        );
    }

    /**
     * Sends a short message to check the AhaSend connection.
     */
    public function sendTest(string $recipients): ?string
    {
        return $this->post(
            $recipients,
            'GeenStijl ModBot testmail',
            view('emails.test')->render(),
            'Dit is een testmail van GeenStijl ModBot. De e-mailkoppeling (AhaSend) werkt.',
        );
    }

    private function post(string $recipients, string $subject, string $html, string $text): ?string
    {
        return $this->deliver($this->recipients($recipients), $subject, $html, $text);
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
