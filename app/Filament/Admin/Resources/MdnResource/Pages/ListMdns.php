<?php

namespace App\Filament\Admin\Resources\MdnResource\Pages;

use App\Filament\Admin\Resources\MdnResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMdns extends ListRecords
{
    protected static string $resource = MdnResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
