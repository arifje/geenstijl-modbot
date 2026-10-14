<?php

namespace Tests\Feature;

use App\Filament\Resources\Schedules\Pages\ListSchedules;
use App\Models\Schedule;
use App\Models\User;
use App\Support\ScheduleFrequency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class ScheduleSortingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        Schedule::query()->delete(); // the seeded defaults

        // Like the production list: daily reports, not created in time order.
        foreach (['08', '14', '20', '11', '22', '17'] as $hour) {
            Schedule::create(['name' => "Rapport {$hour}:00", 'cron' => "0 {$hour} * * *", 'window_hours' => 24]);
        }
        Schedule::create(['name' => 'Analyse', 'cron' => '*/15 * * * *', 'window_hours' => 12, 'send_slack' => false, 'send_email' => false]);
        Schedule::create(['name' => 'Uit', 'cron' => '0 6 * * *', 'window_hours' => 12, 'enabled' => false]);
    }

    public function test_the_list_is_sorted_by_time_of_day_by_default(): void
    {
        Livewire::test(ListSchedules::class)
            ->assertCanSeeTableRecords($this->byName(['Analyse', 'Uit', 'Rapport 08:00', 'Rapport 11:00', 'Rapport 14:00', 'Rapport 17:00', 'Rapport 20:00', 'Rapport 22:00']), inOrder: true)
            ->sortTable('cron', 'desc')
            ->assertCanSeeTableRecords($this->byName(['Rapport 22:00', 'Rapport 20:00', 'Rapport 17:00', 'Rapport 14:00', 'Rapport 11:00', 'Rapport 08:00', 'Uit', 'Analyse']), inOrder: true);
    }

    public function test_it_sorts_by_next_run_with_disabled_schedules_last(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-14 15:05', 'Europe/Amsterdam'));

        Livewire::test(ListSchedules::class)
            ->sortTable('next_run')
            ->assertCanSeeTableRecords($this->byName(['Analyse', 'Rapport 17:00', 'Rapport 20:00', 'Rapport 22:00', 'Rapport 08:00', 'Rapport 11:00', 'Rapport 14:00', 'Uit']), inOrder: true);
    }

    public function test_it_sorts_by_name_and_by_stored_columns(): void
    {
        Livewire::test(ListSchedules::class)
            ->sortTable('name')
            ->assertCanSeeTableRecords($this->byName(['Analyse', 'Rapport 08:00', 'Rapport 11:00', 'Rapport 14:00', 'Rapport 17:00', 'Rapport 20:00', 'Rapport 22:00', 'Uit']), inOrder: true)
            ->sortTable('enabled')
            ->assertCanSeeTableRecords($this->byName(['Uit']), inOrder: true)
            ->sortTable('window_hours')
            ->assertSuccessful();
    }

    public function test_first_minute_of_day(): void
    {
        $this->assertSame(0, ScheduleFrequency::firstMinuteOfDay('*/15 * * * *'));
        $this->assertSame(480, ScheduleFrequency::firstMinuteOfDay('0 8,20 * * *'));
        $this->assertSame(450, ScheduleFrequency::firstMinuteOfDay('30 7 * * 1-5'));
        $this->assertSame(PHP_INT_MAX, ScheduleFrequency::firstMinuteOfDay('nonsense'));
    }

    /**
     * @param  list<string>  $names
     */
    private function byName(array $names): array
    {
        return array_map(fn (string $name) => Schedule::where('name', $name)->sole(), $names);
    }
}
