<?php

namespace App\Filament\Pages;

use App\Services\System\SystemReport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * What runs in this installation and whether it needs updates, with the
 * maintainer's contact details. Checked daily (modbot:check-updates).
 */
class SystemInfo extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInformationCircle;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'About & updates';

    protected static ?string $title = 'About & updates';

    protected static ?string $slug = 'about';

    protected static ?int $navigationSort = 1;

    /** @var array<string, mixed>|null */
    public ?array $report = null;

    public function mount(SystemReport $systemReport): void
    {
        // Built on the first visit if the daily check has not run yet.
        $this->report = $systemReport->cached() ?? $systemReport->refresh();
    }

    /**
     * Red or orange count in the menu when something needs attention.
     */
    public static function getNavigationBadge(): ?string
    {
        $attention = app(SystemReport::class)->cached()['attention'] ?? 0;

        return $attention > 0 ? (string) $attention : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return app(SystemReport::class)->cached()['severity'] ?? null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Components that need an update';
    }

    public function getSubheading(): ?string
    {
        $checked = Carbon::parse($this->report['checked_at'])->timezone('Europe/Amsterdam');

        return match (true) {
            $this->report['attention'] === 0 => "Everything is up to date. Checked {$checked->diffForHumans()}.",
            default => "{$this->report['attention']} component(s) need attention. Checked {$checked->diffForHumans()}; "
                .(filled(config('modbot.maintainer.email')) ? 'contact your maintainer below.' : 'contact your maintainer.'),
        };
    }

    public function content(Schema $schema): Schema
    {
        $maintainer = config('modbot.maintainer');

        return $schema
            ->constantState([
                'components' => $this->report['components'],
                'maintainer' => $maintainer,
            ])
            ->components([
                Section::make('Components')
                    ->description('Installed versions, latest versions and end of support. '
                        ."All {$this->report['packages_checked']} installed PHP packages are checked for known security issues; "
                        .'packages with an issue are listed here too.')
                    ->schema([
                        RepeatableEntry::make('components')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Component'),
                                TableColumn::make('Installed'),
                                TableColumn::make('Latest'),
                                TableColumn::make('Supported until'),
                                TableColumn::make('Status'),
                                TableColumn::make('Note'),
                            ])
                            ->schema([
                                TextEntry::make('name')->weight('medium')
                                    ->belowContent(fn ($get) => $get('group')),
                                TextEntry::make('installed')->placeholder('—')->fontFamily('mono'),
                                TextEntry::make('latest')->placeholder('—')->fontFamily('mono'),
                                TextEntry::make('eol')->placeholder('—')->date('d-m-Y'),
                                TextEntry::make('status')->badge()
                                    ->formatStateUsing(fn (string $state) => SystemReport::STATUSES[$state][0] ?? $state)
                                    ->color(fn (string $state) => SystemReport::STATUSES[$state][1] ?? 'gray'),
                                TextEntry::make('note')->placeholder('')->wrap()->color('gray'),
                            ]),
                    ]),
                Section::make('Application')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('version')->label('ModBot version')->state(config('modbot.version'))->fontFamily('mono'),
                        TextEntry::make('environment')->state(app()->environment()),
                        TextEntry::make('url')->label('Address')->state(config('app.url')),
                        TextEntry::make('php')->label('PHP')->state(PHP_VERSION.' ('.PHP_SAPI.')'),
                        TextEntry::make('database')->state(DB::getDriverName().' · '.config('database.connections.'.config('database.default').'.database')),
                        TextEntry::make('ai')->label('AI provider')->state(config('modbot.llm.provider').' · '.config('modbot.llm.providers.'.config('modbot.llm.provider').'.model')),
                    ]),
                Section::make('Maintenance')
                    ->description('Updates are not installed automatically. When something needs attention, contact the maintainer of this installation.')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('maintainer.name')->label('Maintainer')->placeholder('Not set (MODBOT_MAINTAINER_NAME)'),
                        TextEntry::make('maintainer.email')->label('Email')->placeholder('Not set (MODBOT_MAINTAINER_EMAIL)')
                            ->url(fn () => filled($maintainer['email']) ? 'mailto:'.$maintainer['email'] : null),
                        TextEntry::make('maintainer.url')->label('Website')->placeholder('—')
                            ->url(fn () => $maintainer['url'] ?: null, shouldOpenInNewTab: true),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('emailMaintainer')
                ->label('Email maintainer')
                ->icon(Heroicon::OutlinedEnvelope)
                ->visible(fn () => filled(config('modbot.maintainer.email')))
                ->url(fn () => 'mailto:'.config('modbot.maintainer.email')
                    .'?subject='.rawurlencode('ModBot: '.($this->report['attention'] > 0 ? "{$this->report['attention']} update(s) needed" : 'status'))
                    .'&body='.rawurlencode(SystemReport::summary($this->report))),
            Action::make('checkNow')
                ->label('Check now')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(function (SystemReport $systemReport) {
                    $this->report = $systemReport->refresh();
                    Notification::make()->title('Checked for updates')->success()->send();
                }),
        ];
    }
}
