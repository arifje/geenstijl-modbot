<?php

namespace App\Services\Reporting;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class SlackNotifier
{
    public function __construct(private SlackFormatter $formatter) {}

    /**
     * Posts the report and returns the timestamp of the main message.
     */
    public function send(Report $report, ?string $channel = null): string
    {
        $channel ??= config('modbot.slack.channel');

        $ts = $this->post($channel, $this->formatter->main($report));

        foreach ($this->formatter->thread($report) as $text) {
            $this->post($channel, $text, $ts);
        }

        return $ts;
    }

    public function notice(string $text, ?string $channel = null): string
    {
        return $this->post($channel ?? config('modbot.slack.channel'), str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text));
    }

    private function post(string $channel, string $text, ?string $threadTs = null): string
    {
        $config = config('modbot.slack');

        $payload = array_filter([
            'channel' => $channel,
            'text' => $text,
            'thread_ts' => $threadTs,
            'mrkdwn' => true,
            'unfurl_links' => false,
            'unfurl_media' => false,
            'link_names' => false,
            'username' => $config['username'],
            'icon_url' => $config['icon_url'],
        ], fn ($value) => $value !== null && $value !== '');

        $response = Http::withToken($config['bot_token'])
            ->asJson()
            ->retry(3, 2000, throw: false)
            ->post('https://slack.com/api/chat.postMessage', $payload)
            ->throw()
            ->json();

        if (! ($response['ok'] ?? false)) {
            throw new RuntimeException('Slack error: '.($response['error'] ?? 'unknown'));
        }

        return $response['ts'];
    }
}
