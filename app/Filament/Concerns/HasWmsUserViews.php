<?php

namespace App\Filament\Concerns;

use Archilex\AdvancedTables\Support\Config;
use Illuminate\Support\Collection;

trait HasWmsUserViews
{
    /**
     * Override to use correct table names with wms_ prefix
     */
    protected function getUserViews(): Collection
    {
        if (! Config::userViewsAreEnabled()) {
            return collect();
        }

        $columns = ['id', 'user_id', 'name', 'resource', 'is_public', 'is_global_favorite', 'status', 'filters', 'sort_order', 'color', 'icon'];

        if (Config::hasTenancy()) {
            $columns[] = Config::getTenantColumn();
        }

        // Get table names from the model instances
        $userViewModel = app(Config::getUserView());
        $userViewTable = $userViewModel->getTable();
        $pivotTable = 'wms_filament_filter_set_user';
        $managedDefaultViewsTable = 'wms_filament_filter_sets_managed_default_views';

        return Config::getUserView()::query()
            ->select($columns)
            ->selectSub(function ($query) use ($pivotTable, $userViewTable) {
                // Use 'users' directly without prefix - users table doesn't have wms_ prefix
                $userTable = Config::getUserTable();
                $query->selectRaw('COUNT('.$userTable.'.'.Config::getUserTableKeyColumn().')')
                    ->from($pivotTable)
                    ->join($userTable, $userTable.'.'.Config::getUserTableKeyColumn().'', '=', $pivotTable.'.user_id')
                    ->whereColumn($pivotTable.'.filter_set_id', $userViewTable.'.id')
                    ->where($pivotTable.'.user_id', Config::auth()->id());
            }, 'is_managed_by_current_user')
            ->selectSub(function ($query) use ($pivotTable, $userViewTable) {
                $query->select('managed_user_views.id')
                    ->from($pivotTable.' as managed_user_views')
                    ->whereColumn('managed_user_views.filter_set_id', $userViewTable.'.id')
                    ->where('managed_user_views.user_id', Config::auth()->id())
                    ->limit(1);
            }, 'managed_by_current_user_id')
            ->selectSub(function ($query) use ($pivotTable, $userViewTable) {
                $query->select('managed_user_views.sort_order')
                    ->from($pivotTable.' as managed_user_views')
                    ->whereColumn('managed_user_views.filter_set_id', $userViewTable.'.id')
                    ->where('managed_user_views.user_id', Config::auth()->id())
                    ->limit(1);
            }, 'managed_by_current_user_sort_order')
            ->selectSub(function ($query) use ($pivotTable, $userViewTable) {
                $query->select('managed_user_views.is_visible')
                    ->from($pivotTable.' as managed_user_views')
                    ->whereColumn('managed_user_views.filter_set_id', $userViewTable.'.id')
                    ->where('managed_user_views.user_id', Config::auth()->id())
                    ->limit(1);
            }, 'managed_by_current_user_is_visible')
            ->when(Config::managedDefaultViewsAreEnabled(), function ($query) use ($managedDefaultViewsTable, $userViewTable) {
                $query->selectSub(function ($query) use ($managedDefaultViewsTable, $userViewTable) {
                    $query->select('managed_default_views.id')
                        ->from($managedDefaultViewsTable.' as managed_default_views')
                        ->whereColumn('managed_default_views.view', $userViewTable.'.id')
                        ->where('resource', $this->getResourceName())
                        ->where('view_type', \Archilex\AdvancedTables\Enums\ViewType::UserView)
                        ->where('managed_default_views.user_id', Config::auth()->id())
                        ->when(Config::hasTenancy(), fn ($query) => $query->where('managed_default_views.tenant_id', Config::getTenantId()))
                        ->limit(1);
                }, 'is_current_default');
            })
            ->where('resource', $this->getResourceName())
            ->where(function ($query) {
                $query->managedByCurrentUser()
                    ->orWhere(function ($query) {
                        $query->global()->meetsMinimumStatus();
                    })
                    ->orWhere(function ($query) {
                        $query->public()->meetsMinimumStatus();
                    })
                    ->orWhere('user_id', Config::auth()?->id());
            })
            ->get();
    }

    /**
     * Override to use correct table names with wms_ prefix
     */
    public function getFavoriteUserViews(): Collection
    {
        if (! Config::userViewsAreEnabled()) {
            return collect();
        }

        $columns = ['id', 'user_id', 'name', 'resource', 'is_public', 'is_global_favorite', 'status', 'filters', 'sort_order', 'color', 'icon'];

        if (Config::hasTenancy()) {
            $columns[] = Config::getTenantColumn();
        }

        // Get table names from the model instances
        $userViewModel = app(Config::getUserView());
        $userViewTable = $userViewModel->getTable();
        $pivotTable = 'wms_filament_filter_set_user';

        return Config::getUserView()::query()
            ->select($columns)
            ->selectSub(function ($query) use ($pivotTable, $userViewTable) {
                // Use 'users' directly without prefix - users table doesn't have wms_ prefix
                $userTable = Config::getUserTable();
                $query->selectRaw('COUNT('.$userTable.'.'.Config::getUserTableKeyColumn().')')
                    ->from($pivotTable)
                    ->join($userTable, $userTable.'.'.Config::getUserTableKeyColumn().'', '=', $pivotTable.'.user_id')
                    ->whereColumn($pivotTable.'.filter_set_id', $userViewTable.'.id')
                    ->where($pivotTable.'.user_id', Config::auth()->id());
            }, 'is_managed_by_current_user')
            ->selectSub(function ($query) use ($pivotTable, $userViewTable) {
                $query->select('managed_user_views.id')
                    ->from($pivotTable.' as managed_user_views')
                    ->whereColumn('managed_user_views.filter_set_id', $userViewTable.'.id')
                    ->where('managed_user_views.user_id', Config::auth()->id())
                    ->limit(1);
            }, 'managed_by_current_user_id')
            ->selectSub(function ($query) use ($pivotTable, $userViewTable) {
                $query->select('managed_user_views.sort_order')
                    ->from($pivotTable.' as managed_user_views')
                    ->whereColumn('managed_user_views.filter_set_id', $userViewTable.'.id')
                    ->where('managed_user_views.user_id', Config::auth()->id())
                    ->limit(1);
            }, 'managed_by_current_user_sort_order')
            ->selectSub(function ($query) use ($pivotTable, $userViewTable) {
                $query->select('managed_user_views.is_visible')
                    ->from($pivotTable.' as managed_user_views')
                    ->whereColumn('managed_user_views.filter_set_id', $userViewTable.'.id')
                    ->where('managed_user_views.user_id', Config::auth()->id())
                    ->limit(1);
            }, 'managed_by_current_user_is_visible')
            ->where('resource', $this->getResourceName())
            ->where(function ($query) {
                $query->where(function ($query) {
                    $query->local()->managedByCurrentUser();
                })
                    ->orWhere(function ($query) {
                        $query->global()->favoritedByCurrentUser();
                    })
                    ->orWhere(function ($query) {
                        $query->global()->doesntBelongToCurrentUser()->unManagedByCurrentUser()->meetsMinimumStatus();
                    });
            })
            ->orderBy('is_managed_by_current_user', Config::getNewGlobalUserViewSortPosition() === 'after' ? 'desc' : 'asc')
            ->orderBy('managed_by_current_user_sort_order', 'asc')
            ->limit(20)
            ->get();
    }
}
