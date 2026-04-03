<?php

namespace App\Filament\Resources\ShelfLocations;

use App\Filament\Resources\ShelfLocations\Pages\CreateShelfLocation;
use App\Filament\Resources\ShelfLocations\Pages\EditShelfLocation;
use App\Filament\Resources\ShelfLocations\Pages\ListShelfLocations;
use App\Filament\Resources\ShelfLocations\Schemas\ShelfLocationForm;
use App\Filament\Resources\ShelfLocations\Tables\ShelfLocationsTable;
use App\Models\ShelfLocation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ShelfLocationResource extends Resource
{
    protected static ?string $model = ShelfLocation::class;
    protected static string | \UnitEnum | null $navigationGroup = 'Kelola Data Master';
    
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'Shelf Location';

    public static function form(Schema $schema): Schema
    {
        return ShelfLocationForm::configure($schema);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()->role === 'Administrator IT';
    }
    
    public static function table(Table $table): Table
    {
        return ShelfLocationsTable::configure($table);
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
            'index' => ListShelfLocations::route('/'),
            'create' => CreateShelfLocation::route('/create'),
            'edit' => EditShelfLocation::route('/{record}/edit'),
        ];
    }
}
