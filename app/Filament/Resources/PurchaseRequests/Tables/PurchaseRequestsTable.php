<?php

namespace App\Filament\Resources\PurchaseRequests\Tables;

use App\Models\PurchaseRequest;
use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\Task;
use App\Models\UserLog;
use App\Services\AI\PurchaseRequestSubmissionService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PurchaseRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->poll('2s')
            ->selectable(fn (): bool => in_array(auth()->user()?->role, ['Manager Operasional', 'Manager Operational', 'Manajer Operasional'], true))
            ->currentSelectionLivewireProperty('selectedPrIds')
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
                    ->numeric(decimalPlaces: 1),
                TextColumn::make('priority')
                    ->label('Prioritas')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'urgent' => 'CRITICAL',
                        'high' => 'HIGH',
                        'medium' => 'MEDIUM',
                        'low' => 'LOW',
                        default => strtoupper($state),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'urgent' => 'danger',
                        'high' => 'warning',
                        'medium' => 'info',
                        'low' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('hari_tersisa')
                    ->label('Hari Tersisa')
                    ->getStateUsing(fn (PurchaseRequest $record) => $record->hari_tersisa)
                    ->formatStateUsing(fn ($state): string => $state === null ? '-' : "{$state} hari")
                    ->color(fn ($state): string => match (true) {
                        $state === null => 'gray',
                        $state <= 0 => 'danger',
                        $state <= 7 => 'warning',
                        $state <= 14 => 'info',
                        default => 'success',
                    }),
                TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'gray' => 'draft',
                        'danger' => 'rejected',
                        'warning' => ['pending_manager', 'pending_finance'],
                        'success' => ['approved', 'ordered', 'received'],
                    ]),
                TextColumn::make('created_at')
                    ->label('Draft Dibuat')
                    ->dateTime('d M Y H:i')
                    ->placeholder('-'),
                TextColumn::make('requester.name')
                    ->label('Requester')
                    ->placeholder('-'),
                TextColumn::make('submitted_at')
                    ->label('Draft Terkirim')
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
                    ->visible(fn (PurchaseRequest $record) => PurchaseRequestResource::canEdit($record)),
                Action::make('approve_draft')
                    ->label('Setujui & Kirim ke Manager')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (PurchaseRequest $record) => self::canApproveDraft($record))
                    ->action(function (PurchaseRequest $record) {
                        $record->update([
                            'draft_approved_by' => auth()->id(),
                            'draft_approved_at' => now(),
                        ]);

                        $service = new PurchaseRequestSubmissionService();
                        $result = $service->submitDraftsForProject((int) $record->project_id, auth()->user());

                        Notification::make()
                            ->title('Draft PR disetujui dan dikirim ke Manager')
                            ->body("{$result['submitted_count']} PR berhasil disubmit untuk project {$result['project_code']}.")
                            ->success()
                            ->send();
                    }),
                Action::make('cancel_submission')
                    ->label('Batalkan Pengajuan')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Batalkan Pengajuan ke Manager')
                    ->modalDescription('PR akan dikembalikan ke status Draft selama Manager Operasional belum melakukan approval.')
                    ->visible(fn (PurchaseRequest $record) => PurchaseRequestResource::canCancelSubmission($record))
                    ->action(function (PurchaseRequest $record): void {
                        $result = (new PurchaseRequestSubmissionService())
                            ->cancelSubmittedPr($record, auth()->user());

                        Notification::make()
                            ->title($result['cancelled'] ? 'Pengajuan PR dibatalkan' : 'Pengajuan tidak dapat dibatalkan')
                            ->body($result['cancelled']
                                ? "PR dikembalikan ke draft untuk project {$result['project_code']}."
                                : 'Status PR tidak lagi menunggu approval manager.')
                            ->success($result['cancelled'])
                            ->warning(!$result['cancelled'])
                            ->send();
                    })
                    ->after(function ($livewire): void {
                        $livewire->redirect(PurchaseRequestResource::getUrl('index'), navigate: true);
                    }),
                Action::make('cancel_submission_to_finance')
                    ->label('Batalkan Pengajuan ke Finance')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Batalkan Pengajuan ke Finance')
                    ->modalDescription('PR akan dikembalikan ke status Menunggu ACC Manager selama Finance belum melakukan approval.')
                    ->visible(fn (PurchaseRequest $record) => PurchaseRequestResource::canCancelSubmissionToFinanceByManager($record))
                    ->action(function (PurchaseRequest $record): void {
                        $result = (new PurchaseRequestSubmissionService())
                            ->cancelSubmissionToFinanceByManager($record, auth()->user());

                        Notification::make()
                            ->title($result['cancelled'] ? 'Pengajuan ke Finance dibatalkan' : 'Pengajuan tidak dapat dibatalkan')
                            ->body($result['cancelled']
                                ? "PR dikembalikan ke status pending manager untuk project {$result['project_code']}."
                                : 'Status PR tidak lagi menunggu approval finance.')
                            ->success($result['cancelled'])
                            ->warning(!$result['cancelled'])
                            ->send();
                    })
                    ->after(function ($livewire): void {
                        $livewire->redirect(PurchaseRequestResource::getUrl('index'), navigate: true);
                    }),
                Action::make('approve_manager')
                    ->label('Setujui dan Kirim ke Finance')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (PurchaseRequest $record) => self::canApproveByManager($record))
                    ->action(function (PurchaseRequest $record): void {
                        $record->update([
                            'status' => 'pending_finance',
                            'approved_by_manager' => auth()->id(),
                            'approved_by_manager_at' => now(),
                        ]);

                        UserLog::create([
                            'user_id' => auth()->id(),
                            'action' => 'Approve',
                            'module' => 'Purchase Request',
                            'description' => "Manager approve PR ID {$record->id}.",
                            'ip_address' => request()?->ip(),
                        ]);

                        Notification::make()
                            ->title('PR disetujui Manager')
                            ->success()
                            ->send();
                    }),
                Action::make('approve_finance_only')
                    ->label('Setuju')
                    ->icon('heroicon-o-check-circle')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (PurchaseRequest $record) => self::canApproveByFinance($record))
                    ->action(function (PurchaseRequest $record, $livewire): void {
                        $record->update([
                            'status' => 'approved',
                            'approved_by_finance' => auth()->id(),
                            'approved_by_finance_at' => now(),
                        ]);

                        Task::create([
                            'for_role' => 'Manager Operasional',
                            'type' => 'po_manual_followup',
                            'title' => 'PR Disetujui Finance - Lanjutkan ke PO',
                            'message' => 'PR sudah disetujui Finance. Mohon Manager Operasional lanjutkan proses pembuatan PO.',
                            'payload' => [
                                'pr_ids' => [$record->id],
                                'project_id' => $record->project_id,
                                'project_code' => $record->project?->kode_proyek ?? ('#' . $record->project_id),
                                'source' => 'purchase_requests_table_approve_finance_only',
                            ],
                            'status' => 'pending',
                            'related_id' => $record->project_id,
                            'related_type' => 'project',
                        ]);

                        UserLog::create([
                            'user_id' => auth()->id(),
                            'action' => 'Approve',
                            'module' => 'Purchase Request',
                            'description' => "Finance approve PR ID {$record->id} (tanpa auto-redirect PO).",
                            'ip_address' => request()?->ip(),
                        ]);

                        Notification::make()
                            ->title('PR disetujui Finance')
                            ->success()
                            ->send();
                    }),
            ]);
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

        return in_array($user->role, ['Staff Administrasi Gudang', 'Staff Adm Gudang', 'Staff Gudang'], true)
            && (int) $record->requested_by === (int) $user->id;
    }

    private static function canApproveByManager(PurchaseRequest $record): bool
    {
        $user = auth()->user();

        if (!$user) {
            return false;
        }

        return in_array($user->role, ['Manager Operasional', 'Manager Operational', 'Manajer Operasional'], true)
            && $record->status === 'pending_manager';
    }

    private static function canApproveByFinance(PurchaseRequest $record): bool
    {
        $user = auth()->user();

        if (!$user) {
            return false;
        }

        return in_array($user->role, ['Finance', 'Keuangan'], true)
            && $record->status === 'pending_finance';
    }

}
