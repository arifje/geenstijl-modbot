<?php

namespace Tests\Feature;

use App\Filament\Pages\SystemInfo;
use App\Models\User;
use App\Services\System\SystemReport;
use App\Support\VersionConstraint;
use Composer\InstalledVersions;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class SystemInfoTest extends TestCase
{
    use RefreshDatabase;

    private string $laravel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->laravel = app()->version();
        [$major] = explode('.', $this->laravel);
        $phpCycle = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
        $filament = VersionConstraint::normalize(InstalledVersions::getPrettyVersion('filament/filament'));

        Http::fake([
            'endoflife.date/api/php.json' => Http::response([['cycle' => $phpCycle, 'eol' => now()->addYears(3)->toDateString(), 'latest' => PHP_VERSION]]),
            'endoflife.date/api/laravel.json' => Http::response([
                ['cycle' => (string) ($major + 1), 'eol' => now()->addYears(2)->toDateString(), 'latest' => ($major + 1).'.0.0'],
                ['cycle' => $major, 'eol' => now()->addDays(60)->toDateString(), 'latest' => $major.'.999.0'],
            ]),
            'repo.packagist.org/p2/filament/filament.json' => Http::response(['packages' => ['filament/filament' => [['version' => 'v'.$filament]]]]),
            'repo.packagist.org/p2/*' => Http::response(['packages' => []]),
            'packagist.org/api/security-advisories/*' => Http::response(['advisories' => [
                'livewire/livewire' => [['title' => 'XSS in uploads', 'cve' => 'CVE-2026-0001', 'affectedVersions' => '<999.0.0']],
                'filament/filament' => [['title' => 'Old issue', 'cve' => null, 'affectedVersions' => '<1.0.0']],
            ]]),
            '*' => Http::response([], 404),
        ]);
    }

    public function test_the_report_assesses_each_component(): void
    {
        $report = app(SystemReport::class)->refresh();
        $byName = collect($report['components'])->keyBy('name');

        $this->assertSame('ok', $byName['PHP']['status']);
        // Laravel's support ends in 60 days: that wins over the newer patch.
        $this->assertSame('eol_soon', $byName['Laravel']['status']);
        $this->assertSame('ok', $byName['Filament (admin)']['status']);
        // An advisory that matches the installed version.
        $this->assertSame('security', $byName['Livewire']['status']);
        $this->assertStringContainsString('CVE-2026-0001', $byName['Livewire']['note']);
        $this->assertSame('danger', $report['severity']);
        $this->assertSame(2, $report['attention']);

        $summary = SystemReport::summary($report);
        $this->assertStringContainsString('Livewire', $summary);
        $this->assertStringContainsString('Security issue', $summary);
    }

    public function test_the_page_shows_the_report_and_the_menu_badge(): void
    {
        config(['modbot.maintainer' => ['name' => 'Arjan', 'email' => 'onderhoud@example.com', 'url' => null]]);
        $this->actingAs(User::factory()->create());

        $this->get('/admin/about')
            ->assertOk()
            ->assertSee('About &amp; updates', false)
            ->assertSee('Security issue')
            ->assertSee('onderhoud@example.com')
            ->assertSee('mailto:onderhoud@example.com?subject=', false);

        $this->assertSame('2', SystemInfo::getNavigationBadge());
        $this->assertSame('danger', SystemInfo::getNavigationBadgeColor());

        Livewire::test(SystemInfo::class)->callAction('checkNow')->assertNotified('Checked for updates');
    }

    public function test_pixio_is_the_default_maintainer(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/about')
            ->assertOk()
            ->assertSee('pixio')
            ->assertSee('mailto:info@pixio.media?subject=', false);
    }

    public function test_the_daily_check_is_scheduled_and_logs_the_result(): void
    {
        $this->artisan('modbot:check-updates')
            ->expectsOutputToContain('need attention')
            ->assertSuccessful();

        $this->assertNotNull(app(SystemReport::class)->cached());
        $this->assertStringContainsString('modbot:check-updates', collect(app(Schedule::class)->events())->pluck('command')->implode(' '));
    }

    public function test_unreachable_sources_do_not_break_the_report(): void
    {
        Http::fake(fn () => throw new ConnectionException('offline'));

        $report = app(SystemReport::class)->refresh();

        $this->assertSame('unknown', collect($report['components'])->firstWhere('name', 'PHP')['status']);
        $this->assertSame(0, $report['attention']);
    }

    public function test_version_constraints_from_advisories(): void
    {
        $this->assertTrue(VersionConstraint::satisfies('13.20.1', '>=13.0.0,<13.30.0|<12.69.0'));
        $this->assertTrue(VersionConstraint::satisfies('12.5.0', '>=13.0.0,<13.30.0|<12.69.0'));
        $this->assertFalse(VersionConstraint::satisfies('v13.34.0', '>=13.0.0,<13.30.0|<12.69.0'));
        $this->assertFalse(VersionConstraint::satisfies('dev-main', '<99'));
        $this->assertFalse(VersionConstraint::satisfies('1.0.0', '^1.0'));   // unsupported syntax never matches
    }
}
