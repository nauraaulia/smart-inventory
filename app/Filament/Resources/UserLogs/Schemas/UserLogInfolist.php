<?php

namespace App\Filament\Resources\UserLogs\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Identitas User')
                    ->schema([
                        TextEntry::make('user.id_custom')
                            ->label('User ID')
                            ->placeholder('-'),
                        TextEntry::make('user.name')
                            ->label('Nama User')
                            ->placeholder('-'),
                        TextEntry::make('ip_address')
                            ->label('IP Address')
                            ->placeholder('-'),
                    ])
                    ->columnSpan(1),

                Section::make('Metadata Aktivitas')
                    ->schema([
                        TextEntry::make('action')
                            ->label('Action'),
                        TextEntry::make('module')
                            ->label('Module'),
                        TextEntry::make('created_at')
                            ->label('Created At')
                            ->dateTime()
                            ->placeholder('-'),
                    ])
                    ->columnSpan(1),

                Section::make('Detail Aktivitas')
                    ->schema([
                        TextEntry::make('action')
                            ->label('Jenis Aktivitas')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'Create' => 'success',
                                'Update' => 'warning',
                                'Delete' => 'danger',
                                'Activity' => 'info',
                                'Login' => 'primary',
                                'Outbound Taken' => 'success',
                                'Auto Allocate' => 'info',
                                default => 'gray',
                            }),
                        TextEntry::make('module')
                            ->label('Modul Terkait')
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('description')
                            ->label('Detail Aktivitas')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
