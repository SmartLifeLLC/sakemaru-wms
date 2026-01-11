<?php

namespace App\Models;

use Archilex\AdvancedTables\Concerns\HasViews;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;


// Only for filament advanced-tables (actual user table exists in SAKEMARU DB)
class User extends Authenticatable implements FilamentUser
{
    public function canAccessPanel(Panel $panel): bool
    {

        return true;
    }
}
