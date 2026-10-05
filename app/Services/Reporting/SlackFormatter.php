<?php

namespace App\Services\Reporting;

use App\Models\Article;
use App\Models\CommentVerdict;

/**
 * Renders a report as Slack mrkdwn: a main message plus thread replies.
 */
class SlackFormatter
{
    // Slack truncates long messages; stay well below the limit per message.
    private const MAX_MESSAGE_LENGTH = 3500;

    public function main(Report $report): string
    {
        $lines = [
            "*🛡️ {$this->escape($report->title())}*",
            '',
            "💬 *{$report->commentsCount}* reacties beoordeeld van *{$report->articlesCount}* artikelen",
            "🚩 *{$report->items->count()}* reacties vereisen moderatie",
        ];

        if ($report->failedCount > 0) {
            $lines[] = "⚠️ *{$report->failedCount}* reacties konden niet worden geanalyseerd";
        }

        $lines[] = '';
        $lines[] = '*💬 Sfeer en sentiment*';
        $lines[] = $report->commentsCount === 0
            ? 'Geen recente reacties beschikbaar voor beoordeling.'
            : $this->escape($report->sentiment ?? 'Geen samenvatting beschikbaar.');

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    public function thread(Report $report): array
    {
        if ($report->items->isEmpty()) {
            return [];
        }

        $messages = [];
        $current = '*🚩 Reacties ter beoordeling*';

        $append = function (string $block, ?string $continuation = null) use (&$messages, &$current) {
            if (mb_strlen($current) + mb_strlen($block) + 2 > self::MAX_MESSAGE_LENGTH) {
                $messages[] = $current;
                // Repeat the article header when a group continues in the next message.
                $current = $continuation !== null ? $continuation."\n\n".$block : $block;
            } else {
                $current .= "\n\n".$block;
            }
        };

        foreach ($report->groups() as $group) {
            $header = $this->articleHeader($group['article'], $group['items']->count());
            $append($header);

            foreach ($group['items'] as $verdict) {
                $append($this->item($verdict), $header.' _(vervolg)_');
            }
        }

        if ($report->hiddenCount() > 0) {
            $append("Nog {$report->hiddenCount()} reacties vereisen beoordeling.");
        }

        $messages[] = $current;

        return $messages;
    }

    private function articleHeader(Article $article, int $count): string
    {
        $title = str_replace('|', '-', $this->escape($article->title));
        $label = $count === 1 ? 'reactie' : 'reacties';

        return "📰 *<{$article->url()}|{$title}>* · {$count} {$label}";
    }

    private function item(CommentVerdict $verdict): string
    {
        $comment = $verdict->comment;
        $date = $comment->posted_at->timezone(config('modbot.geenstijl.timezone'))->format('d/m H:i');

        $header = implode(' · ', [
            $verdict->priority->label(),
            $verdict->category->label(),
            $this->escape($comment->displayName()),
            $date,
        ]);

        return implode("\n", [
            "{$verdict->priority->emoji()} *{$header}*",
            "“{$this->escape($verdict->quote)}” - <{$comment->url()}|Bewerk reactie>",
        ]);
    }

    /**
     * Escape user content so it cannot create links, mentions or broadcasts.
     */
    private function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
