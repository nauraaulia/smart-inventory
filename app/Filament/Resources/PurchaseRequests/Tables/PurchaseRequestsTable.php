<?php

namespace App\Filament\Resources\PurchaseRequests\Tables;

use App\Models\PurchaseRequest;
use App\Services\AI\PurchaseRequestSubmissionService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PurchaseRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('project.kode_proyek')
                    ->label('Kode Proyek')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('material.nama_material')
                    ->label('Material')
                    ->searchable(),
                TextColumn::make('quantity')
                    ->label('Qty')
                    ->numeric(decimalPlaces: 2),
                TextColumn::make('priority')
                    ->label('Prioritas')
                    ->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'gray' => 'draft',
                        'danger' => 'rejected',
                        'warning' => ['pending_manager', 'pending_finance'],
                        'success' => ['approved', 'ordered', 'received'],
                    ]),
                TextColumn::make('draft_approved_at')
                    ->label('Draft Approved')
                    ->dateTime('d M Y H:i')
                    ->placeholder('-'),
                TextColumn::make('requester.name')
                    ->label('Requester')
                    ->placeholder('-'),
                TextColumn::make('submitted_at')
                    ->label('Submitted')
                    ->dateTime('d M Y H:i')
                    ->placeholder('-'),
                TextColumn::make('rejection_reason')
                    ->label('Alasan Reject')
                    ->limit(40)
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'pending_manager' => 'Pending Manager',
                        'pending_finance' => 'Pending Finance',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                        'ordered' => 'Ordered',
                        'received' => 'Received',
                    ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (PurchaseRequest $record) => self::canEditDraft($record)),
                Action::make('approve_draft')
                    ->label('Setujui Draft')
                    ->icon('heroicon-o-check-badge')
                    ->color('info')
                    ->requiresConfirmation()
                    ->visible(fn (PurchaseRequest $record) => self::canApproveDraft($record))
                    ->action(function (PurchaseRequest $record) {
                        $record->update([
                            'draft_approved_by' => auth()->id(),
                            'draft_approved_at' => now(),
                        ]);
                    }),
                Action::make('unapprove_draft')
                    ->label('Batalkan Persetujuan Draft')
                    ->icon('heroicon-o-x-mark')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (PurchaseRequest $record) => self::canUnapproveDraft($record))
                    ->action(function (PurchaseRequest $record) {
                        $record->update([
                            'draft_approved_by' => null,
                            'draft_approved_at' => null,
                        ]);
                    }),
                Action::make('submit_to_manager')
                    ->label('Submit ke Manager')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Submit Draft PR ke Manager Operasional')
                    ->modalDescription('Semua draft/rejected PR pada proyek ini akan digabung dan dikirim ke approval Manager Operasional.')
                    ->visible(fn (PurchaseRequest $record) => self::canSubmit($record))
                    ->action(function (PurchaseRequest $record) {
                        $service = new PurchaseRequestSubmissionService();
                        $service->submitDraftsForProject((int) $record->project_id, auth()->user());
                    }),
            ]);
    }

    private static function canEditDraft(PurchaseRequest $record): bool
    {
        $user = auth()->user();

        if (!$user) {
            return false;
        }

        if ($user->role === 'Administrator IT') {
            return true;
        }

        return in_array($user->role, ['Staff Administrasi Gudang', 'Staff Adm Gudang', 'Staff Gudang'], true)
            && in_array($record->status, ['draft', 'rejected'], true)
            && (int) $record->requested_by === (int) $user->id;
    }

    private static function canSubmit(PurchaseRequest $record): bool
    {
        $user = auth()->user();

        if (!$user || !$record->project_id) {
            return false;
        }

        if (!in_array($record->status, ['draft', 'rejected'], true)) {
            return false;
        }

        if (blank($record->draft_approved_at)) {
            return false;
        }

        if ($user->role === 'Administrator IT') {
            return true;
        }

        return in_array($user->role, ['Staff Administrasi Gudang', 'Staff Adm Gudang', 'Staff Gudang'], true)
            && (int) $record->requested_by === (int) $user->id;
    }

    private static function canApproveDraft(PurchaseRequest $record): bool
    {
        $user = auth()->user();

        if (!$user) {
            return false;
        }

        if (!in_array($record->status, ['draft', 'rejected'], true)) {
            return false;
        }

        if (filled($record->draft_approved_at)) {
            return false;
        }

        if ($user->role === 'Administrator IT') {
            return true;
        }

        return in_array($user->role, ['Staff Administrasi Gudang', 'Staff Adm Gudang', 'Staff Gudang'], true)
            && (int) $record->requested_by === (int) $user->id;
    }

    private static function canUnapproveDraft(PurchaseRequest $record): bool
    {
        $user = auth()->user();

        if (!$user) {
            return false;
        }

        if (!in_array($record->status, ['draft', 'rejected'], true)) {
            return false;
        }

        if (blank($record->draft_approved_at)) {
            return false;
        }

        if ($user->role === 'Administrator IT') {
            return true;
        }

        return in_array($user->role, ['Staff Administrasi Gudang', 'Staff Adm Gudang', 'Staff Gudang'], true)
            && (int) $record->requested_by === (int) $user->id;
    }
}
