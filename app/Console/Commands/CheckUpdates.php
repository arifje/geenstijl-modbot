<?php

namespace App\Console\Commands;

use App\Services\System\SystemReport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('modbot:check-updates')]
#[Description('Check versions, end-of-life dates and security advisories (System → About & updates)')]
class CheckUpdates extends Command
{
    public function handle(SystemReport $report): int
    {
        $result = $report->refresh();

        foreach ($result['components'] as $component) {
            $this->line(sprintf('%-28s %-12s %-12s %s', $component['name'], $component['installed'] ?? '—', $component['latest'] ?? '—', SystemReport::statusLabel($component)));
        }

        $message = $result['attention'] === 0
            ? 'Update check: everything is up to date.'
            : "Update check: {$result['attention']} component(s) need attention. See System → About & updates.";

        Log::log($result['severity'] === 'danger' ? 'warning' : 'info', $message, ['category' => 'app']);
        $this->components->info($message);

        return self::SUCCESS;
    }
}
