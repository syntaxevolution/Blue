<?php

namespace App\Filament\Admin\Resources\MdnResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class MembershipsRelationManager extends RelationManager
{
    protected static string $relationship = 'memberships';

    protected static ?string $title = 'Members';

    protected static ?string $recordTitleAttribute = 'player_id';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('role')
                ->options([
                    'leader' => 'Leader',
                    'officer' => 'Officer',
                    'member' => 'Member',
                ])
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('player_id')->label('Player #')->sortable(),
                Tables\Columns\TextColumn::make('player.user.name')->label('Username')->searchable(),
                Tables\Columns\TextColumn::make('role')->badge()->sortable(),
                Tables\Columns\TextColumn::make('joined_at')->dateTime()->since(),
                Tables\Columns\TextColumn::make('player.akzar_cash')->label('Cash')->money('USD', locale: 'en'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()->label('Kick'),
            ])
            ->defaultSort('joined_at');
    }
}
