<?php

namespace App\Logging;

use App\Models\LogEntry;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;

/**
 * Writes log records to the log_entries table, so the admin can show logs
 * from every container (web, scheduler) in one place.
 *
 * Put "category" in the log context to group entries (default: app).
 */
class DatabaseLogHandler extends AbstractProcessingHandler
{
    private static bool $writing = false;

    protected function write(LogRecord $record): void
    {
        // A failing insert is logged too; don't loop.
        if (self::$writing) {
            return;
        }

        self::$writing = true;

        try {
            $context = $record->context;
            $category = $context['category'] ?? 'app';
            unset($context['category']);

            LogEntry::create([
                'level' => strtolower($record->level->getName()),
                'level_value' => $record->level->value,
                'category' => mb_substr((string) $category, 0, 20),
                'message' => mb_substr($record->message, 0, 10_000),
                'context' => $context === [] ? null : $this->normalize($context),
                'source' => $this->source(),
                'created_at' => $record->datetime,
            ]);
        } catch (\Throwable) {
            // The database is unavailable (e.g. during the image build); the
            // other log channels still have the record.
        } finally {
            self::$writing = false;
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function normalize(array $context): array
    {
        return collect($context)->map(function ($value) {
            if ($value instanceof \Throwable) {
                return [
                    'class' => $value::class,
                    'message' => $value->getMessage(),
                    'file' => $value->getFile().':'.$value->getLine(),
                    'trace' => collect(explode("\n", $value->getTraceAsString()))->take(15)->implode("\n"),
                ];
            }

            return is_scalar($value) || $value === null || is_array($value) ? $value : get_debug_type($value);
        })->all();
    }

    private function source(): string
    {
        if (! app()->runningInConsole()) {
            return 'web';
        }

        return mb_substr(implode(' ', array_slice($_SERVER['argv'] ?? [], 1, 2)) ?: 'cli', 0, 255);
    }
}
