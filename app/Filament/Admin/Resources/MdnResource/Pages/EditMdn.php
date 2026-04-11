<?php

namespace App\Filament\Admin\Resources\MdnResource\Pages;

use App\Filament\Admin\Resources\MdnResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMdn extends EditRecord
{
    protected static string $resource = MdnResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->label('Disband'),
        ];
    }
}
