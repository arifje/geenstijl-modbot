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
        $config = config('modbot.email');
        $recipients = $this->recipients($config['recipients']);

        if ($recipients === []) {
            throw new RuntimeException('No email recipients configured (AHASEND_RECIPIENTS).');
        }

        $response = Http::withToken($config['api_key'])
            ->asJson()
            ->acceptJson()
            ->timeout(40)
            ->post("https://api.ahasend.com/v2/accounts/{$config['account_id']}/messages", [
                'from' => ['email' => $config['from_email'], 'name' => $config['from_name']],
                'recipients' => $recipients,
                'subject' => 'GeenStijl '.Str::lcfirst($report->title()),
                'html_content' => view('emails.report', ['report' => $report])->render(),
                'text_content' => view('emails.report-text', ['report' => $report])->render(),
                'sandbox' => $config['sandbox'],
            ])
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
