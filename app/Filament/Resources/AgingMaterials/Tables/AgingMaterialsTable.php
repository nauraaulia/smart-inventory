<?php

namespace App\Filament\Resources\AgingMaterials\Tables;

use App\Models\Task;
use App\Services\AI\TaskApprovalService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AgingMaterialsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->poll('2s')
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->formatStateUsing(fn ($state): string => 'Task #' . (string) $state)
                    ->sortable(),
                TextColumn::make('title')
                    ->label('Judul')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'done' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('total_material')
                    ->label('Total Material')
                    ->getStateUsing(fn (Task $record): int => collect(data_get($record->payload, 'deadstock_items', []))->count()),
                TextColumn::make('total_qty_keep')
                    ->label('Total Qty Keep')
                    ->getStateUsing(function (Task $record): string {
                        $total = (float) collect(data_get($record->payload, 'deadstock_items', []))
                            ->sum(fn (array $row): float => (float) ($row['qty_kept'] ?? ($row['available_qty'] ?? 0)));

                        return number_format($total, 2, '.', '');
                    }),
                TextColumn::make('total_qty_disposed')
                    ->label('Total Qty Buang/Jual/Return')
                    ->getStateUsing(function (Task $record): string {
                        $total = (float) collect(data_get($record->payload, 'deadstock_items', []))
                            ->sum(fn (array $row): float => (float) ($row['qty_disposed'] ?? 0));

                        return number_format($total, 2, '.', '');
                    }),
                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('proses')
                    ->label('Proses Aging')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Task $record): bool => $record->status === 'pending' && (string) data_get($record->payload, 'workflow_state', '') === 'approved_for_aging' && self::canHandle())
                    ->form(function (Task $record): array {
                        $rows = collect(data_get($record->payload, 'deadstock_items', []))
                            ->map(function (array $row): array {
                                $rekomendasi = (string) ($row['rekomendasi'] ?? 'monitor');
                                $availableQty = round((float) ($row['available_qty'] ?? 0), 2);
                                $defaultDisposed = $rekomendasi === 'monitor' ? 0.0 : $availableQty;
                                $defaultKept = round(max(0, $availableQty - $defaultDisposed), 2);
                                $legacyDecision = (string) ($row['manager_decision'] ?? '');

                                $qtyBuang = (float) ($row['qty_buang'] ?? 0);
                                $qtyJualMurah = (float) ($row['qty_jual_murah'] ?? 0);
                                $qtyReturn = (float) ($row['qty_return_distributor'] ?? 0);

                                if (($qtyBuang + $qtyJualMurah + $qtyReturn) <= 0.00001 && (float) ($row['qty_disposed'] ?? 0) > 0) {
                                    $legacyDisposed = (float) $row['qty_disposed'];
                                    if ($legacyDecision === 'buang') {
                                        $qtyBuang = $legacyDisposed;
                                    } elseif ($legacyDecision === 'jual_murah' || $legacyDecision === 'clearance') {
                                        $qtyJualMurah = $legacyDisposed;
                                    } elseif ($legacyDecision === 'return_distributor') {
                                        $qtyReturn = $legacyDisposed;
                                    }
                                }

                                return [
                                    'material_id' => (int) ($row['material_id'] ?? 0),
                                    'material_name' => (string) ($row['material_name'] ?? '-'),
                                    'available_qty' => $availableQty,
                                    'satuan' => (string) ($row['satuan'] ?? 'unit'),
                                    'last_used_days' => (int) ($row['last_used_days'] ?? 0),
                                    'prioritas' => (string) ($row['prioritas'] ?? 'sedang'),
                                    'alasan' => (string) ($row['alasan'] ?? '-'),
                                    'rekomendasi' => $rekomendasi,
                                    'qty_kept' => (float) ($row['qty_kept'] ?? $defaultKept),
                                    'qty_buang' => round($qtyBuang, 2),
                                    'qty_jual_murah' => round($qtyJualMurah, 2),
                                    'qty_return_distributor' => round($qtyReturn, 2),
                                ];
                            })
                            ->values()
                            ->all();

                        return [
                            Repeater::make('deadstock_decisions')
                                ->label('Keputusan Status Aging Material')
                                ->schema([
                                    Hidden::make('material_id')->required(),
                                    TextInput::make('material_name')
                                        ->label('Material')
                                        ->disabled()
                                        ->dehydrated(false),
                                    TextInput::make('available_qty')
                                        ->label('Stok')
                                        ->suffix(fn ($get): string => (string) ($get('satuan') ?: 'unit'))
                                        ->disabled()
                                        ->dehydrated(false),
                                    TextInput::make('last_used_days')
                                        ->label('Tidak Terpakai (hari)')
                                        ->disabled()
                                        ->dehydrated(false),
                                    TextInput::make('rekomendasi')
                                        ->label('Saran Gemini')
                                        ->disabled()
                                        ->dehydrated(false),
                                    Textarea::make('alasan')
                                        ->label('Alasan')
                                        ->rows(2)
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->columnSpanFull(),
                                    TextInput::make('qty_kept')
                                        ->label('Disimpan / Monitor')
                                        ->numeric()
                                        ->minValue(0)
                                        ->maxValue(fn ($get): float => (float) ($get('available_qty') ?? 0))
                                        ->required()
                                        ->suffix(fn ($get): string => (string) ($get('satuan') ?: 'unit')),
                                    TextInput::make('qty_buang')
                                        ->label('Buang')
                                        ->numeric()
                                        ->minValue(0)
                                        ->maxValue(fn ($get): float => (float) ($get('available_qty') ?? 0))
                                        ->required()
                                        ->suffix(fn ($get): string => (string) ($get('satuan') ?: 'unit')),
                                    TextInput::make('qty_jual_murah')
                                        ->label('Jual Murah')
                                        ->numeric()
                                        ->minValue(0)
                                        ->maxValue(fn ($get): float => (float) ($get('available_qty') ?? 0))
                                        ->required()
                                        ->suffix(fn ($get): string => (string) ($get('satuan') ?: 'unit')),
                                    TextInput::make('qty_return_distributor')
                                        ->label('Kembalikan ke Supplier')
                                        ->numeric()
                                        ->minValue(0)
                                        ->maxValue(fn ($get): float => (float) ($get('available_qty') ?? 0))
                                        ->required()
                                        ->suffix(fn ($get): string => (string) ($get('satuan') ?: 'unit')),
                                ])
                                ->columns(3)
                                ->helperText('Isi qty per status. Total Disimpan + Buang + Jual + Return harus sama dengan Stok.')
                                ->default($rows)
                                ->addable(false)
                                ->deletable(false)
                                ->reorderable(false)
                                ->minItems(1),
                        ];
                    })
                    ->action(function (Task $record, array $data): void {
                        (new TaskApprovalService())->finalizeDeadstockAging($record, auth()->user(), [
                            'deadstock_decisions' => $data['deadstock_decisions'] ?? [],
                        ]);
                    }),
            ]);
    }

    private static function canHandle(): bool
    {
        return in_array(auth()->user()?->role, ['Manager Operasional', 'Manager Operational', 'Manajer Operasional'], true);
    }
}
