<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\MdnResource\Pages;
use App\Models\Mdn;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class MdnResource extends Resource
{
    protected static ?string $model = Mdn::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Game';

    protected static ?string $navigationLabel = 'MDNs';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(50)
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('tag')
                    ->required()
                    ->maxLength(8),
                Forms\Components\TextInput::make('leader_player_id')
                    ->numeric()
                    ->required(),
                Forms\Components\TextInput::make('member_count')
                    ->numeric()
                    ->disabled()
                    ->dehydrated(false),
                Forms\Components\Textarea::make('motto')
                    ->maxLength(200)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('tag')
                    ->searchable()
                    ->badge()
                    ->color('warning'),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('member_count')
                    ->label('Members')
                    ->sortable(),
                Tables\Columns\TextColumn::make('leader_player_id')
                    ->label('Leader #'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->since(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->label('Disband'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('member_count', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMdns::route('/'),
            'create' => Pages\CreateMdn::route('/create'),
            'edit' => Pages\EditMdn::route('/{record}/edit'),
        ];
    }
}
