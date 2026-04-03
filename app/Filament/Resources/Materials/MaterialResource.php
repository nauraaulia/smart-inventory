<?php

namespace App\Filament\Resources\Materials;

use App\Filament\Resources\Materials\Pages\CreateMaterial;
use App\Filament\Resources\Materials\Pages\EditMaterial;
use App\Filament\Resources\Materials\Pages\ListMaterials;
use App\Filament\Resources\Materials\Schemas\MaterialForm;
use App\Filament\Resources\Materials\Tables\MaterialsTable;
use App\Models\Material;
use Illuminate\Database\Eloquent\Model;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class MaterialResource extends Resource
{
    protected static ?string $model = Material::class;
    //protected static string | \UnitEnum | null $navigationGroup = 'Kelola Data Master';
    public static function getNavigationGroup(): ?string
    {
        // Cek siapa yang lagi login
        if (auth()->check() && auth()->user()->role === 'Administrator IT') {
            // Kalau Admin, masukin ke laci 'Kelola Data Master'
            return 'Kelola Data Master';
        }

        // Kalau BUKAN Admin (misal: Staff), balikin null. 
        // null artinya dia bakal tampil di luar, mandiri sebagai menu utama!
        return null; 
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'Material';

    public static function form(Schema $schema): Schema
    {
        return MaterialForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MaterialsTable::configure($table);
    }

        // 1. Cuma Administrator IT yang bisa nambah barang baru
    public static function canCreate(): bool
    {
        return auth()->user()->role === 'Administrator IT'; 
    }

    // 2. Cuma Administrator IT yang bisa edit (termasuk edit Min Stock/Harga)
    public static function canEdit(Model $record): bool
    {
        return auth()->user()->role === 'Administrator IT';
    }

    // 3. Cuma Administrator IT yang bisa hapus data material
    public static function canDelete(Model $record): bool
    {
        return auth()->user()->role === 'Administrator IT';
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
            'index' => ListMaterials::route('/'),
            'create' => CreateMaterial::route('/create'),
            'edit' => EditMaterial::route('/{record}/edit'),
        ];
    }
}
