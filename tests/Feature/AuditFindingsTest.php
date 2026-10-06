<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\CommentVerdict;
use App\Models\ModerationRun;
use App\Services\Analysis\LanguageModel;
use App\Services\ModerationPipeline;
use App\Services\ModerationRunner;
use App\Services\RunOptions;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Fakes\FakeLanguageModel;
use Tests\TestCase;

/**
 * Regression tests for the findings of the audit of v1.6.3.
 */
class AuditFindingsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, list<array<string, mixed>>|int|string> article id => comments, an HTTP status, or "no-data" */
    private array $comments = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05 10:00:00');
        config(['modbot.slack.bot_token' => 'xoxb-test']);

        $this->comments = [1 => [
            $this->comment('c-threat', 'Pas maar op, ik weet waar je woont.'),
            $this->comment('c-fine', 'Gewoon een mening'),
        ]];

        Http::fake(function (HttpRequest $request) {
            if (str_contains($request->url(), '/articles')) {
                return Http::response(['data' => collect(array_keys($this->comments))->map(fn (int $id) => [
                    'id' => $id, 'slug' => "artikel-{$id}", 'title' => "Artikel {$id}",
                    'publishedAt' => '2026-10-05T09:00:00', 'commentsCount' => 2,
                ])->all(), 'meta' => ['pagination' => ['pageCount' => 1]]]);
            }

            if (preg_match('#/v3/(\d+)/comments#', $request->url(), $m)) {
                $comments = $this->comments[(int) $m[1]];

                return match (true) {
                    is_int($comments) => Http::response('error', $comments),
                    $comments === 'no-data' => Http::response(['closed' => false]),
                    default => Http::response(['closed' => false, 'data' => $comments]),
                };
            }

            return Http::response(['ok' => true, 'ts' => '1.1']);
        });

        $this->respondWith(fn () => ['summary' => 'Rustig.', 'flags' => [$this->flag('c-threat')]]);
    }

    // 1. A failed re-analysis must not erase existing flags.
    public function test_a_failed_reanalysis_keeps_the_existing_flags(): void
    {
        $this->artisan('modbot:run --no-slack --no-email')->assertSuccessful();
        $this->assertSame(['c-threat'], CommentVerdict::pluck('comment_id')->all());

        $this->respondWith(fn () => throw new \RuntimeException('API down'));
        $this->artisan('modbot:run --no-slack --no-email --reanalyze')->assertFailed();

        $this->assertSame(['c-threat'], CommentVerdict::pluck('comment_id')->all());
    }

    public function test_a_successful_reanalysis_replaces_the_flags(): void
    {
        $this->artisan('modbot:run --no-slack --no-email')->assertSuccessful();

        $this->respondWith(fn () => ['summary' => 'Rustig.', 'flags' => [$this->flag('c-fine')]]);
        $this->artisan('modbot:run --no-slack --no-email --reanalyze')->assertSuccessful();

        $this->assertSame(['c-fine'], CommentVerdict::pluck('comment_id')->all());
    }

    // 2. Comments that could not be fetched must not produce a "succeeded" run.
    public function test_a_run_fails_when_no_comments_could_be_fetched(): void
    {
        $this->comments[1] = 500;

        $this->artisan('modbot:run --no-email')->assertFailed();

        $this->assertSame('failed', ModerationRun::sole()->status);
        $this->assertStringContainsString('could not be fetched', ModerationRun::sole()->error);
    }

    public function test_a_run_is_partial_when_some_comments_could_not_be_fetched(): void
    {
        $this->comments[2] = 500;

        // The report is still sent, but the command signals that the run is incomplete.
        $this->artisan('modbot:run --no-email')->assertFailed();

        $run = ModerationRun::sole();
        $this->assertSame('partial', $run->status);
        $this->assertSame(1, $run->sync_failed_count);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'slack.com')
            && str_contains($r['text'], 'Reacties van *1* artikelen konden niet worden opgehaald'));
    }

    // 3. The summaries of the current run are part of the report.
    public function test_the_sentiment_includes_summaries_created_during_the_run(): void
    {
        $this->respondWith(function (?array $schema) {
            Carbon::setTestNow(now()->addSeconds(5)); // the model takes a while

            return $schema === null ? 'Rustige discussie.' : ['summary' => 'Rustig.', 'flags' => []];
        });

        $this->artisan('modbot:run --no-email')->assertSuccessful();

        $this->assertSame('Rustige discussie.', ModerationRun::sole()->sentiment);
    }

    // 4. An unusable model response must not mark comments as reviewed.
    public function test_an_invalid_model_response_does_not_mark_comments_analysed(): void
    {
        $this->respondWith(fn () => []);

        $this->artisan('modbot:run --no-slack --no-email')->assertFailed();

        $this->assertSame(0, Comment::whereNotNull('analyzed_at')->count());
    }

    // 5. Cancelling while the run waits for the lock stops it.
    public function test_a_run_cancelled_while_waiting_for_the_lock_does_not_execute(): void
    {
        $options = new RunOptions(windowHours: 12, trigger: 'manual');
        $run = ModerationRun::queue($options);

        $lock = \Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->andReturnUsing(function () use ($run) {
            $run->update(['status' => 'cancelled']); // cancelled by a moderator during the wait

            return true;
        });
        $lock->shouldReceive('release');
        Cache::partialMock()->shouldReceive('lock')->andReturn($lock);

        app(ModerationRunner::class)->run(new RunOptions(...[...$options->toArray(), 'runId' => $run->id]));

        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertSame(0, Comment::count());
        Http::assertNothingSent();
    }

    // 6. Comments deleted at GeenStijl drop out of the report.
    public function test_comments_that_disappeared_are_no_longer_reported(): void
    {
        $this->artisan('modbot:run --no-slack --no-email')->assertSuccessful();

        $this->comments[1] = [$this->comment('c-fine', 'Gewoon een mening')];
        $this->artisan('modbot:run --no-slack --no-email')->assertSuccessful();

        $this->assertSame('gone', Comment::find('c-threat')->status);
        $this->assertCount(0, app(ModerationPipeline::class)->report(ModerationRun::latest('id')->first())->items);
    }

    public function test_an_incomplete_response_does_not_mark_comments_gone(): void
    {
        $this->artisan('modbot:run --no-slack --no-email')->assertSuccessful();

        $this->comments[1] = 'no-data';
        $this->artisan('modbot:run --no-slack --no-email')->assertFailed();

        $this->assertSame('visible', Comment::find('c-threat')->status);
    }

    // 7. Only the proxy in front of the app may set the client IP.
    public function test_the_client_ip_cannot_be_spoofed_by_direct_clients(): void
    {
        Route::get('/_ip', fn (Request $request) => $request->ip());

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
            ->get('/_ip', ['X-Forwarded-For' => '1.2.3.4'])
            ->assertSee('203.0.113.5');

        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.1'])
            ->get('/_ip', ['X-Forwarded-For' => '1.2.3.4'])
            ->assertSee('1.2.3.4');
    }

    /**
     * @param  \Closure(?array $schema): (array|string)  $respond
     */
    private function respondWith(\Closure $respond): void
    {
        $this->app->instance(LanguageModel::class, new FakeLanguageModel(function (string $input, ?array $schema) use ($respond) {
            $result = $respond($schema);

            if ($schema === null) {
                return is_string($result) ? $result : 'Rustige discussie.';
            }

            return json_encode($result);
        }));
    }

    private function flag(string $id): array
    {
        return ['comment_id' => $id, 'priority' => 'high', 'category' => 'bedreiging', 'reason' => 'Test', 'quote' => 'x'];
    }

    private function comment(string $id, string $content): array
    {
        return [
            'id' => $id, 'author' => ['id' => '1', 'username' => "user-{$id}"], 'content' => $content,
            'createdAt' => '2026-10-05T11:30:00', 'status' => 'visible', 'approved' => false, 'parentId' => null,
        ];
    }
}
