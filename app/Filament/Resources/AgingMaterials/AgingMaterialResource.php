<?php

namespace App\Filament\Resources\AgingMaterials;

use App\Filament\Resources\AgingMaterials\Pages\ListAgingMaterials;
use App\Filament\Resources\AgingMaterials\Tables\AgingMaterialsTable;
use App\Models\Task;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AgingMaterialResource extends Resource
{
    protected static ?string $model = Task::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Aging Material';

    protected static ?string $modelLabel = 'Aging Material';

    protected static ?string $pluralModelLabel = 'Aging Materials';

    public static function table(Table $table): Table
    {
        return AgingMaterialsTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        $role = auth()->user()?->role;

        return in_array($role, ['Manager Operasional', 'Manager Operational', 'Manajer Operasional', 'Administrator IT'], true);
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('type', 'ai_deadstock_recommendation')
            ->whereIn('status', ['approved', 'done']);
    }

    public static function getNavigationBadge(): ?string
    {
        if (!self::canViewAny()) {
            return null;
        }

        $count = Task::query()
            ->where('type', 'ai_deadstock_recommendation')
            ->where('status', 'approved')
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgingMaterials::route('/'),
        ];
    }
}
