<?php

namespace App\Filament\Resources\UserLogs\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class UserLogForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('user_id')
                    ->required()
                    ->numeric(),
                TextInput::make('action')
                    ->required(),
                TextInput::make('module')
                    ->required(),
                Textarea::make('description')
                    ->columnSpanFull(),
                TextInput::make('ip_address'),
            ]);
    }
}
