<?php

namespace App\Filament\Resources\Projects\Tables;

use App\Filament\Resources\Outbounds\Tables\OutboundsTable;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Models\Project;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->poll('2s')
            ->columns([
                TextColumn::make('nama_proyek')
                    ->searchable(),
                TextColumn::make('kode_proyek')
                    ->searchable(),
                TextColumn::make('tanggal_mulai')
                    ->date()
                    ->sortable(),
                TextColumn::make('tanggal_selesai')
                    ->date()
                    ->sortable(),
                TextColumn::make('progress_label')
                    ->label('Progress Operasional')
                    ->badge()
                    ->color(fn (Project $record): string => $record->progress_color)
                    ->description(fn (Project $record): string => $record->progress_description),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'Pending' => 'Pending',
                        'Approved' => 'Approved',
                        'Processing' => 'Processing',
                        'Completed' => 'Completed',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Pending' => 'gray',
                        'Approved' => 'info',
                        'Processing' => 'warning',
                        'Completed' => 'success',
                        default => 'gray',
                    })
                    ->searchable(),
                TextColumn::make('outbound_confirmed_at')
                    ->label('Outbound Confirmed')
                    ->dateTime('d M Y H:i')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('view_requirements')
                    ->label('Lihat Kebutuhan')
                    ->icon('heroicon-o-list-bullet')
                    ->color('gray')
                    ->modalHeading(fn (Project $record): string => 'Kebutuhan Project ' . ($record->kode_proyek ?: ('#' . $record->id)))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->modalWidth('5xl')
                    ->modalContent(fn (Project $record) => OutboundsTable::requirementsModalContent($record)),
                Action::make('confirm_outbound')
                    ->label('Konfirmasi Outbound')
                    ->icon('heroicon-o-truck')
                    ->color('warning')
                    ->modalWidth('6xl')
                    ->modalHeading('Outbound Checklist')
                    ->modalDescription('Checklist fisik pengambilan barang dari rak sebelum transaksi outbound diselesaikan.')
                    ->modalContent(function (Project $record): HtmlString {
                        $now = now()->translatedFormat('d F Y');
                        $projectName = e($record->nama_proyek);

                        return new HtmlString(<<<HTML
<div class="outbound-modal-shell rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-slate-900">
    <div class="mb-4 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-slate-800">
        <div class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-300">Header</div>
        <div class="mt-3 grid gap-3 md:grid-cols-2">
            <div class="rounded-lg border border-slate-200 bg-white p-3 dark:border-white/10 dark:bg-slate-900">
                <div class="text-xs font-medium text-slate-500 dark:text-slate-300">Nama Proyek</div>
                <div class="mt-1 text-sm font-semibold text-slate-900 dark:text-white">{$projectName}</div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-3 dark:border-white/10 dark:bg-slate-900">
                <div class="text-xs font-medium text-slate-500 dark:text-slate-300">Tanggal</div>
                <div class="mt-1 text-sm font-semibold text-slate-900 dark:text-white">{$now}</div>
            </div>
        </div>
    </div>
    <div class="mb-3 grid grid-cols-[1.2fr_1.6fr_0.9fr_1fr_0.6fr] gap-3 rounded-lg border border-slate-200 bg-slate-100 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-600 dark:border-white/10 dark:bg-slate-800 dark:text-slate-300">
        <span>Lokasi Rak</span>
        <span>Nama Barang</span>
        <span>Kuantitas</span>
        <span>Tipe Barang</span>
        <span class="text-center">Aksi</span>
    </div>
</div>
<style>
    .outbound-checklist .fi-fo-field-wrp-label,
    .outbound-checklist .fi-fo-field-wrp-description {
        display: none;
    }

    .outbound-checklist .fi-fo-checkbox-list-options {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .outbound-checklist .fi-fo-checkbox-list-option {
        display: grid;
        grid-template-columns: 1.2fr 1.6fr 0.9fr 1fr 0.6fr;
        align-items: center;
        gap: 0.75rem;
        border: 1px solid rgb(226 232 240);
        border-radius: 0.75rem;
        background-color: rgb(255 255 255);
        padding: 0.875rem 1rem;
        transition: all 200ms ease;
    }

    .outbound-checklist .fi-fo-checkbox-list-option:hover {
        border-color: rgb(148 163 184);
        background-color: rgb(248 250 252);
    }

    .dark .outbound-checklist .fi-fo-checkbox-list-option {
        border-color: rgb(51 65 85);
        background-color: rgb(15 23 42);
    }

    .dark .outbound-checklist .fi-fo-checkbox-list-option:hover {
        border-color: rgb(100 116 139);
        background-color: rgb(30 41 59);
    }

    .dark .outbound-checklist .fi-fo-checkbox-list-option-label {
        color: rgb(226 232 240);
    }

    .outbound-checklist .fi-fo-checkbox-list-option:has(input:checked) {
        opacity: 0.48;
    }

    .outbound-checklist .fi-fo-checkbox-list-option > input {
        grid-column: 5;
        justify-self: center;
    }

    .outbound-checklist .fi-fo-checkbox-list-option-text {
        grid-column: 1 / 5;
        display: grid;
        grid-template-columns: 1.2fr 1.6fr 0.9fr 1fr;
        gap: 0.75rem;
        align-items: center;
    }

    .outbound-checklist .outbound-qty {
        display: inline-flex;
        align-items: center;
        width: fit-content;
        border-radius: 9999px;
        border: 1px solid rgb(191 219 254);
        background-color: rgb(239 246 255);
        color: rgb(30 64 175);
        font-size: 0.75rem;
        font-weight: 700;
        padding: 0.125rem 0.625rem;
    }

    .dark .outbound-checklist .outbound-qty {
        border-color: rgb(59 130 246 / 0.5);
        background-color: rgb(59 130 246 / 0.15);
        color: rgb(191 219 254);
    }

    .outbound-checklist .outbound-type {
        width: fit-content;
        border-radius: 9999px;
        font-size: 0.75rem;
        font-weight: 600;
        padding: 0.125rem 0.625rem;
    }

    .outbound-checklist .outbound-type.is-potongan {
        background-color: rgb(254 249 195);
        color: rgb(133 77 14);
        border: 1px solid rgb(253 224 71);
    }

    .dark .outbound-checklist .outbound-type.is-potongan {
        background-color: rgb(245 158 11 / 0.15);
        color: rgb(253 230 138);
        border-color: rgb(251 191 36 / 0.5);
    }

    .outbound-checklist .outbound-type.is-utuh {
        background-color: rgb(220 252 231);
        color: rgb(22 101 52);
        border: 1px solid rgb(134 239 172);
    }

    .dark .outbound-checklist .outbound-type.is-utuh {
        background-color: rgb(16 185 129 / 0.15);
        color: rgb(167 243 208);
        border-color: rgb(52 211 153 / 0.5);
    }

    @media (max-width: 768px) {
        .outbound-modal-shell > .mb-3 {
            display: none;
        }

        .outbound-checklist .fi-fo-checkbox-list-option {
            grid-template-columns: 1fr auto;
            align-items: start;
        }

        .outbound-checklist .fi-fo-checkbox-list-option-text {
            grid-column: 1;
            grid-template-columns: 1fr;
            gap: 0.35rem;
        }

        .outbound-checklist .fi-fo-checkbox-list-option > input {
            grid-column: 2;
            margin-top: 0.25rem;
        }
    }
</style>
HTML);
                    })
                    ->schema(function (Project $record): array {
                        $pieces = $record->bookedPieces()
                            ->with(['material:id,nama_material,satuan', 'shelfLocation:id,kode_lokasi,nama_lokasi'])
                            ->where('status', 'booked')
                            ->orderBy('shelf_location_id')
                            ->orderBy('material_id')
                            ->get();

                        $options = [];
                        $pieceIds = [];
                        $pieceCountByMaterial = $pieces->countBy(fn ($piece) => (int) $piece->material_id);

                        foreach ($pieces as $piece) {
                            $materialName = e($piece->material?->nama_material ?? 'Material tidak diketahui');
                            $rackName = e($piece->shelfLocation?->kode_lokasi ?? $piece->shelfLocation?->nama_lokasi ?? '-');
                            $qtyValue = rtrim(rtrim(number_format((float) $piece->panjang, 2, '.', ''), '0'), '.');
                            $unit = (string) ($piece->material?->satuan ?? 'unit');
                            $qty = e(trim($qtyValue . ' ' . $unit));
                            $normalizedUnit = strtolower(trim($unit));
                            $isLengthUnit = in_array($normalizedUnit, ['m', 'meter', 'meters', 'mtr'], true);
                            $pieceCount = (int) ($pieceCountByMaterial[(int) $piece->material_id] ?? 1);
                            $isPotongan = $isLengthUnit && $pieceCount > 1;
                            $typeLabel = $isPotongan ? 'Potongan' : 'Utuh';
                            $typeClass = $isPotongan ? 'is-potongan' : 'is-utuh';

                            $options[(string) $piece->id] = "
                                <span>{$rackName}</span>
                                <span>{$materialName}</span>
                                <span><span class=\"outbound-qty\">{$qty}</span></span>
                                <span><span class=\"outbound-type {$typeClass}\">{$typeLabel}</span></span>
                            ";

                            $pieceIds[] = (string) $piece->id;
                        }

                        return [
                            Hidden::make('all_piece_ids')
                                ->default($pieceIds),
                            CheckboxList::make('picked_piece_ids')
                                ->label('')
                                ->helperText($pieces->isEmpty() ? 'Tidak ada barang booked untuk proyek ini.' : null)
                                ->allowHtml()
                                ->live()
                                ->bulkToggleable(false)
                                ->options($options)
                                ->extraAttributes(['class' => 'outbound-checklist'])
                                ->required(),
                        ];
                    })
                    ->modalSubmitAction(fn (Action $action) => $action
                        ->label('Konfirmasi & Keluarkan Barang')
                        ->color('warning')
                        ->disabled(function (Get $get): bool {
                            $all = collect($get('all_piece_ids') ?? [])->map(fn ($id) => (string) $id)->unique();

                            if ($all->isEmpty()) {
                                return true;
                            }

                            $checked = collect($get('picked_piece_ids') ?? [])->map(fn ($id) => (string) $id)->unique();

                            return $checked->count() !== $all->count() || $checked->diff($all)->isNotEmpty();
                        }))
                    ->visible(fn ($record) => auth()->user()?->role === 'Manager Teknik' && in_array($record->status, ['Pending', 'Approved']))
                    ->action(function (Project $record, array $data) {
                        $allPieceIds = collect($data['all_piece_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values();
                        $pickedPieceIds = collect($data['picked_piece_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values();

                        if ($allPieceIds->isEmpty() || $pickedPieceIds->count() !== $allPieceIds->count() || $pickedPieceIds->diff($allPieceIds)->isNotEmpty()) {
                            Notification::make()
                                ->title('Checklist belum lengkap')
                                ->body('Centang semua barang pada daftar outbound sebelum konfirmasi.')
                                ->warning()
                                ->send();

                            return;
                        }

                        DB::transaction(function () use ($record, $pickedPieceIds): void {
                            $record->bookedPieces()
                                ->whereIn('id', $pickedPieceIds->all())
                                ->where('status', 'booked')
                                ->update([
                                    'status' => 'used',
                                    'used_at' => now(),
                                ]);

                            $record->update([
                                'status' => 'Processing',
                                'outbound_confirmed_at' => now(),
                            ]);
                        });

                        Notification::make()
                            ->title('Outbound berhasil dikonfirmasi')
                            ->success()
                            ->send();
                    }),
                \Filament\Actions\EditAction::make()
                    ->visible(fn () => auth()->user()?->role === 'Manager Teknik'),
                \Filament\Actions\DeleteAction::make()
                    ->visible(fn () => auth()->user()?->role === 'Manager Teknik'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ])
                    ->visible(fn () => auth()->user()?->role === 'Manager Teknik'),
            ]);
    }
}
