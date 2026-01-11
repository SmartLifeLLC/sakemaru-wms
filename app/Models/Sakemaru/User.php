<?php

namespace App\Models\Sakemaru;


use Archilex\AdvancedTables\Concerns\HasViews;
use Archilex\AdvancedTables\Support\Config;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class User extends Authenticatable implements FilamentUser
{
    use HasViews;


    protected $connection = 'sakemaru';
    protected $table = 'users';
    protected $primaryKey = 'id';
    public $timestamps = false;
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'client_id',
        'name',
        'kana_name',
        'email',
        'code',
        'default_branch_id',
        'default_warehouse_id',
        'permission_ship_rare_item',
        'invalidation_date',
        'is_active',
        'password',
        'created_at',
        'updated_at',
        'is_created_from_data_transfer',
        'creator_id',
        'last_updater_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
    public function branch() : BelongsTo
    {
        return $this->belongsTo(Branch::class,'default_branch_id', 'id');
    }
    public function warehouse() : BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'default_warehouse_id', 'id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id', 'id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_updater_id', 'id');
    }

    protected function mainRole(): Attribute
    {
        $role = $this->roles->first()->display_name ?? '';
        return Attribute::make(
            get: fn () => $role ?: '',
        );
    }

    public static function getTableName(): string
    {
        return with(new static)->getTable();
    }

    public function newQuery() : Builder
    {
        $query = parent::newQuery();
        $query = $query->where("users.is_active", true);
        // 一時的に消す
//        if (hasColumn($table_name, 'client_id') && !config('app.is_from_admin')) {
//            $client_id = auth()->user()?->client_id;
//            $query = $query->where("{$table_name}.client_id", $client_id);
//        }
        return $query;
    }

    public static function hasColumn(string $col): bool
    {
        return Schema::hasColumn(static::getTableName(), $col);
    }

    public static function getCodeIds($client_id): array
    {
        return User::where('client_id', $client_id)->pluck('id', 'code')->toArray();
    }

    public static function deleteAllForClient($client_id): int
    {
        \DB::table('model_has_roles')->whereIn('model_id',function($query) use ($client_id){
            $query->select('id')->from('users')->where('client_id',$client_id);
        })->delete();
        $query = \DB::table('users')->where('client_id',$client_id);
        $target_row_count = $query->count();
        $query->delete();
        return $target_row_count;

    }


    public function permissionShipRareItemAttribute($value): bool
    {
        return $value === 1;
    }

    /**
     * Override to use correct table name with wms_ prefix
     */
    public function managedUserViews(): BelongsToMany
    {
        return $this->belongsToMany(Config::getUserView(), 'wms_filament_filter_set_user', foreignPivotKey: 'user_id', relatedPivotKey: 'filter_set_id')
            ->withPivot('sort_order', 'is_visible', 'is_default');
    }

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
                    $query->selectRaw('COUNT(' . $userTable . '.' . Config::getUserTableKeyColumn() . ')')
                        ->from($pivotTable)
                        ->join($userTable, $userTable . '.' . Config::getUserTableKeyColumn() . '', '=', $pivotTable . '.user_id')
                        ->whereColumn($pivotTable . '.filter_set_id', $userViewTable . '.id')
                        ->where($pivotTable . '.user_id', Config::auth()->id());
                }, 'is_managed_by_current_user')
                ->selectSub(function ($query) use ($pivotTable, $userViewTable) {
                    $query->select('managed_user_views.id')
                        ->from($pivotTable . ' as managed_user_views')
                        ->whereColumn('managed_user_views.filter_set_id', $userViewTable . '.id')
                        ->where('managed_user_views.user_id', Config::auth()->id())
                        ->limit(1);
                }, 'managed_by_current_user_id')
                ->selectSub(function ($query) use ($pivotTable, $userViewTable) {
                    $query->select('managed_user_views.sort_order')
                        ->from($pivotTable . ' as managed_user_views')
                        ->whereColumn('managed_user_views.filter_set_id', $userViewTable . '.id')
                        ->where('managed_user_views.user_id', Config::auth()->id())
                        ->limit(1);
                }, 'managed_by_current_user_sort_order')
                ->selectSub(function ($query) use ($pivotTable, $userViewTable) {
                    $query->select('managed_user_views.is_visible')
                        ->from($pivotTable . ' as managed_user_views')
                        ->whereColumn('managed_user_views.filter_set_id', $userViewTable . '.id')
                        ->where('managed_user_views.user_id', Config::auth()->id())
                        ->limit(1);
                }, 'managed_by_current_user_is_visible')
                ->when(Config::managedDefaultViewsAreEnabled(), function ($query) use ($managedDefaultViewsTable, $userViewTable) {
                    $query->selectSub(function ($query) use ($managedDefaultViewsTable, $userViewTable) {
                        $query->select('managed_default_views.id')
                            ->from($managedDefaultViewsTable . ' as managed_default_views')
                            ->whereColumn('managed_default_views.view', $userViewTable . '.id')
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
                $query->selectRaw('COUNT(' . $userTable . '.' . Config::getUserTableKeyColumn() . ')')
                    ->from($pivotTable)
                    ->join($userTable, $userTable . '.' . Config::getUserTableKeyColumn() . '', '=', $pivotTable . '.user_id')
                    ->whereColumn($pivotTable . '.filter_set_id', $userViewTable . '.id')
                    ->where($pivotTable . '.user_id', Config::auth()->id());
            }, 'is_managed_by_current_user')
            ->selectSub(function ($query) use ($pivotTable, $userViewTable) {
                $query->select('managed_user_views.id')
                    ->from($pivotTable . ' as managed_user_views')
                    ->whereColumn('managed_user_views.filter_set_id', $userViewTable . '.id')
                    ->where('managed_user_views.user_id', Config::auth()->id())
                    ->limit(1);
            }, 'managed_by_current_user_id')
            ->selectSub(function ($query) use ($pivotTable, $userViewTable) {
                $query->select('managed_user_views.sort_order')
                    ->from($pivotTable . ' as managed_user_views')
                    ->whereColumn('managed_user_views.filter_set_id', $userViewTable . '.id')
                    ->where('managed_user_views.user_id', Config::auth()->id())
                    ->limit(1);
            }, 'managed_by_current_user_sort_order')
            ->selectSub(function ($query) use ($pivotTable, $userViewTable) {
                $query->select('managed_user_views.is_visible')
                    ->from($pivotTable . ' as managed_user_views')
                    ->whereColumn('managed_user_views.filter_set_id', $userViewTable . '.id')
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


    public function canAccessPanel(\Filament\Panel $panel): bool
    {
        return true;
    }

}
