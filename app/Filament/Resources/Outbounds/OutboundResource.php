<?php

namespace App\Filament\Resources\Outbounds;

use App\Filament\Resources\Outbounds\Pages\ListOutbounds;
use App\Filament\Resources\Outbounds\Pages\ViewOutbound;
use App\Filament\Resources\Outbounds\Tables\OutboundsTable;
use App\Models\Outbound;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OutboundResource extends Resource
{
    protected static ?string $model = Outbound::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $navigationLabel = 'Outbound';

    protected static ?string $modelLabel = 'Outbound';

    protected static ?string $pluralModelLabel = 'Outbound';

    protected static ?int $navigationSort = 6;

    public static function getNavigationGroup(): ?string
    {
        $role = auth()->user()?->role;

        return $role === 'Administrator IT' || User::isGeneralManagerRole($role) ? 'Gudang' : null;
    }

    public static function canViewAny(): bool
    {
        return self::canAccessOutbound(auth()->user()?->role);
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

    public static function table(Table $table): Table
    {
        return OutboundsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        if (!self::canAccessOutbound(auth()->user()?->role)) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()
            ->with([
                'project:id,kode_proyek,nama_proyek,tanggal_mulai,status',
                'items:id,outbound_id,material_id,qty,unit,selected_piece_ids',
                'items.material:id,nama_material,satuan',
            ])
            ->withCount('items');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOutbounds::route('/'),
            'view' => ViewOutbound::route('/{record}'),
        ];
    }

    private static function canAccessOutbound(?string $role): bool
    {
        return in_array($role, [
            'Manager Teknik',
            'Manajer Teknik',
            'Staff Administrasi Gudang',
            'Staff Adm Gudang',
            'Staff Gudang',
            'Administrator IT',
            User::ROLE_GENERAL_MANAGER,
        ], true);
    }
}
