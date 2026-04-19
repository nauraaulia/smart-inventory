<?php

namespace App\Providers;

use Filament\Tables\Table;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Auth\Events\Login;
use App\Models\UserLog;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // PAGINATION GLOBAL SEMUA TABEL
        Table::configureUsing(function (Table $table): void {
            $table->paginationPageOptions([10, 25, 50, 100]);
        });

        // CCTV LOGIN
        Event::listen(Login::class, function ($event) {
            UserLog::create([
                'user_id' => $event->user->id,
                'action' => 'Login',
                'module' => 'Authentication',
                'description' => $event->user->name . ' telah berhasil login ke sistem.',
                'ip_address' => request()->ip(),
            ]);
        });
    }
}