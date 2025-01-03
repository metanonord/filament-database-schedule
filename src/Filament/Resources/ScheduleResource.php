<?php

namespace HusamTariq\FilamentDatabaseSchedule\Filament\Resources;

use Awcodes\FilamentTableRepeater\Components\TableRepeater;
use Carbon\Carbon;
use HusamTariq\FilamentDatabaseSchedule\Filament\Resources\ScheduleResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Components\Section;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\Column;
use Filament\Tables\Table;
use HusamTariq\FilamentDatabaseSchedule\Enums\Status;
use HusamTariq\FilamentDatabaseSchedule\Filament\Columns\ActionGroup;
use HusamTariq\FilamentDatabaseSchedule\Filament\Columns\ScheduleArguments;
use HusamTariq\FilamentDatabaseSchedule\Http\Services\CommandService;
use HusamTariq\FilamentDatabaseSchedule\Rules\Corn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Filament\Forms\Components\DatePicker;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Panlatent\CronExpressionDescriptor\ExpressionDescriptor as ExpressionDescriptor;

class ScheduleResource extends Resource
{

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static Collection $commands;


    public static function getModel(): string
    {
        return  config('filament-database-schedule.model');
    }

    public static function getNavigationIcon(): ?string
    {
        return config('filament-database-schedule.navigation_icon');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-database-schedule::schedule.resource.plural');
    }

    public static function getModelLabel(): string
    {
        return __('filament-database-schedule::schedule.resource.single');
    }

    protected static ?string $navigationGroup = 'Schedulazioni';

    public static function getSlug(): string
    {
        return config('filament-database-schedule.route_slug');
    }

    public static function form(Form $form): Form
    {
        static::$commands = CommandService::get();
        return $form
            ->schema([
                Section::make([
                    Forms\Components\Select::make('command')->label(__('filament-database-schedule::schedule.fields.command'))
                        ->options(
                            fn () =>
                            config('filament-database-schedule.commands.enable_custom') ?
                                static::$commands->pluck('full_name', 'name')->prepend(__('filament-database-schedule::schedule.messages.custom'), 'custom') : static::$commands->pluck('full_name', 'name')
                        )
                        ->reactive()
                        ->searchable()
                        ->required()
                        ->afterStateUpdated(function ($set, $state) {
                            $set('params', static::$commands->firstWhere('name', $state)['arguments'] ?? []);
                            $set('options_with_value', static::$commands->firstWhere('name', $state)['options']["withValue"] ?? []);
                        }),
                    Forms\Components\Select::make('custom_type_selection')
                        ->label('Tipo')
                        ->options([
                            'function' => 'Function',
                            'stored-procedure' => 'Stored Procedure',
                            'package' => 'Package',
                            'php-script' => 'PHP Script',
                            'bash-command' => 'Bash Command',
                            'route' => 'Scheduled function on Route'
                        ])
                        ->reactive() // Importante per aggiornare dinamicamente altri campi basandosi sulla selezione
                        ->required(),
                    Forms\Components\Select::make('custom_connection')
                        ->label('Connessione')
                        ->required()
                        ->options([
                            'oracle' => 'Core',
                            'oracleaux' => 'Aux',
                            'mn' => 'MetanoNord',
                            'ut' => 'Utilità',
                            'bl_webapp_se' => 'BL Webapp SE',
                            'bl_webapp_mn' => 'BL Webapp MN',
                            'bl_webapp_ut' => 'BL Webapp UT',
                            'bl_webapp_u4' => 'BL Webapp U4U',
                        ])
                        ->reactive() // Rende il campo reattivo
                        ->visible(fn ($get) => $get('custom_type_selection') === 'function' || $get('custom_type_selection') === 'stored-procedure')
                        ->searchable(),
                    Forms\Components\TextInput::make('custom_script_name')
                        ->placeholder(__('Inserisci il nome dello script da eseguire'))
                        ->helperText(new HtmlString('in caso di <strong>PHP Script</strong> o <strong>Bash Command</strong> aggiungere il path completo. In caso di <strong>Procedure</strong> o <strong>Function</strong> può essere aggiunto il Package e/o parametri in query string. (Es. uri?param=value). In caso di <strong>Route Function</strong> inserire solo lo slug.'))
                        ->label(__('Nome Script'))
                        ->required()
                        ->reactive()
                        ->live(onBlur: true)
                        ->afterStateUpdated(function ($set, $state, $get) {
                            $typeSelection = $get('custom_type_selection');
                            $customConnection = $get('custom_connection');
                            // Inizializza la variabile commandCustomFinal
                            $commandCustomFinal = "";
                            // Utilizza uno switch per determinare la stringa in base a custom_type_selection
                            switch ($typeSelection) {
                                case 'php-script':
                                    $commandCustomFinal = "php {$state}";
                                    break;
                                case 'bash-command':
                                    $commandCustomFinal = "{$state}";
                                    break;
                                case 'route':
                                    $commandCustomFinal = "php artisan route:call --uri=\"/{$state}\"";
                                    break;
                                default:
                                    $commandCustomFinal = "php artisan route:call --uri=\"/{$typeSelection}/{$customConnection}/{$state}\"";
                                    break;
                            }

                            // Imposta il valore di command_custom con il risultato finale
                            $set('command_custom', $commandCustomFinal);
                        })
                        ->visible(fn ($get) => $get('command') === 'custom' && config('filament-database-schedule.commands.enable_custom')),
                    Forms\Components\TextInput::make('command_custom')
                        ->readOnly()
                        ->reactive()
                        ->label(__('Custom Command'))
                        ->visible(fn ($get) => $get('command') === 'custom' && config('filament-database-schedule.commands.enable_custom')),
                    Forms\Components\TextInput::make('custom_descrizione')
                        ->label('Descrizione')
                        ->required(),
                    Forms\Components\DatePicker::make('custom_data_attivazione')
                        ->label('Data Attivazione')
                        ->default(Carbon::now())
                        ->required(),
                    Forms\Components\TextInput::make('custom_creato_da')
                        ->label('Creato da')
                        ->default(auth()->user()->name) // Imposta il valore predefinito per il campo con il nome dell'utente loggato
                        ->readonly(),
                    TableRepeater::make('params')->label(__('filament-database-schedule::schedule.fields.arguments'))
                        ->schema([
                            Forms\Components\TextInput::make('value')->label(fn ($get) => ucfirst($get('name')))->required(fn ($get) => $get('required')),
                            Forms\Components\Hidden::make('name'),
                        ])->addable(false)->withoutHeader()->deletable(false)->reorderable(false)
                        ->columnSpan('full')->visible(fn ($get) => !empty(static::$commands->firstWhere('name', $get('command'))['arguments'])),
                    TableRepeater::make('options_with_value')->label(__('filament-database-schedule::schedule.fields.options_with_value'))
                        ->schema([
                            Forms\Components\TextInput::make('value')->label(fn ($get) => ucfirst($get('name')))->required(fn ($get) => $get('required')),
                            Forms\Components\Hidden::make('type')->default('string'),
                            Forms\Components\Hidden::make('name'),
                        ])->addable(false)->withoutHeader()->deletable(false)->reorderable(false)->default([])
                        ->columnSpan('full')->visible(fn ($state) => !empty($state)),
                    Forms\Components\CheckboxList::make('options')->label(__('filament-database-schedule::schedule.fields.options'))
                        ->options(
                            fn ($get) =>
                            collect(static::$commands->firstWhere('name', $get('command'))['options']['withoutValue'] ?? [])
                                ->mapWithKeys(function ($value) {
                                    return [$value => $value];
                                }),
                        )->columns(3)->columnSpanFull()->visible(fn (Forms\Components\CheckboxList $component) => !empty($component->getOptions())),
                    Forms\Components\TextInput::make('expression')
                        ->placeholder('* * * * *')
                        ->rules([new Corn()])
                        ->reactive()
                        ->label(__('filament-database-schedule::schedule.fields.expression'))
                        ->required()->helperText(fn () => config('filament-database-schedule.tool-help-cron-expression.enable') ? new HtmlString(" <a href='" . config('filament-database-schedule.tool-help-cron-expression.url') . "' target='_blank'>" . __('filament-database-schedule::schedule.messages.help-cron-expression') . " </a>") : null)
                        ->afterStateUpdated(function ($set, $state) {
                            if (!empty($state)) {
                                try {
                                    $description = (new ExpressionDescriptor($state, 'it_IT'))->getDescription();
                                    $set('custom_frequenza', $description);
                                } catch (\Exception $e) {
                                    // Gestisci l'eccezione se la stringa cron non è valida
                                    $set('custom_frequenza', __('Invalid Cron Expression'));
                                }
                            }
                        }),
                    Forms\Components\TextInput::make('custom_frequenza')
                        ->label('Descrizione frequenza')
                        ->required()
                        ->readonly(),
                    Forms\Components\TagsInput::make('environments')
                        ->placeholder(null)
                        ->visible(false)
                        ->label(__('filament-database-schedule::schedule.fields.environments')),
                    Forms\Components\TextInput::make('log_filename')
                        ->label(__('filament-database-schedule::schedule.fields.log_filename'))
                        ->visible(false)
                        ->helperText(__('filament-database-schedule::schedule.messages.help-log-filename')),
                    Forms\Components\TextInput::make('webhook_before')
                        ->visible(false)
                        ->label(__('filament-database-schedule::schedule.fields.webhook_before')),
                    Forms\Components\TextInput::make('webhook_after')
                        ->visible(false)
                        ->label(__('filament-database-schedule::schedule.fields.webhook_after')),
                    Forms\Components\TextInput::make('email_output')
                        ->visible(false)
                        ->label(__('filament-database-schedule::schedule.fields.email_output')),
                    Forms\Components\Section::make('History')
                        ->label(__('filament-database-schedule::schedule.button.history'))
                        ->columns(2)
                        ->schema([
                            Forms\Components\Toggle::make('log_success')
                                ->label(__('filament-database-schedule::schedule.fields.log_success'))->default(true),
                            Forms\Components\Toggle::make('log_error')
                                ->label(__('filament-database-schedule::schedule.fields.log_error'))->default(true),
                            Forms\Components\Toggle::make('limit_history_count')
                                ->label(__('filament-database-schedule::schedule.fields.limit_history_count'))
                                ->visible(false)
                                ->live(),
                            Forms\Components\TextInput::make('max_history_count')
                                ->label('')
                                ->numeric()
                                ->default(10)
                                ->visible(false),
                        ]),
                    Forms\Components\Toggle::make('sendmail_success')
                        ->label(__('filament-database-schedule::schedule.fields.sendmail_success')),
                    Forms\Components\Toggle::make('sendmail_error')
                        ->label(__('filament-database-schedule::schedule.fields.sendmail_error')),
                    Forms\Components\Toggle::make('even_in_maintenance_mode')
                        ->visible(false)
                        ->label(__('filament-database-schedule::schedule.fields.even_in_maintenance_mode')),
                    Forms\Components\Toggle::make('without_overlapping')
                        ->visible(false)
                        ->label(__('filament-database-schedule::schedule.fields.without_overlapping')),
                    Forms\Components\Toggle::make('on_one_server')
                        ->visible(false)
                        ->label(__('filament-database-schedule::schedule.fields.on_one_server')),
                    Forms\Components\Toggle::make('run_in_background')
                        ->visible(false)
                        ->label(__('filament-database-schedule::schedule.fields.run_in_background')),
                ])->inlineLabel(false)
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('custom_descrizione')
                    ->label('Descrizione')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('custom_type_selection')
                    ->label('Tipo Comando')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('custom_frequenza')
                    ->label('Frequenza')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('command')->getStateUsing(function ($record) {
                    if ($record->command == 'custom')
                        return $record->command_custom;
                    return $record->command;
                })->label(__('filament-database-schedule::schedule.fields.command'))->searchable()->sortable(),
                ScheduleArguments::make('params')->label(__('filament-database-schedule::schedule.fields.arguments'))->searchable()->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('options')->label(__('filament-database-schedule::schedule.fields.options'))->searchable()->sortable()->getStateUsing(fn (Model $record) => $record->getOptions())->separator(',')->badge()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('expression')->label(__('filament-database-schedule::schedule.fields.expression'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('environments')->label(__('filament-database-schedule::schedule.fields.environments'))->separator(',')->searchable()->sortable()->badge()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('filament-database-schedule::schedule.fields.status'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\TextColumn::make('created_at')->label(__('filament-database-schedule::schedule.fields.created_at'))->searchable()->sortable()
                    ->dateTime()->wrap()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')->getStateUsing(fn ($record) => $record->created_at == $record->updated_at ? __('filament-database-schedule::schedule.fields.never') : $record->updated_at)
                    ->wrap()->formatStateUsing(static function (Column $column, $state): ?string {
                        $format ??= Table::$defaultDateTimeDisplayFormat;

                        if (blank($state) || $state == __('filament-database-schedule::schedule.fields.never')) {
                            return $state;
                        }

                        return Carbon::parse($state)
                            ->setTimezone($timezone ?? $column->getTimezone())
                            ->translatedFormat($format);
                    })->label(__('filament-database-schedule::schedule.fields.updated_at'))->searchable()->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('custom_data_attivazione')
                    ->label('Data Attivazione')
                    ->date()
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('custom_creato_da')
                    ->label('Creato da')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('custom_connection')
                    ->label('Connessione')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('last_run')
                    ->label('Ultimo Svolgimento')
                    ->getStateUsing(function ($record) {
                        return optional($record->histories()->latest('created_at')->first())->created_at;
                    })
                    ->dateTime()
                    ->sortable(),
            ])
           ->filters([
            Tables\Filters\TrashedFilter::make(),
            Tables\Filters\SelectFilter::make('status')
                ->label('Stato')
                ->options([
                    Status::Active => 'Attivo',
                    Status::Inactive => 'Inattivo',
                ]),
        ])
            ->actions([
                ActionGroup::make([
                    Tables\Actions\EditAction::make()->hidden(fn ($record) => $record->trashed())->tooltip(__('filament-actions::edit.single.label')),
                    Tables\Actions\RestoreAction::make()->tooltip(__('filament-actions::restore.single.label')),
                    Tables\Actions\DeleteAction::make()->tooltip(__('filament-actions::delete.single.label')),
                    Tables\Actions\ForceDeleteAction::make()->tooltip(__('filament-support::actions/force-delete.single.label')),
                    Tables\Actions\Action::make('toggle')
                        ->disabled(fn ($record) => $record->trashed())
                        ->icon(fn ($record) => $record->status === Status::Active ? 'schedule-pause-fill' : 'schedule-play-fill')
                        ->color(fn ($record) => $record->status === Status::Active ? 'warning' : 'success')
                        ->action(function ($record): void {
                            if ($record->status === Status::Active)
                                $record->status = Status::Inactive;
                            else if ($record->status === Status::Inactive)
                                $record->status = Status::Active;
                            $record->save();
                        })->tooltip(fn ($record) => $record->status === Status::Active ? __('filament-database-schedule::schedule.buttons.inactivate') : __('filament-database-schedule::schedule.buttons.activate')),
                    Tables\Actions\ViewAction::make()
                        ->icon('schedule-history')
                        ->color('gray')
                        ->tooltip(__('filament-database-schedule::schedule.buttons.history'))
                        ->visible(fn ($record) => $record->histories()->count()),
                ])

            ])
            ->defaultPaginationPageOption(config('filament-database-schedule.per_page', 10) ?: config('tables.pagination.default_records_per_page'))
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ])->defaultSort(config('filament-database-schedule.default_ordering'), config('filament-database-schedule.default_ordering_direction'));
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSchedules::route('/'),
            'create' => Pages\CreateSchedule::route('/create'),
            'edit' => Pages\EditSchedule::route('/{record}/edit'),
            'view' => Pages\ViewSchedule::route('/{record}'),
        ];
    }

    public static function canAccess(): bool
    {
        return Auth::user()->is_admin == 1 || Auth::user()->can('view Schedule');
    }
}
