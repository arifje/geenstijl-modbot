<?php

namespace Tests\Feature;

use App\Filament\Resources\Schedules\Pages\CreateSchedule;
use App\Filament\Resources\Schedules\Pages\EditSchedule;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ScheduleFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_a_new_schedule_is_created_from_simple_choices(): void
    {
        Livewire::test(CreateSchedule::class)
            ->assertFormSet(['frequency' => 'daily', 'times' => ['08:00', '20:00']])
            ->fillForm([
                'name' => 'Werkdagen',
                'frequency' => 'weekly',
                'days' => [1, 2, 3, 4, 5],
                'times' => ['07:30', '12:30'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('30 7,12 * * 1,2,3,4,5', Schedule::where('name', 'Werkdagen')->sole()->cron);
    }

    public function test_an_existing_schedule_opens_in_the_simple_form(): void
    {
        $report = Schedule::where('name', 'Moderatierapport')->sole();

        Livewire::test(EditSchedule::class, ['record' => $report->id])
            ->assertFormSet(['frequency' => 'daily', 'times' => ['08:00', '20:00']])
            ->assertSee('Every day at 08:00 and 20:00')
            ->fillForm(['frequency' => 'minutes', 'every_minutes' => 15])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('*/15 * * * *', $report->fresh()->cron);
    }

    public function test_unusual_expressions_open_as_advanced(): void
    {
        $schedule = Schedule::create(['name' => 'Eerste van de maand', 'cron' => '0 8 1 * *', 'window_hours' => 12]);

        Livewire::test(EditSchedule::class, ['record' => $schedule->id])
            ->assertFormSet(['frequency' => 'cron', 'cron' => '0 8 1 * *'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('0 8 1 * *', $schedule->fresh()->cron);
    }

    public function test_times_with_different_minutes_are_refused(): void
    {
        Livewire::test(CreateSchedule::class)
            ->fillForm(['name' => 'Mis', 'frequency' => 'daily', 'times' => ['08:00', '20:30']])
            ->call('create')
            ->assertHasFormErrors(['times']);

        $this->assertSame(0, Schedule::where('name', 'Mis')->count());
    }

    public function test_the_list_shows_readable_schedules(): void
    {
        $this->get('/admin/schedules')
            ->assertOk()
            ->assertSee('Every day at 08:00 and 20:00')
            ->assertSee('Every 15 minutes');
    }
}
