<?php

namespace App\Filament\Resources\Tasks\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class TaskInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('for_role'),
                TextEntry::make('type'),
                TextEntry::make('title'),
                TextEntry::make('message')
                    ->columnSpanFull(),
                TextEntry::make('status'),
                TextEntry::make('related_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('related_type')
                    ->placeholder('-'),
                TextEntry::make('handled_by')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('handled_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('payload.approval_trail.staff.status')
                    ->label('ACC Staff')
                    ->placeholder('-'),
                TextEntry::make('payload.execution_result.allocated_total_qty')
                    ->label('Qty Berhasil Dialokasikan')
                    ->visible(fn ($record) => (string) data_get($record, 'type', '') === 'ai_inbound_allocation_suggestion')
                    ->formatStateUsing(fn ($state) => is_null($state) ? '-' : rtrim(rtrim(number_format((float) $state, 2, '.', ''), '0'), '.'))
                    ->placeholder('-'),
                TextEntry::make('payload.execution_result.returned_to_stock_qty')
                    ->label('Qty Kembali ke Stok Available')
                    ->visible(fn ($record) => (string) data_get($record, 'type', '') === 'ai_inbound_allocation_suggestion')
                    ->formatStateUsing(fn ($state) => is_null($state) ? '-' : rtrim(rtrim(number_format((float) $state, 2, '.', ''), '0'), '.'))
                    ->placeholder('-'),
                TextEntry::make('payload.execution_result.selected_piece_ids')
                    ->label('Piece yang Dipakai')
                    ->visible(fn ($record) => (string) data_get($record, 'type', '') === 'ai_inbound_allocation_suggestion')
                    ->formatStateUsing(function ($state): string {
                        if (!is_array($state) || empty($state)) {
                            return '-';
                        }

                        return collect($state)
                            ->map(fn ($id): string => 'Piece #' . (int) $id)
                            ->implode(', ');
                    })
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('payload.execution_result.allocated_rows')
                    ->label('Rincian Alokasi per Proyek')
                    ->visible(fn ($record) => (string) data_get($record, 'type', '') === 'ai_inbound_allocation_suggestion')
                    ->formatStateUsing(function ($state): string {
                        if (!is_array($state) || empty($state)) {
                            return '-';
                        }

                        return collect($state)
                            ->map(function ($row): string {
                                $code = (string) data_get($row, 'project_code', '#-');
                                $qty = rtrim(rtrim(number_format((float) data_get($row, 'allocated_qty', 0), 2, '.', ''), '0'), '.');

                                return $code . ': ' . $qty . ' unit';
                            })
                            ->implode("\n");
                    })
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('payload.rejection.reason')
                    ->label('Alasan Reject')
                    ->visible(fn ($record) => filled(data_get($record, 'payload.rejection.reason')))
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
