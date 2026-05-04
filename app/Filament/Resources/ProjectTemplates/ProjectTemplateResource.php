<?php

namespace App\Filament\Resources\ProjectTemplates;

use App\Filament\Resources\ProjectTemplates\Pages\CreateProjectTemplate;
use App\Filament\Resources\ProjectTemplates\Pages\EditProjectTemplate;
use App\Filament\Resources\ProjectTemplates\Pages\ListProjectTemplates;
use App\Filament\Resources\ProjectTemplates\Schemas\ProjectTemplateForm; // ✅ Import form baru
use App\Filament\Resources\ProjectTemplates\Tables\ProjectTemplatesTable;
use App\Models\ProjectTemplate;
use App\Models\User;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Filament\Schemas\Schema;

class ProjectTemplateResource extends Resource
{
    protected static ?string $model = ProjectTemplate::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-duplicate';
    protected static string|\UnitEnum|null $navigationGroup = 'Data Master';

    public static function getNavigationGroup(): ?string
    {
        return User::isGeneralManagerRole(auth()->user()?->role) ? 'Gudang' : 'Data Master';
    }

    // ✅ Pakai form baru dari ProjectTemplateForm
    public static function form(Schema $schema): Schema
    {
        return ProjectTemplateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProjectTemplatesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function canViewAny(): bool
    {
        $role = auth()->user()?->role;

        if (!$role) {
            return false;
        }

        return !in_array($role, ['Finance', 'Keuangan'], true);
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->role === 'Manager Teknik';
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->role === 'Manager Teknik';
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProjectTemplates::route('/'),
            'create' => CreateProjectTemplate::route('/create'),
            'edit' => EditProjectTemplate::route('/{record}/edit'),
        ];
    }
}
