<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Dashboard;

class DashboardInventory extends Dashboard
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-home-modern';

    protected static ?string $navigationLabel = 'Dashboard Inventory';

    protected static ?string $title = 'Inventory Management Dashboard';
}
