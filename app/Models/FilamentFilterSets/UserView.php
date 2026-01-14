<?php

namespace App\Models\FilamentFilterSets;

use Archilex\AdvancedTables\Models\UserView as BaseUserView;
use Archilex\AdvancedTables\Support\Config;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class UserView extends BaseUserView
{
    protected $connection = 'sakemaru';

    protected $table = 'wms_filament_filter_sets';

    /**
     * Override to use the correct pivot table name with wms_ prefix
     */
    public function userManagedUserViews(): BelongsToMany
    {
        return $this->belongsToMany(Config::getUser(), 'wms_filament_filter_set_user', foreignPivotKey: 'filter_set_id', relatedPivotKey: 'user_id')
            ->withPivot('sort_order', 'is_visible', Config::getTenantColumn());
    }
}
