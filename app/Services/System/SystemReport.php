<?php

namespace App\Services\System;

use App\Support\VersionConstraint;
use Composer\InstalledVersions;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * What runs in this installation and whether it is up to date: versions,
 * end of life (endoflife.date), newer releases (Packagist, GitHub) and known
 * security issues (Packagist advisories). Built daily by modbot:check-updates
 * and cached, so the admin never waits for it.
 */
class SystemReport
{
    public const CACHE_KEY = 'modbot:system-report';

    /** Statuses, most urgent first. */
    public const STATUSES = [
        'security' => ['Security issue', 'danger'],
        'eol' => ['End of life', 'danger'],
        'eol_soon' => ['Support ends soon', 'warning'],
        'update' => ['Update available', 'warning'],
        'major' => ['New major version', 'info'],
        'ok' => ['Up to date', 'success'],
        'unknown' => ['Could not check', 'gray'],
    ];

    /** Statuses that count as "needs attention". */
    public const ATTENTION = ['security', 'eol', 'eol_soon', 'update'];

    /** Support ending within this many days counts as "ends soon". */
    private const EOL_SOON_DAYS = 180;

    /** Packages shown with their version; all others are only checked for advisories. */
    private const MAIN_PACKAGES = [
        'filament/filament' => 'Filament (admin)',
        'livewire/livewire' => 'Livewire',
        'anthropic-ai/sdk' => 'Anthropic SDK',
    ];

    /**
     * The cached report, or null when it has not been built yet.
     *
     * @return array<string, mixed>|null
     */
    public function cached(): ?array
    {
        return Cache::get(self::CACHE_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    public function refresh(): array
    {
        $report = $this->build();
        Cache::forever(self::CACHE_KEY, $report);

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $components = [
            $this->modbot(),
            $this->lifecycle('PHP', PHP_VERSION, 'php', fn (string $v) => implode('.', array_slice(explode('.', $v), 0, 2))),
            ['package' => 'laravel/framework', ...$this->lifecycle('Laravel', app()->version(), 'laravel', fn (string $v) => explode('.', $v)[0])],
            $this->database(),
            $this->frankenphp(),
            $this->alpine(),
            ...$this->mainPackages(),
        ];

        $advisories = $this->advisories();

        // Security issues in packages that are not listed above get their own row.
        foreach ($advisories as $package => $titles) {
            $row = collect($components)->search(fn (array $c) => ($c['package'] ?? null) === $package);

            if ($row !== false) {
                $components[$row]['status'] = 'security';
                $components[$row]['note'] = implode('; ', $titles);
            } else {
                $components[] = [
                    'name' => $package, 'package' => $package, 'group' => 'PHP package',
                    'installed' => $this->installedVersion($package), 'latest' => $this->latestStable($package),
                    'eol' => null, 'status' => 'security', 'note' => implode('; ', $titles),
                ];
            }
        }

        $attention = collect($components)->whereIn('status', self::ATTENTION);

        return [
            'checked_at' => now()->toIso8601String(),
            'components' => array_values($components),
            'packages_checked' => count($this->installedPackages()),
            'attention' => $attention->count(),
            'severity' => $attention->contains(fn (array $c) => in_array($c['status'], ['security', 'eol'], true)) ? 'danger' : ($attention->isEmpty() ? null : 'warning'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function modbot(): array
    {
        return [
            'name' => 'ModBot', 'group' => 'Application', 'installed' => config('modbot.version'),
            'latest' => null, 'eol' => null, 'status' => 'ok',
            'note' => 'Updates of ModBot itself come from your maintainer.',
        ];
    }

    /**
     * A runtime with a release cycle on endoflife.date (PHP, Laravel, PostgreSQL, Alpine).
     *
     * @param  \Closure(string): string  $cycleOf  version → cycle ("8.5.11" → "8.5")
     * @return array<string, mixed>
     */
    private function lifecycle(string $name, ?string $installed, string $product, \Closure $cycleOf, string $group = 'Runtime'): array
    {
        $row = ['name' => $name, 'group' => $group, 'installed' => $installed, 'latest' => null, 'eol' => null, 'status' => 'unknown', 'note' => null];

        if (blank($installed)) {
            $row['note'] = 'Version not detected here.';

            return $row;
        }

        $cycles = $this->fetch("eol:{$product}", fn () => Http::timeout(10)->acceptJson()->get("https://endoflife.date/api/{$product}.json")->throw()->json());

        if (! is_array($cycles)) {
            $row['note'] = 'endoflife.date not reachable.';

            return $row;
        }

        $cycle = collect($cycles)->firstWhere('cycle', $cycleOf($installed));
        $newest = $cycles[0] ?? null;

        if ($cycle === null) {
            $row['note'] = "Release cycle {$cycleOf($installed)} not listed.";

            return $row;
        }

        $row['latest'] = $cycle['latest'] ?? null;
        $row['eol'] = is_string($cycle['eol'] ?? null) ? $cycle['eol'] : null;

        $eol = $row['eol'] ? Carbon::parse($row['eol']) : null;
        $row['status'] = match (true) {
            $eol !== null && $eol->isPast() => 'eol',
            $eol !== null && $eol->diffInDays(now(), true) <= self::EOL_SOON_DAYS => 'eol_soon',
            $row['latest'] && version_compare($installed, $row['latest'], '<') => 'update',
            $newest && $newest['cycle'] !== $cycle['cycle'] => 'major',
            default => 'ok',
        };

        if ($row['status'] === 'major') {
            $row['note'] = "Version {$newest['cycle']} is available.";
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function database(): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            return ['name' => 'Database', 'group' => 'Runtime', 'installed' => DB::getDriverName(), 'latest' => null, 'eol' => null, 'status' => 'unknown', 'note' => 'Only PostgreSQL is checked.'];
        }

        $version = rescue(fn () => explode(' ', DB::selectOne('show server_version')->server_version)[0], null, false);

        return $this->lifecycle('PostgreSQL', $version, 'postgresql', fn (string $v) => explode('.', $v)[0]);
    }

    /**
     * The web server in the Docker image; not present when running locally.
     *
     * @return array<string, mixed>
     */
    private function frankenphp(): array
    {
        $output = rescue(fn () => Process::timeout(5)->run(['frankenphp', 'version'])->output(), '', false);
        $installed = preg_match('/FrankenPHP v?(\d+\.\d+\.\d+)/', $output, $m) ? $m[1] : null;
        $row = ['name' => 'FrankenPHP (web server)', 'group' => 'Runtime', 'installed' => $installed, 'latest' => null, 'eol' => null, 'status' => 'unknown', 'note' => null];

        if ($installed === null) {
            $row['note'] = 'Not running in the Docker image.';

            return $row;
        }

        $latest = $this->fetch('github:frankenphp', fn () => ltrim((string) Http::timeout(10)->acceptJson()
            ->get('https://api.github.com/repos/php/frankenphp/releases/latest')->throw()->json('tag_name'), 'v'));

        $row['latest'] = $latest ?: null;
        $row['status'] = $latest ? (version_compare($installed, $latest, '<') ? 'update' : 'ok') : 'unknown';

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function alpine(): array
    {
        $version = rescue(fn () => trim((string) @file_get_contents('/etc/alpine-release')) ?: null, null, false);

        if ($version === null) {
            return ['name' => 'Alpine Linux (image)', 'group' => 'Runtime', 'installed' => null, 'latest' => null, 'eol' => null, 'status' => 'unknown', 'note' => 'Not running in the Docker image.'];
        }

        return $this->lifecycle('Alpine Linux (image)', $version, 'alpine-linux', fn (string $v) => implode('.', array_slice(explode('.', $v), 0, 2)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mainPackages(): array
    {
        return collect(self::MAIN_PACKAGES)
            ->filter(fn (string $label, string $package) => InstalledVersions::isInstalled($package))
            ->map(function (string $label, string $package) {
                $installed = $this->installedVersion($package);
                $latest = $this->latestStable($package);

                $status = match (true) {
                    $installed === null || $latest === null => 'unknown',
                    version_compare($installed, $latest, '>=') => 'ok',
                    explode('.', $installed)[0] === explode('.', $latest)[0] => 'update',
                    default => 'major',
                };

                return [
                    'name' => $label, 'package' => $package, 'group' => 'PHP package',
                    'installed' => $installed, 'latest' => $latest, 'eol' => null, 'status' => $status,
                    'note' => $status === 'major' ? "Version {$latest} is a new major version." : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Known security issues in installed packages: package => advisory titles.
     *
     * @return array<string, list<string>>
     */
    private function advisories(): array
    {
        $installed = $this->installedPackages();

        $advisories = $this->fetch('packagist:advisories:'.md5(json_encode($installed)), fn () => Http::timeout(20)->acceptJson()->asForm()
            ->post('https://packagist.org/api/security-advisories/', ['packages' => array_keys($installed)])
            ->throw()->json('advisories', []));

        return collect(is_array($advisories) ? $advisories : [])
            ->map(fn ($list, string $package) => collect($list)
                ->filter(fn (array $a) => VersionConstraint::satisfies($installed[$package] ?? '', (string) ($a['affectedVersions'] ?? '')))
                ->map(fn (array $a) => trim((filled($a['cve'] ?? null) ? "{$a['cve']}: " : '').($a['title'] ?? 'Security advisory')))
                ->values()->all())
            ->filter()
            ->all();
    }

    /**
     * Installed packages with a release version: name => version.
     *
     * @return array<string, string>
     */
    private function installedPackages(): array
    {
        return collect(InstalledVersions::getInstalledPackages())
            ->mapWithKeys(fn (string $package) => [$package => $this->installedVersion($package)])
            ->filter()
            ->all();
    }

    private function installedVersion(string $package): ?string
    {
        $version = rescue(fn () => InstalledVersions::getPrettyVersion($package), null, false);

        return $version ? VersionConstraint::normalize($version) : null;
    }

    private function latestStable(string $package): ?string
    {
        $versions = $this->fetch("packagist:{$package}", fn () => Http::timeout(10)->acceptJson()
            ->get("https://repo.packagist.org/p2/{$package}.json")->throw()->json("packages.{$package}", []));

        $latest = collect(is_array($versions) ? $versions : [])
            ->pluck('version')
            ->first(fn ($v) => is_string($v) && VersionConstraint::isStable($v));

        return $latest ? VersionConstraint::normalize($latest) : null;
    }

    /**
     * One external lookup; failures are logged and give null, so one
     * unreachable source does not break the report.
     */
    private function fetch(string $what, \Closure $lookup): mixed
    {
        try {
            return $lookup();
        } catch (\Throwable $e) {
            Log::warning("Update check: {$what} failed: {$e->getMessage()}", ['category' => 'app']);

            return null;
        }
    }

    /**
     * Plain-text summary of what needs attention, for an email to the maintainer.
     *
     * @param  array<string, mixed>  $report
     */
    public static function summary(array $report): string
    {
        $lines = collect($report['components'])
            ->whereIn('status', self::ATTENTION)
            ->map(fn (array $c) => "- {$c['name']} {$c['installed']}: ".self::STATUSES[$c['status']][0]
                .($c['latest'] ? " (latest {$c['latest']})" : '')
                .($c['eol'] ? ", support until {$c['eol']}" : '')
                .($c['note'] ? ". {$c['note']}" : ''));

        return 'ModBot '.config('modbot.version').' on '.config('app.url')."\n"
            .'Checked: '.Carbon::parse($report['checked_at'])->timezone('Europe/Amsterdam')->format('d-m-Y H:i')."\n\n"
            .($lines->isEmpty() ? 'Everything is up to date.' : "Needs attention:\n".$lines->implode("\n"));
    }

    /**
     * @param  array<string, mixed>  $component
     */
    public static function statusLabel(array $component): string
    {
        return Arr::get(self::STATUSES, $component['status'].'.0', $component['status']);
    }
}
