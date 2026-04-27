<?php

namespace App\Filament\Resources\Outbounds\Pages;

use App\Filament\Resources\Outbounds\OutboundResource;
use App\Filament\Widgets\OutboundStatsWidget;
use App\Models\Outbound;
use App\Models\Project;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ListOutbounds extends ListRecords
{
    protected static string $resource = OutboundResource::class;

    protected ?string $pollingInterval = '2s';

    protected function getHeaderWidgets(): array
    {
        return [
            OutboundStatsWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('manual_outbound')
                ->label('Outbound Manual')
                ->icon('heroicon-o-plus-circle')
                ->color('warning')
                ->visible(fn (): bool => in_array(auth()->user()?->role, ['Staff Administrasi Gudang', 'Staff Adm Gudang', 'Staff Gudang'], true))
                ->form([
                    Select::make('project_id')
                        ->label('Pilih Proyek')
                        ->options(Project::query()->active()->orderBy('kode_proyek')->pluck('kode_proyek', 'id'))
                        ->required()
                        ->searchable(),
                    Repeater::make('items')
                        ->label('Material + Qty')
                        ->schema([
                            Select::make('material_id')
                                ->label('Material')
                                ->options(\App\Models\Material::query()->orderBy('nama_material')->pluck('nama_material', 'id'))
                                ->searchable()
                                ->required(),
                            TextInput::make('qty')
                                ->label('Qty')
                                ->numeric()
                                ->required()
                                ->minValue(0.01),
                        ])
                        ->columns(2)
                        ->minItems(1)
                        ->required(),
                    Textarea::make('alasan_manual')
                        ->label('Alasan Outbound Manual')
                        ->required()
                        ->rows(3),
                ])
                ->action(function (array $data): void {
                    DB::transaction(function () use ($data): void {
                        $outbound = Outbound::create([
                            'project_id' => (int) $data['project_id'],
                            'status' => 'pending',
                            'outbound_manual' => true,
                            'alasan_manual' => (string) $data['alasan_manual'],
                            'created_by' => auth()->id(),
                        ]);

                        foreach (($data['items'] ?? []) as $row) {
                            $materialId = (int) ($row['material_id'] ?? 0);
                            $qty = (float) ($row['qty'] ?? 0);

                            if ($materialId <= 0 || $qty <= 0) {
                                continue;
                            }

                            $material = \App\Models\Material::query()->find($materialId);

                            $outbound->items()->create([
                                'material_id' => $materialId,
                                'qty' => number_format($qty, 2, '.', ''),
                                'unit' => (string) ($material?->satuan ?: 'unit'),
                            ]);
                        }
                    });

                    Notification::make()
                        ->title('Outbound manual berhasil dibuat')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('Menunggu')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending')),
            'ready' => Tab::make('Siap')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'ready')),
            'taken' => Tab::make('Sudah Diambil')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'taken')),
            'all' => Tab::make('Semua'),
        ];
    }
}
