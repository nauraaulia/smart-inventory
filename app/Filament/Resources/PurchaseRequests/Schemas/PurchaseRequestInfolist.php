<?php

namespace App\Filament\Resources\PurchaseRequests\Schemas;

use App\Models\PurchaseRequest;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class PurchaseRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('project.kode_proyek')
                    ->label('Kode Proyek')
                    ->placeholder('-'),
                TextEntry::make('material.nama_material')
                    ->label('Material')
                    ->placeholder('-'),
                TextEntry::make('quantity')
                    ->label('Quantity')
                    ->numeric(decimalPlaces: 1),
                TextEntry::make('priority')
                    ->label('Prioritas')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'urgent' => 'CRITICAL',
                        'high' => 'HIGH',
                        'medium' => 'MEDIUM',
                        'low' => 'LOW',
                        default => strtoupper($state),
                    }),
                TextEntry::make('status')
                    ->badge(),
                TextEntry::make('requester.name')
                    ->label('Pembuat Draft')
                    ->placeholder('-'),
                TextEntry::make('draftApprover.id_custom')
                    ->label('Disetujui Admin Gudang Oleh')
                    ->formatStateUsing(function ($state, PurchaseRequest $record): string {
                        if (filled($state)) {
                            return (string) $state;
                        }

                        $id = (int) ($record->draft_approved_by ?? $record->requested_by ?? 0);

                        return $id > 0
                            ? 'JAU-' . str_pad((string) $id, 3, '0', STR_PAD_LEFT)
                            : '-';
                    })
                    ->placeholder('-'),
                TextEntry::make('draft_approved_at')
                    ->label('Waktu ACC Admin Gudang')
                    ->formatStateUsing(function ($state, PurchaseRequest $record): string {
                        $value = $state ?: $record->submitted_at;

                        if (blank($value)) {
                            return '-';
                        }

                        return \Illuminate\Support\Carbon::parse($value)->format('d M Y H:i');
                    })
                    ->placeholder('-'),
                TextEntry::make('managerApprover.id_custom')
                    ->label('Disetujui Manager Oleh')
                    ->formatStateUsing(function ($state, PurchaseRequest $record): string {
                        if (filled($state)) {
                            return (string) $state;
                        }

                        $id = (int) ($record->approved_by_manager ?? 0);

                        return $id > 0
                            ? 'JAU-' . str_pad((string) $id, 3, '0', STR_PAD_LEFT)
                            : '-';
                    })
                    ->placeholder('-'),
                TextEntry::make('approved_by_manager_at')
                    ->label('Waktu ACC Manager')
                    ->dateTime('d M Y H:i')
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->label('Draft Dibuat')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('submitted_at')
                    ->label('Draft Terkirim')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('reason')
                    ->label('Reason')
                    ->columnSpanFull()
                    ->placeholder('-'),
                TextEntry::make('rejection_reason')
                    ->label('Alasan Reject')
                    ->columnSpanFull()
                    ->placeholder('-'),
            ]);
    }
}
