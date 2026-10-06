<?php

namespace Tests\Feature;

use App\Jobs\RunModerationJob;
use App\Models\ArticleSummary;
use App\Models\Comment;
use App\Models\CommentVerdict;
use App\Models\ModerationRun;
use App\Models\Schedule;
use App\Services\Analysis\LanguageModel;
use App\Services\ModerationPipeline;
use App\Services\ModerationRunner;
use App\Services\RunOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\FakeLanguageModel;
use Tests\TestCase;

class RunModerationTest extends TestCase
{
    use RefreshDatabase;

    private FakeLanguageModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05 10:00:00'); // UTC, 12:00 in Amsterdam

        config([
            'modbot.slack.bot_token' => 'xoxb-test',
            'modbot.slack.channel' => '#development',
            'modbot.email.api_key' => 'aha-test',
            'modbot.email.account_id' => 'acc-1',
            'modbot.email.recipients' => 'Arjan <arjan@example.com>, mods@example.com',
        ]);

        $this->model = new FakeLanguageModel(function (string $input, ?array $schema) {
            if ($schema === null) {
                return 'Felle discussie over Rusland. Een enkele reaguurder bedreigt een ander.';
            }

            return json_encode([
                'summary' => 'Veel humor, één bedreiging.',
                'flags' => [
                    [
                        'comment_id' => 'c-threat',
                        'priority' => 'high',
                        'category' => 'bedreiging',
                        'reason' => 'Concrete bedreiging <@here> richting een andere reaguurder.',
                        'quote' => 'ik weet waar je woont',
                    ],
                    [
                        'comment_id' => 'invented-id',
                        'priority' => 'medium',
                        'category' => 'spam',
                        'reason' => 'Bestaat niet.',
                        'quote' => 'x',
                    ],
                ],
            ]);
        });
        $this->app->instance(LanguageModel::class, $this->model);

        Http::fake([
            'content-api.prod.platform.gstech.nl/articles*' => Http::response([
                'data' => [
                    ['id' => 1, 'slug' => 'rusland', 'title' => 'Pest in Rusland', 'publishedAt' => '2026-10-05T09:00:00', 'commentsCount' => 4],
                    ['id' => 2, 'slug' => 'oud', 'title' => 'Oud artikel', 'publishedAt' => '2026-10-03T09:00:00', 'commentsCount' => 10],
                ],
                'meta' => ['pagination' => ['page' => 1, 'pageCount' => 1]],
            ]),
            'comments-api.prod.platform.gstech.nl/v3/1/comments' => Http::response([
                'closed' => false,
                'data' => [
                    $this->comment('c-old-parent', '2026-10-04T20:00:00', 'Oude reactie waarop gereageerd wordt'),
                    $this->comment('c-threat', '2026-10-05T11:30:00', 'Pas maar op, ik weet waar je woont.', parentId: 'c-old-parent'),
                    $this->comment('c-fine', '2026-10-05T11:00:00', 'Gewoon een grap & een mening'),
                    $this->comment('c-approved', '2026-10-05T11:45:00', 'Al goedgekeurd door een moderator', approved: true),
                    $this->comment('c-removed', '2026-10-05T11:40:00', 'Al verwijderd', status: 'removed'),
                ],
            ]),
            'slack.com/api/chat.postMessage' => Http::sequence()
                ->push(['ok' => true, 'ts' => '111.222'])
                ->push(['ok' => true, 'ts' => '111.333']),
            'api.ahasend.com/*' => Http::response(['object' => 'list', 'data' => [['id' => 'msg-1', 'status' => 'queued']]], 202),
        ]);
    }

    public function test_it_analyses_new_comments_and_reports_to_slack_and_email(): void
    {
        $this->artisan('modbot:run')->assertSuccessful();

        // Only unapproved, visible comments in the window are analysed.
        $this->assertSame(['c-fine', 'c-threat'], Comment::whereNotNull('analyzed_at')->orderBy('id')->pluck('id')->all());

        // The parent is sent as context, not as a comment to judge.
        $input = json_decode($this->model->calls[0]['input'], true);
        $this->assertSame(['c-old-parent'], array_column($input['context'], 'id'));
        $this->assertSame(['c-fine', 'c-threat'], array_column($input['comments'], 'id'));
        // GeenStijl times are Amsterdam local time and are shown as such.
        $this->assertSame('05/10 11:30', $input['comments'][1]['date']);

        // Invented ids are ignored.
        $this->assertSame(['c-threat'], CommentVerdict::pluck('comment_id')->all());

        $run = ModerationRun::sole();
        $this->assertSame('succeeded', $run->status);
        $this->assertSame(2, $run->analyzed_count);
        $this->assertSame('111.222', $run->slack_ts);
        $this->assertSame('msg-1', $run->email_message_id);
        $this->assertStringContainsString('Felle discussie', $run->sentiment);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://slack.com/api/chat.postMessage'
            && ! isset($r['thread_ts'])
            && str_contains($r['text'], '💬 *2* reacties beoordeeld van *1* artikelen')
            && str_contains($r['text'], '🚩 *1* reacties vereisen moderatie'));

        Http::assertSent(fn (Request $r) => $r->url() === 'https://slack.com/api/chat.postMessage'
            && ($r->data()['thread_ts'] ?? null) === '111.222'
            && str_contains($r['text'], '📰 *<https://www.geenstijl.nl/1/rusland|Pest in Rusland>* · 1 reactie')
            && str_contains($r['text'], '🔴 *Hoog · Bedreiging / aanzetten tot geweld · user-c-threat · 05/10 11:30*')
            && str_contains($r['text'], '<https://www.geenstijl.nl/1/rusland#cc-threat|Bewerk reactie>')
            && ! str_contains($r['text'], 'Concrete bedreiging'));

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.ahasend.com/v2/accounts/acc-1/messages')
            && $r->hasHeader('Authorization', 'Bearer aha-test')
            && $r['recipients'] === [['email' => 'arjan@example.com', 'name' => 'Arjan'], ['email' => 'mods@example.com']]
            && str_contains($r['html_content'], 'ik weet waar je woont'));
    }

    public function test_comments_are_only_analysed_once(): void
    {
        $this->artisan('modbot:run --no-slack --no-email')->assertSuccessful();
        $calls = count($this->model->calls);

        $this->artisan('modbot:run --no-slack --no-email')->assertSuccessful();

        // No new comments: no analysis call and no summary call.
        $this->assertCount($calls, $this->model->calls);
        // The verdict from the first run still shows up in the second report.
        $this->assertSame(1, CommentVerdict::count());
    }

    public function test_dry_run_prints_the_report_without_sending(): void
    {
        $this->artisan('modbot:run --dry-run')
            ->expectsOutputToContain('Reacties ter beoordeling')
            ->assertSuccessful();

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'slack.com') || str_contains($r->url(), 'ahasend'));
    }

    public function test_run_is_marked_failed_when_every_analysis_fails(): void
    {
        $this->app->instance(LanguageModel::class, new FakeLanguageModel(fn () => 'not json'));

        $this->artisan('modbot:run')->assertFailed();

        $this->assertSame('failed', ModerationRun::sole()->status);
        $this->assertSame(0, Comment::whereNotNull('analyzed_at')->count());

        // Only a failure notice goes to Slack, no email.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'slack.com') && str_contains($r['text'], 'ModBot-run mislukt'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'ahasend'));
    }

    public function test_a_schedule_run_uses_the_schedule_settings(): void
    {
        $schedule = Schedule::create([
            'name' => 'Test', 'cron' => '0 * * * *', 'window_hours' => 3,
            'send_slack' => true, 'send_email' => false, 'slack_channel' => '#moderatie',
        ]);

        $this->artisan("modbot:run --schedule={$schedule->id}")->assertSuccessful();

        $run = ModerationRun::latest('id')->first();
        $this->assertSame('schedule', $run->trigger);
        $this->assertSame($schedule->id, $run->schedule_id);
        $this->assertSame(3, (int) round($run->window_start->diffInHours($run->window_end)));
        $this->assertNotNull($schedule->fresh()->last_run_at);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'slack.com') && $r['channel'] === '#moderatie');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'ahasend'));
    }

    public function test_a_queued_run_is_picked_up_by_the_job(): void
    {
        Carbon::setTestNow('2026-10-05 09:55:00');
        $run = ModerationRun::queue(new RunOptions(windowHours: 12, sendEmail: false, trigger: 'manual'));
        Carbon::setTestNow('2026-10-05 10:00:00');

        (new RunModerationJob([...(new RunOptions(windowHours: 12, sendEmail: false, trigger: 'manual'))->toArray(), 'runId' => $run->id]))
            ->handle(app(ModerationRunner::class));

        // Same record, now finished, with the window starting when the job ran.
        $this->assertSame(1, ModerationRun::count());
        $run->refresh();
        $this->assertSame('succeeded', $run->status);
        $this->assertSame('2026-10-05 10:00:00', $run->window_end->toDateTimeString());
        $this->assertSame(2, $run->analyzed_count);
        $this->assertSame('111.222', $run->slack_ts);
    }

    public function test_a_failed_job_does_not_leave_the_run_queued(): void
    {
        $run = ModerationRun::queue(new RunOptions(windowHours: 12, trigger: 'manual'));

        (new RunModerationJob(['runId' => $run->id]))->failed(new \RuntimeException('Worker timed out'));

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('Worker timed out', $run->fresh()->error);
    }

    public function test_a_flag_drops_out_of_the_report_once_a_moderator_approved_the_comment(): void
    {
        $this->artisan('modbot:run --no-slack --no-email')->assertSuccessful();
        $this->assertSame(1, CommentVerdict::count());

        Comment::whereKey('c-threat')->update(['approved' => true]);

        $report = app(ModerationPipeline::class)->report(ModerationRun::latest('id')->first());
        $this->assertCount(0, $report->items);
    }

    public function test_the_approval_filter_can_select_approved_comments(): void
    {
        config(['modbot.approval_filter' => 'approved']);

        $this->artisan('modbot:run --no-slack --no-email')->assertSuccessful();

        $this->assertSame(['c-approved'], Comment::whereNotNull('analyzed_at')->pluck('id')->all());
    }

    public function test_the_report_describes_the_window_including_earlier_analysis_only_runs(): void
    {
        // An analysis-only run (like the 15-minute schedule): no summary call, nothing sent.
        $this->artisan('modbot:run --no-slack --no-email')->assertSuccessful();
        $this->assertCount(1, $this->model->calls);
        $this->assertNull(ModerationRun::sole()->sentiment);
        $this->assertSame(1, ArticleSummary::count());

        // The report run finds nothing new, but still summarises the window.
        $this->artisan('modbot:run --no-email')->assertSuccessful();

        $report = ModerationRun::latest('id')->first();
        $this->assertSame(0, $report->analyzed_count);
        $this->assertStringContainsString('Felle discussie', $report->sentiment);
        $this->assertStringContainsString('Veel humor, één bedreiging.', end($this->model->calls)['input']);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'slack.com')
            && ! isset($r['thread_ts'])
            && str_contains($r['text'], '🚩 *1* reacties vereisen moderatie'));
    }

    private function comment(string $id, string $createdAt, string $content, ?string $parentId = null, bool $approved = false, string $status = 'visible'): array
    {
        return [
            'id' => $id,
            'author' => ['id' => '1', 'username' => "user-{$id}", 'premium' => true, 'newbie' => false],
            'content' => $content,
            'createdAt' => $createdAt,
            'kudos' => 0,
            'reports' => 0,
            'status' => $status,
            'approved' => $approved,
            'parentId' => $parentId,
        ];
    }
}
