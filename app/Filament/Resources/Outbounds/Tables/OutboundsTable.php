<?php

namespace App\Filament\Resources\Outbounds\Tables;

use App\Filament\Resources\Outbounds\OutboundResource;
use App\Models\Outbound;
use Filament\Actions\Action;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OutboundsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('project.kode_proyek')
                    ->label('Kode Proyek')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('project.nama_proyek')
                    ->label('Nama Proyek')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('status')
                    ->label('Status Outbound')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'Menunggu',
                        'ready' => 'Siap',
                        'taken' => 'Sudah Diambil',
                        default => ucfirst($state),
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'ready' => 'info',
                        'taken' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('items_count')
                    ->label('Item Outbound')
                    ->badge()
                    ->color('warning')
                    ->counts('items'),
                TextColumn::make('project.tanggal_mulai')
                    ->label('Mulai')
                    ->date('d M Y')
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('outbound_manual')
                    ->label('Tipe')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Manual' : 'Booking')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'gray' : 'primary'),
                TextColumn::make('taken_at')
                    ->label('Waktu Diambil')
                    ->dateTime('d M Y H:i')
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Menunggu',
                        'ready' => 'Siap',
                        'taken' => 'Sudah Diambil',
                    ]),
            ])
            ->defaultSort('updated_at', 'desc')
            ->recordActions([
                Action::make('process_outbound')
                    ->label('Buka Outbound')
                    ->icon('heroicon-o-truck')
                    ->color(fn (Outbound $record): string => match ($record->status) {
                        'taken' => 'success',
                        'ready' => 'info',
                        default => 'warning',
                    })
                    ->url(fn (Outbound $record): string => OutboundResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
