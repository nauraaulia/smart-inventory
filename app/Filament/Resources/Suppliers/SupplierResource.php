<?php

namespace App\Filament\Resources\Suppliers;

use App\Filament\Resources\Suppliers\Pages\CreateSupplier;
use App\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Filament\Resources\Suppliers\Schemas\SupplierForm;
use App\Filament\Resources\Suppliers\Tables\SuppliersTable;
use App\Models\Supplier;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;
    protected static string | \UnitEnum | null $navigationGroup = 'Data Master';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'Supplier';
    
    public static function form(Schema $schema): Schema
        {
           return SupplierForm::configure($schema);
        }

    public static function table(Table $table): Table
        {
            return SuppliersTable::table($table);
        }
    
    public static function canViewAny(): bool
    {
        $role = auth()->user()?->role;

        return filled($role) && !in_array($role, ['Finance', 'Keuangan'], true);
    }

    public static function canCreate(): bool
    {
        return self::canManageModule();
    }

    public static function canEdit(Model $record): bool
    {
        return self::canManageModule();
    }

    public static function canDelete(Model $record): bool
    {
        return self::canManageModule();
    }

    private static function canManageModule(): bool
    {
        return in_array(auth()->user()?->role, ['Staff Administrasi Gudang', 'Staff Adm Gudang', 'Staff Gudang'], true);
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
            'index' => ListSuppliers::route('/'),
            'create' => CreateSupplier::route('/create'),
            'edit' => EditSupplier::route('/{record}/edit'),
        ];
    }
}
