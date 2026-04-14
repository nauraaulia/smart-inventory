<?php

namespace App\Filament\Resources\Tasks\Pages;

use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Services\AI\TaskApprovalService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditTask extends EditRecord
{
    protected static string $resource = TaskResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $type = (string) ($data['type'] ?? '');

        if ($type !== 'ai_inbound_allocation_suggestion') {
            return $data;
        }

        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];

        $manualAllocation = collect($payload['manual_allocation'] ?? [])
            ->filter(fn ($row): bool => is_array($row))
            ->values();

        if ($manualAllocation->isEmpty()) {
            $manualAllocation = collect($payload['allocation_suggestions'] ?? [])
                ->filter(fn ($row): bool => is_array($row) && (int) ($row['project_id'] ?? 0) > 0)
                ->map(fn (array $row): array => [
                    'selected' => true,
                    'project_id' => (int) ($row['project_id'] ?? 0),
                    'project_code' => (string) ($row['project_code'] ?? ('#' . (int) ($row['project_id'] ?? 0))),
                    'pr_status' => (string) ($row['pr_status'] ?? '-'),
                    'qty' => (float) ($row['qty'] ?? $row['suggested_qty'] ?? 0),
                ])
                ->values();
        }

        $selectedPieceIds = collect($payload['selected_piece_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values();

        if ($selectedPieceIds->isEmpty()) {
            $selectedPieceIds = collect($payload['piece_ids'] ?? [])
                ->map(fn ($id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->values();
        }

        $payload['manual_allocation'] = $manualAllocation->all();
        $payload['selected_piece_ids'] = $selectedPieceIds->all();
        $data['payload'] = $payload;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Task $record */
        $service = new TaskApprovalService();

        if ($record->type === 'ai_booking_suggestion') {
            $materialItems = collect(data_get($data, 'payload.items', []));
            $newStatus = (string) ($data['status'] ?? $record->status);
            $oldStatus = (string) $record->status;
            $reasonInput = trim((string) ($data['rejection_reason'] ?? ''));
            $hasMaterialDecision = $materialItems->contains(
                fn ($item) => in_array((string) ($item['status'] ?? 'pending'), ['approved', 'rejected'], true)
            );

            if ($materialItems->isEmpty()) {
                throw ValidationException::withMessages([
                    'payload.items' => 'Detail material belum tersedia. Muat ulang halaman lalu coba lagi.',
                ]);
            }

            // Jika user sudah memilih salah satu status per-material,
            // proses memakai keputusan per-material dan global status diabaikan.
            if ($hasMaterialDecision) {
                if ($materialItems->contains(fn ($item) => (string) ($item['status'] ?? 'pending') === 'pending')) {
                    throw ValidationException::withMessages([
                        'payload.items' => 'Masih ada material dengan status pending. Tentukan semua material terlebih dahulu.',
                    ]);
                }

                $service->approve($record, auth()->user(), [
                    'material_decisions' => $materialItems
                        ->map(function ($item): array {
                            return [
                                'material_id' => (int) ($item['material_id'] ?? 0),
                                'status' => (string) ($item['status'] ?? 'pending'),
                                'recommended_piece_ids' => collect($item['recommended_piece_ids'] ?? [])->map(fn ($id) => (int) $id)->values()->all(),
                            ];
                        })
                        ->values()
                        ->all(),
                ]);

                return $record->refresh();
            }

            // Jika belum ada keputusan per-material, gunakan global ACC seperti alur lama.
            if ($newStatus === $oldStatus) {
                return $record->refresh();
            }

            if ($newStatus === 'done') {
                $service->approve($record, auth()->user());
                return $record->refresh();
            }

            if ($newStatus === 'rejected') {
                if ($reasonInput === '') {
                    throw ValidationException::withMessages([
                        'rejection_reason' => 'Alasan reject wajib diisi.',
                    ]);
                }

                $service->reject($record, auth()->user(), $reasonInput);
                return $record->refresh();
            }

            return $record->refresh();
        }

        if ($record->type === 'ai_inbound_allocation_suggestion') {
            $newStatus = (string) ($data['status'] ?? $record->status);
            $oldStatus = (string) $record->status;
            $reasonInput = trim((string) ($data['rejection_reason'] ?? ''));

            if ($newStatus === $oldStatus) {
                return $record->refresh();
            }

            if ($newStatus === 'done') {
                $service->approve($record, auth()->user(), [
                    'manual_allocation' => data_get($data, 'payload.manual_allocation', []),
                    'selected_piece_ids' => data_get($data, 'payload.selected_piece_ids', []),
                ]);

                return $record->refresh();
            }

            if ($newStatus === 'rejected') {
                if ($reasonInput === '') {
                    throw ValidationException::withMessages([
                        'rejection_reason' => 'Alasan reject wajib diisi.',
                    ]);
                }

                $service->reject($record, auth()->user(), $reasonInput);

                return $record->refresh();
            }

            return $record->refresh();
        }

        $newStatus = $data['status'] ?? $record->status;
        $oldStatus = $record->status;
        $reasonInput = trim((string) ($data['rejection_reason'] ?? ''));

        // Field virtual (bukan kolom DB), jangan ikut ke parent update.
        unset($data['rejection_reason']);

        if ($oldStatus === $newStatus) {
            return $record->refresh();
        }
        if ($newStatus === 'done') {
            $service->approve($record, auth()->user());
            return $record->refresh();
        }

        if ($newStatus === 'rejected') {
            if ($reasonInput === '') {
                throw ValidationException::withMessages([
                    'rejection_reason' => 'Alasan reject wajib diisi.',
                ]);
            }

            $reason = $reasonInput;
            $service->reject($record, auth()->user(), $reason);
            return $record->refresh();
        }

        return parent::handleRecordUpdate($record, $data);
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
