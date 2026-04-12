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
                        'approved' => 'info',
                        'done' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
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
                    ->visible(fn (Task $record): bool => $record->status === 'approved' && self::canHandle())
                    ->form(function (Task $record): array {
                        $rows = collect(data_get($record->payload, 'deadstock_items', []))
                            ->map(function (array $row): array {
                                $rekomendasi = (string) ($row['rekomendasi'] ?? 'monitor');
                                $availableQty = round((float) ($row['available_qty'] ?? 0), 2);
                                $defaultDisposed = $rekomendasi === 'monitor' ? 0.0 : $availableQty;
                                $defaultKept = round(max(0, $availableQty - $defaultDisposed), 2);

                                return [
                                    'material_id' => (int) ($row['material_id'] ?? 0),
                                    'material_name' => (string) ($row['material_name'] ?? '-'),
                                    'available_qty' => $availableQty,
                                    'satuan' => (string) ($row['satuan'] ?? 'unit'),
                                    'last_used_days' => (int) ($row['last_used_days'] ?? 0),
                                    'prioritas' => (string) ($row['prioritas'] ?? 'sedang'),
                                    'alasan' => (string) ($row['alasan'] ?? '-'),
                                    'rekomendasi' => $rekomendasi,
                                    'manager_decision' => (string) ($row['manager_decision'] ?? $rekomendasi),
                                    'qty_kept' => (float) ($row['qty_kept'] ?? $defaultKept),
                                    'qty_disposed' => (float) ($row['qty_disposed'] ?? $defaultDisposed),
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
                                    Select::make('manager_decision')
                                        ->label('Status Aging Final')
                                        ->options([
                                            'monitor' => 'Disimpan / Monitor',
                                            'jual_murah' => 'Jual Murah',
                                            'clearance' => 'Clearance (Hapus Stok)',
                                            'return_distributor' => 'Kembalikan ke Supplier',
                                            'buang' => 'Buang / Deadstock',
                                        ])
                                        ->required(),
                                    TextInput::make('qty_kept')
                                        ->label('Qty Keep')
                                        ->numeric()
                                        ->minValue(0)
                                        ->required()
                                        ->suffix(fn ($get): string => (string) ($get('satuan') ?: 'unit')),
                                    TextInput::make('qty_disposed')
                                        ->label('Qty Buang/Jual/Return')
                                        ->numeric()
                                        ->minValue(0)
                                        ->required()
                                        ->suffix(fn ($get): string => (string) ($get('satuan') ?: 'unit')),
                                ])
                                ->columns(3)
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
