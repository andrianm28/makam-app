<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Dashboard Vendor';
    protected static ?string $navigationIcon = 'heroicon-o-home';
}
