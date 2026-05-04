<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('id_custom')
                    ->label('ID User')
                    ->disabled() // <--- BIAR GAK BISA DIEDIT (Locked)
                    ->placeholder('Otomatis dibuat sistem'),
                TextInput::make('name')
                    ->required()
                    ->unique(ignoreRecord: true),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true),
                TextInput::make('password')
                    ->password()
                    ->required(),
                Select::make('role')
                    ->label('Jabatan / Role')
                    ->options(User::roleOptions())
                    ->required(),
            ]);
    }
}
