<?php

namespace App\Filament\Resources\Tasks;

use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Resources\Tasks\Schemas\TaskForm;
use App\Filament\Resources\Tasks\Tables\TasksTable;
use App\Models\Task;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class TaskResource extends Resource
{
    protected static ?string $model = Task::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Tasks AI';

    protected static ?string $modelLabel = 'Task';

    protected static ?string $pluralModelLabel = 'Tasks';

    public static function getNavigationGroup(): ?string
    {
        return auth()->user()?->role === 'Administrator IT' ? 'Gudang' : null;
    }

    public static function form(Schema $schema): Schema
    {
        return TaskForm::configure($schema);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        $role = auth()->user()?->role;

        return self::isAdminIt($role)
            || self::isManagerTeknik($role)
            || self::isStaffAdministrasiGudang($role);
    }

    public static function canEdit($record): bool
    {
        $user = auth()->user();

        if (!$user || !$record instanceof Task) {
            return false;
        }

        if (self::isAdminIt($user->role)) {
            return false;
        }

        if (!self::isManagerTeknik($user->role) && !self::isStaffAdministrasiGudang($user->role)) {
            return false;
        }

        return $record->status === 'pending'
            && in_array($record->for_role, self::roleAliases($user->role), true);
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return TasksTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if (!$user) {
            return $query->whereRaw('1 = 0');
        }

        if (self::isAdminIt($user->role)) {
            return $query;
        }

        if (!self::isManagerTeknik($user->role) && !self::isStaffAdministrasiGudang($user->role)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('for_role', self::roleAliases($user->role));
    }

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();

        if (!$user) {
            return null;
        }

        $query = Task::query()->where('status', 'pending');

        if (!self::isAdminIt($user->role)) {
            if (!self::isManagerTeknik($user->role) && !self::isStaffAdministrasiGudang($user->role)) {
                return null;
            }

            $query->whereIn('for_role', self::roleAliases($user->role));
        }

        $count = $query->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTasks::route('/'),
            'edit' => EditTask::route('/{record}/edit'),
            'view' => ViewTask::route('/{record}'),
        ];
    }

    private static function roleAliases(string $role): array
    {
        $normalized = mb_strtolower(trim($role));

        return match ($normalized) {
            'staff administrasi gudang', 'staff adm gudang', 'staff gudang', 'admin gudang', 'administrator gudang' => [
                'Staff Administrasi Gudang',
                'Staff Adm Gudang',
                'Staff Gudang',
                'Admin Gudang',
                'Administrator Gudang',
            ],
            'manager operasional', 'manager operational', 'manajer operasional' => [
                'Manager Operasional',
                'Manager Operational',
                'Manajer Operasional',
            ],
            'finance', 'keuangan' => [
                'Finance',
                'Keuangan',
            ],
            'manager teknik', 'manajer teknik' => [
                'Manager Teknik',
                'Manajer Teknik',
            ],
            default => [$role],
        };
    }

    private static function isAdminIt(?string $role): bool
    {
        $normalized = mb_strtolower(trim((string) $role));

        return in_array($normalized, ['administrator it', 'admin it'], true);
    }

    private static function isManagerTeknik(?string $role): bool
    {
        $normalized = mb_strtolower(trim((string) $role));

        return in_array($normalized, ['manager teknik', 'manajer teknik'], true);
    }

    private static function isStaffAdministrasiGudang(?string $role): bool
    {
        $normalized = mb_strtolower(trim((string) $role));

        return in_array($normalized, [
            'staff administrasi gudang',
            'staff adm gudang',
            'staff gudang',
            'admin gudang',
            'administrator gudang',
        ], true);
    }
}