<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Auth\Events\Login;
use App\Models\UserLog;

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
        {
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
}
