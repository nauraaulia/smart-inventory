<?php

namespace App\Filament\Widgets;

use Filament\Facades\Filament;
use Filament\Widgets\Widget;

class StaffWelcomeWidget extends Widget
{
    protected static ?int $sort = -3;

    protected static bool $isLazy = false;

    protected int | string | array $columnSpan = 'full';

    protected string $view = 'filament.widgets.staff-welcome-widget';

    public static function canView(): bool
    {
        return Filament::auth()->check();
    }
}
