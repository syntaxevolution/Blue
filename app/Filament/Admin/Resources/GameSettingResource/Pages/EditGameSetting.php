<?php

namespace App\Filament\Admin\Resources\GameSettingResource\Pages;

use App\Filament\Admin\Resources\GameSettingResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditGameSetting extends EditRecord
{
    protected static string $resource = GameSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
