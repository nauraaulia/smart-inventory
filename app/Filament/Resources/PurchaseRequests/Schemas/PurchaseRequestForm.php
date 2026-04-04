<?php

namespace App\Filament\Resources\PurchaseRequests\Schemas;

use App\Models\PurchaseRequest;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PurchaseRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('project.kode_proyek')
                    ->label('Kode Proyek')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('material.nama_material')
                    ->label('Material')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('quantity')
                    ->label('Quantity')
                    ->numeric()
                    ->required()
                    ->disabled(fn (?PurchaseRequest $record) => !self::canEditDraft($record)),
                Select::make('priority')
                    ->label('Prioritas')
                    ->options([
                        'low' => 'Low',
                        'medium' => 'Medium',
                        'high' => 'High',
                        'urgent' => 'Urgent',
                    ])
                    ->required()
                    ->disabled(fn (?PurchaseRequest $record) => !self::canEditDraft($record)),
                DatePicker::make('needed_date')
                    ->label('Tanggal Dibutuhkan')
                    ->disabled(fn (?PurchaseRequest $record) => !self::canEditDraft($record)),
                Textarea::make('reason')
                    ->label('Reason')
                    ->rows(3)
                    ->disabled(fn (?PurchaseRequest $record) => !self::canEditDraft($record)),
                TextInput::make('status')
                    ->disabled(),
                TextInput::make('draftApprover.name')
                    ->label('Draft Approved By')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('draft_approved_at')
                    ->label('Draft Approved At')
                    ->disabled(),
                Textarea::make('rejection_reason')
                    ->label('Alasan Reject')
                    ->rows(3)
                    ->disabled(),
            ]);
    }

    private static function canEditDraft(?PurchaseRequest $record): bool
    {
        $user = auth()->user();

        if (!$user || !$record) {
            return false;
        }

        if ($user->role === 'Administrator IT') {
            return true;
        }

        return in_array($user->role, ['Staff Administrasi Gudang', 'Staff Adm Gudang', 'Staff Gudang'], true)
            && in_array($record->status, ['draft', 'rejected'], true)
            && (int) $record->requested_by === (int) $user->id;
    }
}
