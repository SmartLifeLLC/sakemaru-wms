<?php

namespace App\Models;

use Archilex\AdvancedTables\Concerns\HasViews;
use Illuminate\Database\Eloquent\Model;



// Only for filament advanced-tables (actual user table exists in SAKEMARU DB)
class User extends Model
{
    use HasViews;
}
