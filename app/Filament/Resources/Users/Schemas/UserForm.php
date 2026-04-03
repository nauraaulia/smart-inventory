<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Forms\Components\Select;

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
                DateTimePicker::make('email_verified_at'),
                TextInput::make('password')
                    ->password()
                    ->required(),
                Select::make('role')
                    ->label('Jabatan / Role')
                    ->options([
                        'Administrator IT' => 'Administrator IT',
                        'Manager Teknik' => 'Manager Teknik',
                        'Staff Administrasi Gudang' => 'Staff Administrasi Gudang',
                        'Finance' => 'Finance',
                        'Manager Operasional' => 'Manager Operasional',
                    ])
                    ->required(),
            ]);
    }
}
