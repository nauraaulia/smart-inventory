<?php

namespace App\Filament\Resources\Tasks\Pages;

use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Widgets\TaskStatsWidget;
use App\Models\Task;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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

    protected function getHeaderWidgets(): array
    {
        return [
            TaskStatsWidget::class,
        ];
    }

    public function getTabs(): array
    {
        return [
            'ai' => Tab::make('Task AI')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereKey($this->taskIdsByQueueType('ai')))
                ->badge($this->countTasksByType('ai'))
                ->badgeColor('info'),

            'operasional' => Tab::make('Task Operasional')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereKey($this->taskIdsByQueueType('operasional')))
                ->badge($this->countTasksByType('operasional'))
                ->badgeColor('primary'),

            'all' => Tab::make('Semua Task')
                ->badge($this->countVisibleTasks()),

            'pending' => Tab::make('Pending')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereKey($this->taskIdsByVisualStatus('pending')))
                ->badge($this->countVisibleTasks('pending'))
                ->badgeColor('warning'),

            'done' => Tab::make('Done')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereKey($this->taskIdsByVisualStatus('done')))
                ->badge($this->countVisibleTasks('done'))
                ->badgeColor('success'),

            'approved_material' => Tab::make('Approved Items')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereKey($this->taskIdsByDecisionState('approved')))
                ->badge($this->countTasksByDecisionState('approved'))
                ->badgeColor('success'),

            'rejected_material' => Tab::make('Rejected Items')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereKey($this->taskIdsByDecisionState('rejected')))
                ->badge($this->countTasksByDecisionState('rejected'))
                ->badgeColor('danger'),
        ];
    }

    private function countTasksByType(string $type): int
    {
        return count($this->taskIdsByQueueType($type));
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    private function countVisibleTasks(?string $status = null): int
    {
        if ($status !== null) {
            return count($this->taskIdsByVisualStatus($status));
        }

        return (int) TaskResource::getEloquentQuery()->count();
    }

    private function countTasksByDecisionState(string $decision): int
    {
        return count($this->taskIdsByDecisionState($decision));
    }

    /**
     * @return array<int, int>
     */
    private function taskIdsByVisualStatus(string $status): array
    {
        return TaskResource::getEloquentQuery()
            ->get(['id', 'type', 'status', 'payload'])
            ->filter(fn (Task $task): bool => $task->visualStatus() === $status)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function taskIdsByQueueType(string $type): array
    {
        return TaskResource::getEloquentQuery()
            ->get(['id', 'type', 'status', 'payload'])
            ->filter(function (Task $task) use ($type): bool {
                if ($task->visualStatus() !== 'pending') {
                    return false;
                }

                return match ($type) {
                    'ai' => str_starts_with((string) $task->type, 'ai_')
                        && $task->type !== 'ai_inbound_allocation_suggestion',
                    'operasional' => $task->type === 'ai_inbound_allocation_suggestion',
                    default => true,
                };
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function taskIdsByDecisionState(string $decision): array
    {
        return TaskResource::getEloquentQuery()
            ->where('type', 'ai_booking_suggestion')
            ->get(['id', 'type', 'status', 'payload'])
            ->filter(function (Task $task) use ($decision): bool {
                return match ($decision) {
                    'approved' => $task->hasAiItemStatus('approved'),
                    'rejected' => $task->hasAiItemStatus('rejected'),
                    default => false,
                };
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    private function syncAiBookingNotifications(bool $sendInfoNotification, bool $sendReadyNotification): void
    {
        $currentProcessing = $this->currentAiBookingTaskIds(processingOnly: true);
        $currentReady = $this->currentAiBookingTaskIds(processingOnly: false);

        $newProcessing = array_values(array_diff($currentProcessing, $this->aiProcessingTaskIds));
        if ($sendInfoNotification && ! empty($newProcessing)) {
            Notification::make()
                ->title('Gemini sedang memproses rekomendasi')
                ->body('Mohon tunggu sebentar, rekomendasi booking/PR akan diperbarui otomatis.')
                ->info()
                ->send();
        }

        if ($sendReadyNotification && ! empty($this->aiProcessingTaskIds)) {
            $becameReady = array_values(array_intersect($this->aiProcessingTaskIds, $currentReady));
            $becameReady = array_values(array_diff($becameReady, $this->aiReadyNotifiedTaskIds));

            if (! empty($becameReady)) {
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
            ->orderByDesc('id');

        /** @var Collection<int, Task> $tasks */
        $tasks = $query->get(['id', 'payload']);

        return $tasks
            ->filter(function (Task $task) use ($processingOnly): bool {
                if ($task->visualStatus() !== 'pending') {
                    return false;
                }

                $triggerSource = (string) data_get($task->payload, 'ai_trigger_source', 'system_sync');
                if ($triggerSource !== 'manager_project_submit') {
                    return false;
                }

                $items = data_get($task->payload, 'items', []);
                $hasItems = is_array($items) && ! empty($items);

                return $processingOnly ? ! $hasItems : $hasItems;
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }
}
