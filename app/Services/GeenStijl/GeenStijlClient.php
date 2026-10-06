<?php

namespace App\Services\GeenStijl;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the public GeenStijl content and comments APIs.
 */
class GeenStijlClient
{
    /**
     * Fetch articles, newest first, until they are older than $since.
     *
     * @return list<array<string, mixed>>
     */
    public function articlesSince(Carbon $since): array
    {
        $config = config('modbot.geenstijl');
        $articles = [];

        for ($page = 1; $page <= $config['max_pages']; $page++) {
            $response = $this->http($config['content_api_url'])
                ->get('/articles', ['limit' => $config['page_size'], 'page' => $page])
                ->throw()
                ->json();

            $batch = $response['data'] ?? [];
            $articles = [...$articles, ...$batch];

            $oldest = collect($batch)->min(fn (array $a) => $this->parseDate($a['publishedAt']));
            $pageCount = $response['meta']['pagination']['pageCount'] ?? $page;

            if ($batch === [] || $oldest->lt($since) || $page >= $pageCount) {
                break;
            }
        }

        return array_values(array_filter(
            $articles,
            fn (array $a) => $this->parseDate($a['publishedAt'])->gte($since),
        ));
    }

    /**
     * All comments for an article (the API does not paginate).
     *
     * @return array{closed: bool, data: list<array<string, mixed>>}
     */
    public function comments(int $articleId): array
    {
        $response = $this->http(config('modbot.geenstijl.comments_api_url'))
            ->get("/v3/{$articleId}/comments")
            ->throw()
            ->json();

        // An incomplete response would make existing comments look deleted.
        if (! is_array($response) || ! array_key_exists('data', $response) || ! is_array($response['data']) || ! array_is_list($response['data'])) {
            throw new \UnexpectedValueException("Unexpected comments response for article {$articleId}");
        }

        return [
            'closed' => (bool) ($response['closed'] ?? false),
            'data' => $response['data'],
        ];
    }

    /**
     * GeenStijl timestamps are Amsterdam local time without an offset.
     */
    public function parseDate(string $value): Carbon
    {
        return Carbon::parse($value, config('modbot.geenstijl.timezone'))->utc();
    }

    private function http(string $baseUrl): PendingRequest
    {
        return Http::baseUrl($baseUrl)
            ->acceptJson()
            ->timeout(config('modbot.geenstijl.timeout'))
            ->retry(3, 1000, throw: false)
            ->withUserAgent('geenstijl-modbot');
    }
}
