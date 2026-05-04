<?php

namespace App\Filament\Resources\AgingMaterials;

use App\Filament\Resources\AgingMaterials\Pages\ListAgingMaterials;
use App\Filament\Resources\AgingMaterials\Tables\AgingMaterialsTable;
use App\Models\Task;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AgingMaterialResource extends Resource
{
    protected static ?string $model = Task::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Aging Material';

    protected static ?string $modelLabel = 'Aging Material';

    protected static ?string $pluralModelLabel = 'Aging Materials';

    public static function getNavigationGroup(): ?string
    {
        $role = auth()->user()?->role;

        return $role === 'Administrator IT' || User::isGeneralManagerRole($role) ? 'Gudang' : null;
    }

    public static function table(Table $table): Table
    {
        return AgingMaterialsTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        $role = auth()->user()?->role;

        return in_array($role, [
            'Manager Operasional',
            'Manager Operational',
            'Manajer Operasional',
            'Finance',
            'Keuangan',
            'Administrator IT',
            User::ROLE_GENERAL_MANAGER,
        ], true);
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
            ->where(function (Builder $query): void {
                $query
                    ->where(function (Builder $q): void {
                        $q->where('status', 'pending')
                            ->whereRaw("payload->>'workflow_state' = ?", ['approved_for_aging']);
                    })
                    ->orWhere('status', 'done');
            });
    }

    public static function getNavigationBadge(): ?string
    {
        if (! self::canViewAny()) {
            return null;
        }

        $count = Task::query()
            ->where('type', 'ai_deadstock_recommendation')
            ->where('status', 'pending')
            ->whereRaw("payload->>'workflow_state' = ?", ['approved_for_aging'])
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
