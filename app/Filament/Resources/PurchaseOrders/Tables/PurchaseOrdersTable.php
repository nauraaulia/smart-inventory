<?php

namespace App\Filament\Resources\PurchaseOrders\Tables;

use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PurchaseOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->poll('3s')
            ->columns([
                TextColumn::make('po_number')
                    ->label('Nomor PO')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('po_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('supplier.nama_supplier')
                    ->label('Supplier')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('progress_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Waiting Inbound' => 'warning',
                        'Partial' => 'info',
                        'Completed' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('items_count')
                    ->label('Jumlah Item')
                    ->counts('items'),
                TextColumn::make('progress_qty')
                    ->label('Progress Terima')
                    ->getStateUsing(function (PurchaseOrder $record): string {
                        $ordered = (float) $record->items->sum(fn ($item) => (float) $item->qty_order);
                        $received = (float) $record->items->sum(fn ($item) => (float) ($item->qty_received ?? 0));
                        $units = $record->items
                            ->pluck('unit_order')
                            ->filter()
                            ->unique()
                            ->values();

                        $unit = $units->count() === 1 ? (string) $units->first() : 'Unit';

                        return number_format($received, 1, '.', '') . ' / ' . number_format($ordered, 1, '.', '') . ' ' . $unit;
                    }),
                TextColumn::make('received_at')
                    ->label('Tanggal Terima')
                    ->dateTime('d M Y H:i')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'waiting_inbound' => 'Waiting Inbound',
                        'completed' => 'Completed',
                    ]),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ViewAction::make(),
                Action::make('print_pdf')
                    ->label('Cetak PDF')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->visible(fn (): bool => self::canPrint())
                    ->url(fn (PurchaseOrder $record): string => route('po.print', ['purchaseOrder' => $record->id]))
                    ->openUrlInNewTab(),
                Action::make('receive_po')
                    ->label('Terima PO')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('danger')
                    ->visible(fn (PurchaseOrder $record): bool => $record->status === 'waiting_inbound' && self::canReceive())
                    ->url(fn (PurchaseOrder $record): string => PurchaseOrderResource::getUrl('receive', ['po_number' => $record->po_number])),
            ]);
    }

    private static function canReceive(): bool
    {
        return in_array(auth()->user()?->role, [
            'Staff Administrasi Gudang',
            'Staff Adm Gudang',
        ], true);
    }

    private static function canPrint(): bool
    {
        return in_array(auth()->user()?->role, [
            'Manager Operasional',
            'Manager Operasional',
            'Manager Operational',
            'Manajer Operasional',
            'Finance',
            'Keuangan',
            'Finance',
            'Keuangan',
        ], true);
    }
}
