<?php

namespace App\Filament\Resources\Inbounds\Tables;

use App\Models\MaterialPiece;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class InboundsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // 1. Tampilkan Nama Material, bukan ID-nya
                TextColumn::make('material.nama_material')
                    ->label('Nama Material')
                    ->searchable()
                    ->sortable(),

                // 2. Jumlah
                TextColumn::make('quantity')
                    ->label('Jumlah')
                    ->numeric(decimalPlaces: 1)
                    ->sortable(),

                // 3. Satuan (Tambahan baru)
                TextColumn::make('satuan')
                    ->label('Satuan')
                    ->searchable(),

                // 4. Tanggal
                TextColumn::make('entry_date')
                    ->label('Tanggal Masuk')
                    ->date()
                    ->sortable(),

                // 5. Asal inbound: supplier untuk inbound baru, project untuk retur
                TextColumn::make('asal_inbound')
                    ->label('Asal Inbound')
                    ->getStateUsing(fn ($record): string => $record->jenis_inbound === 'Retur'
                        ? ($record->project?->kode_proyek ?? '-')
                        : ($record->dataSupplier?->nama_supplier ?? '-'))
                    ->searchable(),

                // 6. Lokasi Rak (Tambahan baru)
                TextColumn::make('shelfLocation.nama_lokasi')
                    ->label('Lokasi Rak')
                    ->searchable(),

                // 7. Penerima (Tambahan baru)
                TextColumn::make('penerima')
                    ->label('Penerima / Staff')
                    ->searchable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('jenis_inbound')
                    ->label('Status')
                    ->getStateUsing(fn ($record): string => $record->is_opening_balance
                        ? 'Opening Balance'
                        : ($record->jenis_inbound === 'Retur' ? 'Retur' : 'Inbound Baru'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Opening Balance' => 'info',
                        'Inbound Baru' => 'success',
                        'Retur' => 'warning',
                        default => 'gray',
                    })
                    ->searchable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->label('Hapus')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Konfirmasi Hapus Inbound')
                    ->modalDescription('Menghapus inbound akan mengurangi stok, menghapus material piece terkait, dan memengaruhi alokasi.')
                    ->modalContent(fn ($record) => self::buildPiecesPreview($record))
                    ->form([
                        TextInput::make('manager_pin')
                            ->label('PIN Manager (6 digit)')
                            ->password()
                            ->required()
                            ->maxLength(6)
                            ->rule('regex:/^[0-9]{6}$/')
                            ->helperText('Masukkan PIN Manager Operasional untuk melanjutkan penghapusan inbound.'),
                    ])
                    ->action(function ($record, array $data): void {
                        $pin = (string) ($data['manager_pin'] ?? '');

                        if (! self::validateManagerPin($pin)) {
                            throw ValidationException::withMessages([
                                'manager_pin' => 'PIN Manager salah. Penghapusan inbound dibatalkan.',
                            ]);
                        }

                        $record->delete();

                        Notification::make()
                            ->title('Inbound berhasil dihapus')
                            ->body('Data inbound, piece terkait, dan stok summary telah disinkronkan ulang.')
                            ->success()
                            ->send();
                    }),
            ]);
    }

    private static function buildPiecesPreview($record): HtmlString
    {
        $rows = MaterialPiece::query()
            ->where('inbound_id', (int) $record->id)
            ->orderByDesc('panjang')
            ->orderBy('piece_code')
            ->get(['id', 'piece_code', 'panjang', 'status', 'sumber']);

        if ($rows->isEmpty()) {
            return new HtmlString('<div class="text-sm text-gray-600 dark:text-gray-300">Tidak ada material piece yang terhubung langsung dengan inbound ini.</div>');
        }

        $html = '<div class="space-y-3">';
        $html .= '<p class="text-sm text-rose-700 dark:text-rose-300"><strong>Peringatan:</strong> piece berikut akan ikut terhapus.</p>';
        $html .= '<div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">';
        $html .= '<table class="min-w-full text-xs">';
        $html .= '<thead class="bg-gray-50 dark:bg-white/5"><tr>';
        $html .= '<th class="px-3 py-2 text-left">Kode Item</th>';
        $html .= '<th class="px-3 py-2 text-left">Qty</th>';
        $html .= '<th class="px-3 py-2 text-left">Status</th>';
        $html .= '<th class="px-3 py-2 text-left">Sumber</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($rows as $piece) {
            $html .= '<tr class="border-t border-gray-200 dark:border-white/10">';
            $html .= '<td class="px-3 py-2"><div>'.e((string) ($piece->piece_code ?: '-')).'</div><div class="text-[11px] text-gray-500">#'.(int) $piece->id.'</div></td>';
            $html .= '<td class="px-3 py-2">'.e(number_format((float) $piece->panjang, 2, '.', '')).'</td>';
            $html .= '<td class="px-3 py-2">'.e((string) $piece->status).'</td>';
            $html .= '<td class="px-3 py-2">'.e((string) $piece->sumber).'</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div></div>';

        return new HtmlString($html);
    }

    private static function validateManagerPin(string $pin): bool
    {
        if (! preg_match('/^[0-9]{6}$/', $pin)) {
            return false;
        }

        $managers = User::query()
            ->whereIn('role', ['Manager Operasional', 'Manajer Operasional'])
            ->get(['id', 'pin', 'password']);

        foreach ($managers as $manager) {
            if (filled($manager->pin) && Hash::check($pin, (string) $manager->pin)) {
                return true;
            }

            if (Hash::check($pin, (string) $manager->password)) {
                return true;
            }
        }

        return false;
    }
}
