<?php

namespace App\Filament\Resources\UserLogs;

use App\Filament\Resources\UserLogs\Pages\CreateUserLog;
use App\Filament\Resources\UserLogs\Pages\EditUserLog;
use App\Filament\Resources\UserLogs\Pages\ListUserLogs;
use App\Filament\Resources\UserLogs\Pages\ViewUserLog;
use App\Filament\Resources\UserLogs\Schemas\UserLogForm;
use App\Filament\Resources\UserLogs\Schemas\UserLogInfolist;
use App\Filament\Resources\UserLogs\Tables\UserLogsTable;
use App\Models\UserLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class UserLogResource extends Resource
{
    protected static ?string $model = UserLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'User Log';

    public static function form(Schema $schema): Schema
    {
        return UserLogForm::configure($schema);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return UserLogInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UserLogsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()->role === 'Administrator IT';
    }
    
    public static function getPages(): array
    {
        return [
            'index' => ListUserLogs::route('/'),
            'view' => ViewUserLog::route('/{record}'),
        ];
    }
}
