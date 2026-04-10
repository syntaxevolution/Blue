<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\GameSettingResource\Pages;
use App\Models\GameSetting;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Filament resource for the game_settings table — the admin-panel
 * surface over GameConfig's DB-override layer.
 *
 * The 'value' column is JSON: it can hold any scalar or structure.
 * The form renders it as a Textarea that accepts literal JSON so an
 * admin can type 25, 0.125, true, "cosmetic_only", or [1,2,3] and
 * have it parsed correctly on save. The 'type' enum is advisory —
 * GameConfigResolver doesn't check it on read, but it helps validation.
 */
class GameSettingResource extends Resource
{
    protected static ?string $model = GameSetting::class;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'Game';

    protected static ?string $navigationLabel = 'Game settings';

    protected static ?string $recordTitleAttribute = 'key';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('key')
                    ->required()
                    ->maxLength(191)
                    ->placeholder('e.g. stats.hard_cap')
                    ->helperText('Dot-path matching a key in config/game.php')
                    ->disabled(fn (?GameSetting $record) => $record !== null)
                    ->dehydrated()
                    ->rule('regex:/^[a-z0-9_\.]+$/i')
                    ->columnSpanFull(),

                Forms\Components\Select::make('type')
                    ->required()
                    ->options([
                        'int' => 'Integer',
                        'float' => 'Float',
                        'bool' => 'Boolean',
                        'string' => 'String',
                        'array' => 'Array / Object',
                        'enum' => 'Enum',
                    ])
                    ->default('int'),

                Forms\Components\Textarea::make('value')
                    ->required()
                    ->rows(4)
                    ->helperText('Literal JSON. Examples: 25, 0.125, true, "cosmetic_only", [1,2,3]')
                    ->formatStateUsing(fn ($state) => json_encode($state, JSON_UNESCAPED_SLASHES))
                    ->dehydrateStateUsing(fn ($state) => json_decode((string) $state, true))
                    ->rules([
                        function () {
                            return function (string $attribute, $value, \Closure $fail) {
                                if ($value === null && $value !== 'null') {
                                    $fail('The value must be valid JSON.');
                                }
                            };
                        },
                    ])
                    ->columnSpanFull(),

                Forms\Components\Textarea::make('description')
                    ->rows(2)
                    ->maxLength(1000)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('key')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage('Key copied')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->colors([
                        'primary' => 'int',
                        'warning' => 'float',
                        'success' => 'bool',
                        'secondary' => 'string',
                        'info' => 'array',
                        'danger' => 'enum',
                    ]),

                Tables\Columns\TextColumn::make('value')
                    ->label('Value (JSON)')
                    ->formatStateUsing(fn ($state) => json_encode($state, JSON_UNESCAPED_SLASHES))
                    ->limit(60)
                    ->tooltip(fn ($state) => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Last updated')
                    ->dateTime()
                    ->sortable()
                    ->since(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'int' => 'Integer',
                        'float' => 'Float',
                        'bool' => 'Boolean',
                        'string' => 'String',
                        'array' => 'Array',
                        'enum' => 'Enum',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('key');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGameSettings::route('/'),
            'create' => Pages\CreateGameSetting::route('/create'),
            'edit' => Pages\EditGameSetting::route('/{record}/edit'),
        ];
    }
}
