<?php

namespace App\Filament\Resources\Tasks\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class TaskInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('for_role'),
                TextEntry::make('type'),
                TextEntry::make('title'),
                TextEntry::make('message')
                    ->columnSpanFull(),
                TextEntry::make('status'),
                TextEntry::make('related_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('related_type')
                    ->placeholder('-'),
                TextEntry::make('handled_by')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('handled_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
