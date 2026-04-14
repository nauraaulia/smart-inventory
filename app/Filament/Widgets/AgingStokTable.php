<?php

namespace App\Filament\Widgets;

use App\Models\MaterialPiece;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class AgingStokTable extends TableWidget
{
    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->role === 'Staff Administrasi Gudang';
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Aging Stok (>= 90 hari)')
            ->query($this->getTableQuery())
            ->columns([
                TextColumn::make('material.nama_material')
                    ->label('Nama Material')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('panjang')
                    ->label('Panjang (m)')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
                TextColumn::make('shelfLocation.kode_lokasi')
                    ->label('Rack')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('umur_hari')
                    ->label('Umur (hari)')
                    ->getStateUsing(fn (MaterialPiece $record): int => now()->diffInDays($record->created_at))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('created_at', $direction)),
                TextColumn::make('warning_status')
                    ->label('Status Warning')
                    ->getStateUsing(function (MaterialPiece $record): string {
                        $age = now()->diffInDays($record->created_at);

                        if ($age > 90) {
                            return 'Merah (>90 hari)';
                        }

                        if ($age > 60) {
                            return 'Kuning (>60 hari)';
                        }

                        return 'Normal';
                    })
                    ->badge()
                    ->color(function (string $state): string {
                        return match ($state) {
                            'Merah (>90 hari)' => 'danger',
                            'Kuning (>60 hari)' => 'warning',
                            default => 'gray',
                        };
                    }),
            ])
            ->defaultSort('created_at', 'asc')
            ->paginated([10, 25, 50]);
    }

    protected function getTableQuery(): Builder
    {
        return MaterialPiece::query()
            ->with(['material:id,nama_material', 'shelfLocation:id,kode_lokasi'])
            ->where('status', 'available')
            ->where('created_at', '<=', now()->subDays(90));
    }
}
