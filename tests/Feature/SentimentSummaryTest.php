<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleSummary;
use App\Models\ModerationRun;
use App\Models\Prompt;
use App\Services\Analysis\LanguageModel;
use App\Services\Analysis\SentimentSummarizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeLanguageModel;
use Tests\TestCase;

class SentimentSummaryTest extends TestCase
{
    use RefreshDatabase;

    private const PER_ARTICLE = "De reacties zijn luchtig en sarcastisch over het programma. Enkele bevatten negatieve generalisaties over LGBT-personen.\n\n"
        ."De reacties zijn politiek-polemisch en sarcastisch over de pridevlag, met generalisaties over groepen.\n\n"
        .'De reacties zijn sarcastisch en politiek getint, met discussie over doodstraf en publieke executies en stereotiepe generalisaties over moslims.';

    private ModerationRun $run;

    protected function setUp(): void
    {
        parent::setUp();

        $this->run = ModerationRun::create(['status' => 'running', 'started_at' => now(), 'window_start' => now()->subHours(12), 'window_end' => now()]);
        Article::create(['id' => 1, 'slug' => 'a', 'title' => 'Artikel', 'published_at' => now()->subHour(), 'comments_count' => 3]);
        ArticleSummary::create(['article_id' => 1, 'moderation_run_id' => $this->run->id, 'summary' => 'Rustig.', 'comments_count' => 3, 'created_at' => now()]);
    }

    public function test_a_per_article_answer_is_retried_once(): void
    {
        $model = $this->answers([self::PER_ARTICLE, 'Overwegend sarcastische, politieke discussies. Opvallend zijn generalisaties over groepen en enkele gewelddadige uitspraken.']);

        $text = app(SentimentSummarizer::class)->summarize($this->run, []);

        $this->assertSame('Overwegend sarcastische, politieke discussies. Opvallend zijn generalisaties over groepen en enkele gewelddadige uitspraken.', $text);
        $this->assertCount(2, $model->calls);
        $this->assertStringContainsString('maximaal 30 woorden', $model->calls[1]['input']);
    }

    public function test_a_second_long_answer_is_shortened_to_whole_sentences(): void
    {
        $this->answers([self::PER_ARTICLE, self::PER_ARTICLE]);

        $text = app(SentimentSummarizer::class)->summarize($this->run, []);

        $this->assertLessThanOrEqual(SentimentSummarizer::MAX_WORDS, SentimentSummarizer::words($text));
        // Whole sentences only, as many as fit in 30 words.
        $this->assertStringStartsWith('De reacties zijn luchtig en sarcastisch over het programma. Enkele bevatten', $text);
        $this->assertStringEndsWith('.', $text);
        $this->assertStringNotContainsString("\n", $text);
    }

    public function test_a_single_overlong_sentence_is_cut_off(): void
    {
        $this->assertSame(
            implode(' ', array_fill(0, 30, 'woord')).'…',
            SentimentSummarizer::shorten(implode(' ', array_fill(0, 40, 'woord'))),
        );
    }

    public function test_a_short_answer_is_kept_on_one_line(): void
    {
        $model = $this->answers(["\"Rustige discussie.\nWeinig opvallends.\""]);

        $this->assertSame('Rustige discussie. Weinig opvallends.', app(SentimentSummarizer::class)->summarize($this->run, []));
        $this->assertCount(1, $model->calls);
    }

    public function test_the_unchanged_first_prompt_is_updated_but_an_edited_one_is_not(): void
    {
        $migration = require database_path('migrations/2026_10_10_000001_publish_shorter_sentiment_prompt.php');
        $prompt = Prompt::where('key', 'summary')->first();

        // An edited prompt stays as it is.
        $prompt->publish('Mijn eigen prompt.');
        $migration->up();
        $this->assertSame('Mijn eigen prompt.', $prompt->fresh()->activeVersion->content);

        // The first default (by its version hash) is replaced by the new default.
        $first = $prompt->versions()->create(['content' => 'eerste versie', 'version' => 'a78c0dc062c5']);
        $prompt->update(['active_version_id' => $first->id]);
        $migration->up();
        $this->assertSame(trim(file_get_contents(resource_path('prompts/summary.nl.md'))), $prompt->fresh()->activeVersion->content);
    }

    /**
     * @param  list<string>  $answers
     */
    private function answers(array $answers): FakeLanguageModel
    {
        $model = new FakeLanguageModel(function () use (&$answers) {
            return array_shift($answers);
        });
        $this->app->instance(LanguageModel::class, $model);

        return $model;
    }
}
