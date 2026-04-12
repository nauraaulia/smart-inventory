<?php

namespace App\Filament\Resources\Outbounds\Pages;

use App\Filament\Resources\Outbounds\OutboundResource;
use App\Models\Outbound;
use App\Models\Project;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\DB;

class ViewOutbound extends Page
{
    protected static string $resource = OutboundResource::class;

    protected string $view = 'filament.resources.outbounds.pages.view-outbound';

    public Outbound $record;

    public string $currentDate = '';

    public function mount(Outbound $record): void
    {
        abort_unless($this->canAccessOutbound(auth()->user()?->role), 403);

        $this->record = $record->load([
            'project:id,kode_proyek,nama_proyek,status',
            'items:id,outbound_id,material_id,qty,unit',
            'items.material:id,nama_material,satuan',
        ]);

        $this->currentDate = now()->translatedFormat('d F Y');
    }

    public function getHeading(): string
    {
        $projectCode = $this->record->project?->kode_proyek ?: ('#' . $this->record->project_id);

        return 'Outbound - ' . $projectCode;
    }

    public function getSubheading(): ?string
    {
        return 'Kelola status outbound dari menunggu sampai barang diambil lengkap dengan bukti.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('mark_ready')
                ->label('Tandai Siap')
                ->icon('heroicon-o-check-badge')
                ->color('info')
                ->visible(fn (): bool => $this->canPrepare() && $this->record->status === 'pending')
                ->requiresConfirmation()
                ->modalHeading('Tandai outbound siap diambil?')
                ->action(function (): void {
                    $this->record->update([
                        'status' => 'ready',
                        'ready_at' => now(),
                        'prepared_by' => auth()->id(),
                    ]);

                    Notification::make()
                        ->title('Outbound ditandai siap')
                        ->success()
                        ->send();

                    $this->record->refresh();
                }),
            Action::make('mark_taken')
                ->label('Konfirmasi Sudah Diambil')
                ->icon('heroicon-o-camera')
                ->color('success')
                ->visible(fn (): bool => $this->canTake() && $this->record->status === 'ready')
                ->form([
                    FileUpload::make('foto_bukti')
                        ->label('Foto Bukti Pengambilan')
                        ->image()
                        ->directory('outbound-proofs')
                        ->disk('public')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    DB::transaction(function () use ($data): void {
                        $this->record->update([
                            'status' => 'taken',
                            'taken_at' => now(),
                            'foto_bukti' => (string) $data['foto_bukti'],
                            'taken_by' => auth()->id(),
                        ]);

                        if (!$this->record->outbound_manual && $this->record->project_id) {
                            DB::table('material_pieces')
                                ->where('booked_for_project_id', $this->record->project_id)
                                ->where('status', 'booked')
                                ->update([
                                    'status' => 'used',
                                    'used_at' => now(),
                                    'updated_at' => now(),
                                ]);
                        }

                        $project = $this->record->project;
                        if ($project) {
                            $this->completeProjectIfAllTaken($project);
                        }
                    });

                    Notification::make()
                        ->title('Outbound berhasil dikonfirmasi diambil')
                        ->success()
                        ->send();

                    $this->record->refresh();
                }),
        ];
    }

    public function getDisplayItems(): array
    {
        $this->record->loadMissing([
            'items.material:id,nama_material,satuan',
            'project.bookedPieces:id,booked_for_project_id,material_id,panjang,status',
            'project.bookedPieces.material:id,nama_material,satuan',
        ]);

        if ($this->record->items->isNotEmpty()) {
            return $this->record->items
                ->map(fn ($item): array => [
                    'material' => (string) ($item->material?->nama_material ?? 'Material tidak diketahui'),
                    'qty' => $this->formatQty((float) $item->qty, (string) ($item->unit ?: ($item->material?->satuan ?: 'unit'))),
                ])
                ->values()
                ->all();
        }

        $project = $this->record->project;
        if (!$project) {
            return [];
        }

        return $project->bookedPieces
            ->whereIn('status', ['booked', 'used'])
            ->groupBy('material_id')
            ->map(function ($pieces): array {
                $first = $pieces->first();
                $unit = (string) ($first?->material?->satuan ?: 'unit');

                return [
                    'material' => (string) ($first?->material?->nama_material ?: 'Material tidak diketahui'),
                    'qty' => $this->formatQty((float) $pieces->sum('panjang'), $unit),
                ];
            })
            ->values()
            ->all();
    }

    private function completeProjectIfAllTaken(Project $project): void
    {
        $remaining = $project->outbounds()->where('status', '!=', 'taken')->count();

        if ($remaining === 0) {
            $project->update([
                'status' => 'Completed',
                'completed_at' => now(),
            ]);
        }
    }

    private function canPrepare(): bool
    {
        return in_array(auth()->user()?->role, [
            'Staff Administrasi Gudang',
            'Staff Adm Gudang',
            'Staff Gudang',
            'Administrator IT',
        ], true);
    }

    private function canTake(): bool
    {
        return in_array(auth()->user()?->role, [
            'Manager Teknik',
            'Manajer Teknik',
            'Administrator IT',
        ], true);
    }

    private function canAccessOutbound(?string $role): bool
    {
        return in_array($role, [
            'Manager Teknik',
            'Manajer Teknik',
            'Staff Administrasi Gudang',
            'Staff Adm Gudang',
            'Staff Gudang',
            'Administrator IT',
        ], true);
    }

    private function formatQty(float $value, string $unit): string
    {
        $number = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return trim($number . ' ' . $unit);
    }
}
