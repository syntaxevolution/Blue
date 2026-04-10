<?php

namespace App\Filament\Admin\Resources\GameSettingResource\Pages;

use App\Filament\Admin\Resources\GameSettingResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListGameSettings extends ListRecords
{
    protected static string $resource = GameSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
