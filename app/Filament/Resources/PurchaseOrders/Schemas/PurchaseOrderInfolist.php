<?php

namespace App\Filament\Resources\PurchaseOrders\Schemas;

use App\Models\PurchaseOrder;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class PurchaseOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('po_number')
                    ->label('Nomor PO'),
                TextEntry::make('po_date')
                    ->label('Tanggal PO')
                    ->date('d M Y'),
                TextEntry::make('supplier.nama_supplier')
                    ->label('Supplier')
                    ->placeholder('-'),
                TextEntry::make('progress_status')
                    ->label('Status')
                    ->badge(),
                TextEntry::make('notes')
                    ->label('Catatan')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('items_material')
                    ->label('Barang PO')
                    ->state(function (PurchaseOrder $record): string {
                        $lines = $record->items
                            ->map(function ($item): string {
                                $name = (string) ($item->material?->nama_material ?? ('Material #' . $item->material_id));

                                return $name;
                            })
                            ->values()
                            ->all();

                        return empty($lines) ? '-' : implode("\n", $lines);
                    })
                    ->listWithLineBreaks()
                    ->columnSpanFull(),
                TextEntry::make('items_qty')
                    ->label('Qty Pesan / Terima')
                    ->state(function (PurchaseOrder $record): string {
                        $lines = $record->items
                            ->map(function ($item): string {
                                $ordered = number_format((float) $item->qty_order, 1, '.', '');
                                $received = number_format((float) ($item->qty_received ?? 0), 1, '.', '');
                                $unit = (string) ($item->unit_order ?: 'Unit');

                                return $received . ' / ' . $ordered . ' ' . $unit;
                            })
                            ->values()
                            ->all();

                        return empty($lines) ? '-' : implode("\n", $lines);
                    })
                    ->listWithLineBreaks()
                    ->columnSpanFull(),
            ]);
    }
}
