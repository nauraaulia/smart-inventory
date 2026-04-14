<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Auth\Events\Login;
use App\Models\UserLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('gemini-api', function (): Limit {
            return Limit::perMinute(5)->by('global-gemini-api');
        });

        // CCTV LOGIN
        Event::listen(Login::class, function ($event): void {
            UserLog::create([
                'user_id' => $event->user->id,
                'action' => 'Login',
                'module' => 'Authentication',
                'description' => $event->user->name . ' telah berhasil login ke sistem.',
                'ip_address' => request()->ip(),
            ]);
        });

        $autoLogModels = [
            \App\Models\InboundItem::class,
            \App\Models\MaterialPiece::class,
            \App\Models\Outbound::class,
            \App\Models\OutboundItem::class,
            \App\Models\ProjectItem::class,
            \App\Models\ProjectTemplateItem::class,
            \App\Models\PurchaseOrder::class,
            \App\Models\PurchaseOrderItem::class,
            \App\Models\ShelfLocation::class,
            \App\Models\Task::class,
            \App\Models\User::class,
        ];

        Model::created(function (Model $model) use ($autoLogModels): void {
            if (!$this->shouldAutoLogModel($model, $autoLogModels)) {
                return;
            }

            $module = $this->resolveModuleName($model);
            $label = $this->resolveRecordLabel($model);

            UserLog::create([
                'user_id' => auth()->id(),
                'action' => 'Create',
                'module' => $module,
                'description' => "Membuat data {$module}: {$label}",
                'ip_address' => request()->ip(),
            ]);
        });

        Model::updated(function (Model $model) use ($autoLogModels): void {
            if (!$this->shouldAutoLogModel($model, $autoLogModels)) {
                return;
            }

            $changes = collect($model->getChanges())
                ->except(['updated_at'])
                ->all();

            if (empty($changes)) {
                return;
            }

            $module = $this->resolveModuleName($model);
            $label = $this->resolveRecordLabel($model);
            $changedColumns = implode(', ', array_keys($changes));

            UserLog::create([
                'user_id' => auth()->id(),
                'action' => 'Update',
                'module' => $module,
                'description' => "Mengubah data {$module}: {$label} (kolom: {$changedColumns})",
                'ip_address' => request()->ip(),
            ]);
        });

        Model::deleted(function (Model $model) use ($autoLogModels): void {
            if (!$this->shouldAutoLogModel($model, $autoLogModels)) {
                return;
            }

            $module = $this->resolveModuleName($model);
            $label = $this->resolveRecordLabel($model);

            UserLog::create([
                'user_id' => auth()->id(),
                'action' => 'Delete',
                'module' => $module,
                'description' => "Menghapus data {$module}: {$label}",
                'ip_address' => request()->ip(),
            ]);
        });
    }

    /**
     * @param  array<int, class-string<Model>>  $autoLogModels
     */
    private function shouldAutoLogModel(Model $model, array $autoLogModels): bool
    {
        if ($model instanceof UserLog) {
            return false;
        }

        if (!in_array($model::class, $autoLogModels, true)) {
            return false;
        }

        return true;
    }

    private function resolveModuleName(Model $model): string
    {
        return Str::headline(class_basename($model));
    }

    private function resolveRecordLabel(Model $model): string
    {
        foreach (['nama', 'nama_material', 'nama_proyek', 'kode_proyek', 'kode', 'id_custom'] as $column) {
            if (!empty($model->{$column})) {
                return (string) $model->{$column};
            }
        }

        return '#' . (string) $model->getKey();
    }
}
