<?php

namespace App\Filament\Resources\ProjectTemplates;

use App\Filament\Resources\ProjectTemplates\Pages\CreateProjectTemplate;
use App\Filament\Resources\ProjectTemplates\Pages\EditProjectTemplate;
use App\Filament\Resources\ProjectTemplates\Pages\ListProjectTemplates;
use App\Filament\Resources\ProjectTemplates\Schemas\ProjectTemplateForm; // ✅ Import form baru
use App\Filament\Resources\ProjectTemplates\Tables\ProjectTemplatesTable;
use App\Models\ProjectTemplate;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Filament\Schemas\Schema;

class ProjectTemplateResource extends Resource
{
    protected static ?string $model = ProjectTemplate::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static string|\UnitEnum|null $navigationGroup = 'Kelola Data Master';

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
        return !in_array(auth()->user()->role, ['Finance', 'Staff Administrasi Gudang']);
    }

    public static function canCreate(): bool
    {
        return auth()->user()->role === 'Administrator IT';
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()->role === 'Administrator IT';
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()->role === 'Administrator IT';
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