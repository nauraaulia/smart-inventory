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

    protected static ?string $navigationLabel = 'Tasks AI';

    protected static ?string $modelLabel = 'Task';

    protected static ?string $pluralModelLabel = 'Tasks';

    public static function form(Schema $schema): Schema
    {
        return TaskForm::configure($schema);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        $user = auth()->user();

        if (!$user || !$record instanceof Task) {
            return false;
        }

        if ($user->role === 'Administrator IT') {
            return true;
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

        if ($user->role === 'Administrator IT') {
            return $query;
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

        if ($user->role !== 'Administrator IT') {
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
            'staff administrasi gudang', 'staff adm gudang', 'staff gudang' => [
                'Staff Administrasi Gudang',
                'Staff Adm Gudang',
                'Staff Gudang',
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
            default => [$role],
        };
    }
}