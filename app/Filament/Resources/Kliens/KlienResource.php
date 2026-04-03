<?php

namespace App\Filament\Resources\Kliens;

use App\Filament\Resources\Kliens\Pages\CreateKlien;
use App\Filament\Resources\Kliens\Pages\EditKlien;
use App\Filament\Resources\Kliens\Pages\ListKliens;
use App\Filament\Resources\Kliens\Schemas\KlienForm;
use App\Filament\Resources\Kliens\Tables\KliensTable;
use App\Models\Klien;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class KlienResource extends Resource
{
    protected static ?string $model = Klien::class;
    protected static string | \UnitEnum | null $navigationGroup = 'Kelola Data Master';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'Klien';

    public static function form(Schema $schema): Schema
    {
        return KlienForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return KliensTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKliens::route('/'),
            'create' => CreateKlien::route('/create'),
            'edit' => EditKlien::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()->role === 'Administrator IT';
    }
}
