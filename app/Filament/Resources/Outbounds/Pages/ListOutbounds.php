<?php

namespace App\Filament\Resources\Outbounds\Pages;

use App\Filament\Resources\Outbounds\Concerns\HandlesManualOutboundPieces;
use App\Filament\Resources\Outbounds\OutboundResource;
use App\Filament\Widgets\OutboundStatsWidget;
use App\Models\Material;
use App\Models\Outbound;
use App\Models\Project;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ListOutbounds extends ListRecords
{
    use HandlesManualOutboundPieces;

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
                        ->label('Material + Pieces')
                        ->schema([
                            Select::make('material_id')
                                ->label('Material')
                                ->options(Material::query()->orderBy('nama_material')->pluck('nama_material', 'id'))
                                ->searchable()
                                ->required()
                                ->live()
                                ->afterStateUpdated(function (Set $set): void {
                                    $set('selected_piece_ids', []);
                                    $set('qty', null);
                                }),
                            Select::make('selected_piece_ids')
                                ->label('Pilih Piece / Item')
                                ->multiple()
                                ->searchable()
                                ->required()
                                ->visible(fn (Get $get): bool => (int) ($get('material_id') ?? 0) > 0)
                                ->options(fn (Get $get): array => $this->manualOutboundPieceOptions(
                                    (int) ($get('material_id') ?? 0),
                                    $this->manualOutboundPieceIds($get('selected_piece_ids') ?? []),
                                ))
                                ->live()
                                ->afterStateUpdated(function (Set $set, $state): void {
                                    $set('qty', $this->manualOutboundSelectedQty($state));
                                }),
                            TextInput::make('qty')
                                ->label('Qty Total Piece')
                                ->numeric()
                                ->disabled()
                                ->dehydrated()
                                ->required()
                                ->minValue(0.01),
                        ])
                        ->columns(3)
                        ->minItems(1)
                        ->required(),
                    Textarea::make('alasan_manual')
                        ->label('Alasan Outbound Manual')
                        ->required()
                        ->rows(3),
                ])
                ->action(function (array $data): void {
                    $items = $this->normalizeManualOutboundItemRows($data['items'] ?? []);

                    if ($items === null) {
                        return;
                    }

                    DB::transaction(function () use ($data, $items): void {
                        $outbound = Outbound::create([
                            'project_id' => (int) $data['project_id'],
                            'status' => 'pending',
                            'outbound_manual' => true,
                            'alasan_manual' => (string) $data['alasan_manual'],
                            'created_by' => auth()->id(),
                        ]);

                        foreach ($items as $row) {
                            $outbound->items()->create([
                                'material_id' => (int) $row['material_id'],
                                'qty' => (string) $row['qty'],
                                'unit' => (string) $row['unit'],
                                'selected_piece_ids' => $row['selected_piece_ids'],
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
