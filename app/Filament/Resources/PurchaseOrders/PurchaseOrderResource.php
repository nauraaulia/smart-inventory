<?php

namespace App\Filament\Resources\PurchaseOrders;

use App\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Resources\PurchaseOrders\Pages\ReceivePurchaseOrders;
use App\Filament\Resources\PurchaseOrders\Pages\ReviewPurchaseOrders;
use App\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Schemas\PurchaseOrderInfolist;
use App\Filament\Resources\PurchaseOrders\Tables\PurchaseOrdersTable;
use App\Models\PurchaseOrder;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PurchaseOrderResource extends Resource
{
    protected static ?string $model = PurchaseOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Purchase Orders';

    protected static ?string $modelLabel = 'Purchase Order';

    protected static ?string $pluralModelLabel = 'Purchase Orders';

    public static function getNavigationGroup(): ?string
    {
        return auth()->user()?->role === 'Administrator IT' ? 'Gudang' : null;
    }

    public static function canViewAny(): bool
    {
        return self::canViewPo();
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
        return PurchaseOrdersTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'items:id,purchase_order_id,material_id,qty_order,qty_received,unit_order',
                'items.material:id,nama_material',
                'inbounds:id,purchase_order_id,entry_date,quantity',
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PurchaseOrderInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseOrders::route('/'),
            'review' => ReviewPurchaseOrders::route('/review'),
            'receive' => ReceivePurchaseOrders::route('/receive-by-po'),
            'view' => ViewPurchaseOrder::route('/{record}'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        if (!self::canViewPo()) {
            return null;
        }

        $count = PurchaseOrder::query()
            ->where('status', 'waiting_inbound')
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    private static function canViewPo(): bool
    {
        $role = auth()->user()?->role;

        return in_array($role, [
            'Manager Operasional',
            'Manager Operational',
            'Manajer Operasional',
            'Finance',
            'Keuangan',
            'Staff Administrasi Gudang',
            'Staff Adm Gudang',
            'Staff Gudang',
            'Administrator IT',
        ], true);
    }
}
