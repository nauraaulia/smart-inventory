<?php

namespace App\Filament\Resources\Tasks\Pages;

use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListTasks extends ListRecords
{
    protected static string $resource = TaskResource::class;

    protected ?string $pollingInterval = '1s';

    /** @var array<int, int> */
    public array $aiProcessingTaskIds = [];

    /** @var array<int, int> */
    public array $aiReadyNotifiedTaskIds = [];

    public function mount(): void
    {
        parent::mount();

        $this->syncAiBookingNotifications(sendInfoNotification: true, sendReadyNotification: false);
    }

    public function hydrate(): void
    {
        $this->syncAiBookingNotifications(sendInfoNotification: true, sendReadyNotification: true);
    }

    public function getTabs(): array
    {
        return [
            'ai' => Tab::make('Task AI')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('type', 'like', 'ai_%')->whereNotIn('type', ['ai_inbound_allocation_suggestion']))
                ->badge($this->countTasksByType('ai'))
                ->badgeColor('info'),

            'operasional' => Tab::make('Task Operasional')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('type', ['ai_inbound_allocation_suggestion']))
                ->badge($this->countTasksByType('operasional'))
                ->badgeColor('primary'),

            'all' => Tab::make('Semua Task')
                ->badge($this->countVisibleTasks()),

            'pending' => Tab::make('Pending')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending'))
                ->badge($this->countVisibleTasks('pending'))
                ->badgeColor('warning'),

            'approved' => Tab::make('Approved')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'approved'))
                ->badge($this->countVisibleTasks('approved'))
                ->badgeColor('info'),

            'done' => Tab::make('Done')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'done'))
                ->badge($this->countVisibleTasks('done'))
                ->badgeColor('success'),

            'rejected' => Tab::make('Rejected')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'rejected'))
                ->badge($this->countVisibleTasks('rejected'))
                ->badgeColor('danger'),
        ];
    }

    private function countTasksByType(string $type): int
    {
        $query = TaskResource::getEloquentQuery();

        if ($type === 'ai') {
            $query->where('type', 'like', 'ai_%')->whereNotIn('type', ['ai_inbound_allocation_suggestion']);
        } elseif ($type === 'operasional') {
            $query->whereIn('type', ['ai_inbound_allocation_suggestion']);
        }

        return (int) $query->count();
    }

    protected function getHeaderActions(): array
    {
        return [
            // CreateAction::make(),
        ];
    }

    private function countVisibleTasks(?string $status = null): int
    {
        $query = TaskResource::getEloquentQuery();

        if ($status !== null) {
            $query->where('status', $status);
        }

        return (int) $query->count();
    }

    private function syncAiBookingNotifications(bool $sendInfoNotification, bool $sendReadyNotification): void
    {
        $currentProcessing = $this->currentAiBookingTaskIds(processingOnly: true);
        $currentReady = $this->currentAiBookingTaskIds(processingOnly: false);

        $newProcessing = array_values(array_diff($currentProcessing, $this->aiProcessingTaskIds));
        if ($sendInfoNotification && !empty($newProcessing)) {
            Notification::make()
                ->title('Gemini sedang memproses rekomendasi')
                ->body('Mohon tunggu sebentar, rekomendasi booking/PR akan diperbarui otomatis.')
                ->info()
                ->send();
        }

        if ($sendReadyNotification && !empty($this->aiProcessingTaskIds)) {
            $becameReady = array_values(array_intersect($this->aiProcessingTaskIds, $currentReady));
            $becameReady = array_values(array_diff($becameReady, $this->aiReadyNotifiedTaskIds));

            if (!empty($becameReady)) {
                Notification::make()
                    ->title('Jawaban Gemini sudah siap')
                    ->body('Task AI untuk staff gudang sudah diperbarui otomatis. Silakan review sekarang.')
                    ->success()
                    ->send();

                $this->aiReadyNotifiedTaskIds = array_values(array_unique(array_merge($this->aiReadyNotifiedTaskIds, $becameReady)));
            }
        }

        $this->aiProcessingTaskIds = $currentProcessing;
    }

    /**
     * @return array<int, int>
     */
    private function currentAiBookingTaskIds(bool $processingOnly): array
    {
        $query = TaskResource::getEloquentQuery()
            ->where('type', 'ai_booking_suggestion')
            ->where('status', 'pending')
            ->orderByDesc('id');

        /** @var \Illuminate\Support\Collection<int, Task> $tasks */
        $tasks = $query->get(['id', 'payload']);

        return $tasks
            ->filter(function (Task $task) use ($processingOnly): bool {
                $items = data_get($task->payload, 'items', []);
                $hasItems = is_array($items) && !empty($items);

                return $processingOnly ? !$hasItems : $hasItems;
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }
}