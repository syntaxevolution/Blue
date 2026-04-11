<?php

namespace App\Filament\Admin\Resources\MdnResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class JournalEntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'journalEntries';

    protected static ?string $title = 'Journal';

    protected static ?string $recordTitleAttribute = 'id';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('author_player_id')->label('Author #'),
                Tables\Columns\TextColumn::make('body')->limit(80)->searchable(),
                Tables\Columns\TextColumn::make('helpful_count')->label('👍')->sortable(),
                Tables\Columns\TextColumn::make('unhelpful_count')->label('👎')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->since(),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
