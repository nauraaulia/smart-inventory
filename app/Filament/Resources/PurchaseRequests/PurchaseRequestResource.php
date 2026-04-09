<?php

namespace App\Filament\Resources\PurchaseRequests;

use App\Filament\Resources\PurchaseRequests\Pages\CreatePurchaseRequest;
use App\Filament\Resources\PurchaseRequests\Pages\EditPurchaseRequest;
use App\Filament\Resources\PurchaseRequests\Pages\ListPurchaseRequests;
use App\Filament\Resources\PurchaseRequests\Pages\ViewPurchaseRequest;
use App\Filament\Resources\PurchaseRequests\Schemas\PurchaseRequestForm;
use App\Filament\Resources\PurchaseRequests\Schemas\PurchaseRequestInfolist;
use App\Filament\Resources\PurchaseRequests\Tables\PurchaseRequestsTable;
use App\Models\PurchaseRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PurchaseRequestResource extends Resource
{
    protected static ?string $model = PurchaseRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Purchase Request';

    protected static ?string $modelLabel = 'Purchase Request';

    protected static ?string $pluralModelLabel = 'Purchase Requests';

    public static function getNavigationGroup(): ?string
    {
        return auth()->user()?->role === 'Administrator IT' ? 'Gudang' : null;
    }

    public static function form(Schema $schema): Schema
    {
        return PurchaseRequestForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PurchaseRequestsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PurchaseRequestInfolist::configure($schema);
    }

    private static function isGudangStaff(?string $role): bool
    {
        return in_array($role, ['Staff Administrasi Gudang', 'Staff Adm Gudang', 'Staff Gudang'], true);
    }

    private static function isManager(?string $role): bool
    {
        return in_array($role, ['Manager Operasional', 'Manager Operational', 'Manajer Operasional'], true);
    }

    private static function isFinance(?string $role): bool
    {
        return in_array($role, ['Finance', 'Keuangan'], true);
    }

    private static function isAdminIt(?string $role): bool
    {
        return $role === 'Administrator IT';
    }

    public static function canViewAny(): bool
    {
        $role = auth()->user()?->role;

        return self::isGudangStaff($role) || self::isManager($role) || self::isFinance($role) || self::isAdminIt($role);
    }

    public static function canCreate(): bool
    {
        return self::isGudangStaff(auth()->user()?->role);
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        $user = auth()->user();

        if (!$user || !$record instanceof PurchaseRequest) {
            return false;
        }

        return self::isGudangStaff($user->role)
            && in_array($record->status, ['draft', 'rejected'], true)
            && (int) $record->requested_by === (int) $user->id;
    }

    public static function canCancelSubmission($record): bool
    {
        $user = auth()->user();

        if (!$user || !$record instanceof PurchaseRequest) {
            return false;
        }

        if ($record->status !== 'pending_manager') {
            return false;
        }

        return self::isGudangStaff($user->role)
            && (int) $record->requested_by === (int) $user->id;
    }

    public static function canCancelSubmissionToFinanceByManager($record): bool
    {
        $user = auth()->user();

        if (!$user || !$record instanceof PurchaseRequest) {
            return false;
        }

        return self::isManager($user->role)
            && $record->status === 'pending_finance';
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with([
            'project',
            'material.defaultSupplier',
            'requester',
            'draftApprover',
            'managerApprover',
            'financeApprover',
        ]);
        $user = auth()->user();

        if (!$user) {
            return $query->whereRaw('1 = 0');
        }

        if (self::isGudangStaff($user->role)) {
            return $query;
        }

        if (self::isAdminIt($user->role)) {
            return $query;
        }

        if (self::isManager($user->role)) {
            return $query->where('status', 'approved');
        }

        if (self::isFinance($user->role)) {
            return $query->whereIn('status', ['pending_finance', 'approved', 'ordered', 'received']);
        }

        return $query->whereRaw('1 = 0');
    }

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();

        if (!$user) {
            return null;
        }

        $query = PurchaseRequest::query();

        if (self::isGudangStaff($user->role)) {
            $query->whereIn('status', ['draft', 'rejected'])
                ->where('requested_by', $user->id);
        } elseif (self::isManager($user->role)) {
            $query->where('status', 'pending_manager');
        } elseif (self::isFinance($user->role)) {
            $query->where('status', 'pending_finance');
        } elseif (self::isAdminIt($user->role)) {
            $query->whereIn('status', ['draft', 'rejected', 'pending_manager', 'pending_finance']);
        } else {
            return null;
        }

        $count = $query->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseRequests::route('/'),
            'create' => CreatePurchaseRequest::route('/create'),
            'edit' => EditPurchaseRequest::route('/{record}/edit'),
            'view' => ViewPurchaseRequest::route('/{record}'),
        ];
    }
}
