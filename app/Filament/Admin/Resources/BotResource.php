<?php

namespace App\Filament\Admin\Resources;

use App\Domain\Bot\BotSpawnService;
use App\Filament\Admin\Resources\BotResource\Pages;
use App\Models\Player;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filament resource for admin-side bot player management. Mirrors the
 * Artisan commands (bots:spawn / bots:list / bots:set-difficulty /
 * bots:destroy) so non-technical admins can retune or remove bots
 * without SSH access to the VPS. The tick loop still runs on the
 * scheduler — this resource is observation + control, not execution.
 */
class BotResource extends Resource
{
    protected static ?string $model = Player::class;

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?string $navigationGroup = 'Game';

    protected static ?string $navigationLabel = 'Bots';

    protected static ?string $modelLabel = 'Bot';

    protected static ?string $pluralModelLabel = 'Bots';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('user', fn ($q) => $q->where('is_bot', true));
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('bot_difficulty')
                ->label('Difficulty')
                ->options([
                    'easy' => 'Easy',
                    'normal' => 'Normal',
                    'hard' => 'Hard',
                ])
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('user.name')->label('Name')->searchable(),
                Tables\Columns\TextColumn::make('bot_difficulty')
                    ->label('Tier')
                    ->badge()
                    ->colors([
                        'success' => 'easy',
                        'warning' => 'normal',
                        'danger' => 'hard',
                    ])
                    ->sortable(),
                Tables\Columns\TextColumn::make('akzar_cash')
                    ->label('Cash')
                    ->money('USD')
                    ->sortable(),
                Tables\Columns\TextColumn::make('oil_barrels')->label('Oil')->sortable(),
                Tables\Columns\TextColumn::make('moves_current')->label('Moves')->sortable(),
                Tables\Columns\TextColumn::make('mdn.tag')->label('MDN'),
                Tables\Columns\TextColumn::make('bot_last_tick_at')
                    ->label('Last tick')
                    ->since(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('bot_difficulty')
                    ->options([
                        'easy' => 'Easy',
                        'normal' => 'Normal',
                        'hard' => 'Hard',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Retune'),
                Action::make('destroy')
                    ->label('Destroy')
                    ->color('danger')
                    ->icon('heroicon-o-trash')
                    ->requiresConfirmation()
                    ->modalHeading('Destroy bot')
                    ->modalDescription('Releases the base tile back to wasteland and deletes the player + user rows. Not reversible.')
                    ->action(function (Player $record) {
                        app(BotSpawnService::class)->destroy($record);
                    }),
            ])
            ->defaultSort('bot_last_tick_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBots::route('/'),
            'edit' => Pages\EditBot::route('/{record}/edit'),
        ];
    }
}
