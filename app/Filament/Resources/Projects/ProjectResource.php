<?php

namespace App\Filament\Resources\Projects;

use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Schemas\ProjectForm;
use App\Filament\Resources\Projects\Tables\ProjectsTable;
use App\Filament\Widgets\ProjectStatsWidget;
use App\Models\Project;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-folder';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'Project';

    public static function getNavigationGroup(): ?string
    {
        return User::isGeneralManagerRole(auth()->user()?->role) ? 'Gudang' : null;
    }

    private static function canManageProject(?string $role): bool
    {
        return $role === 'Manager Teknik';
    }

    private static function canViewProjectModule(?string $role): bool
    {
        return in_array($role, [
            'Manager Teknik',
            'Manager Operasional',
            'Finance',
            'Staff Administrasi Gudang',
            'Staff Adm Gudang',
            'Staff Gudang',
            User::ROLE_GENERAL_MANAGER,
        ], true);
    }

    public static function form(Schema $schema): Schema
    {
        return ProjectForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProjectsTable::configure($table);
    }

    // INI YANG DITAMBAHKAN — Stats widget di atas tabel
    public static function getHeaderWidgets(): array
    {
        return [
            ProjectStatsWidget::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        if (!$user || !self::canViewProjectModule($user->role)) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()->withCount([
            'purchaseRequests as pr_draft_count' => fn (Builder $query) => $query->where('status', 'draft'),
            'purchaseRequests as pr_rejected_count' => fn (Builder $query) => $query->where('status', 'rejected'),
            'purchaseRequests as pr_pending_manager_count' => fn (Builder $query) => $query->where('status', 'pending_manager'),
            'purchaseRequests as pr_pending_finance_count' => fn (Builder $query) => $query->where('status', 'pending_finance'),
        ]);
    }

    public static function canViewAny(): bool
    {
        return self::canViewProjectModule(auth()->user()?->role);
    }

    public static function canCreate(): bool
    {
        return self::canManageProject(auth()->user()?->role);
    }

    public static function canEdit($record): bool
    {
        return self::canManageProject(auth()->user()?->role);
    }

    public static function canDelete($record): bool
    {
        return self::canManageProject(auth()->user()?->role);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProjects::route('/'),
            'create' => CreateProject::route('/create'),
            'edit' => EditProject::route('/{record}/edit'),
        ];
    }
}
