<?php

namespace App\Services\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Sakemaru\Auth\Services\PermissionCache;

class CachedPermissionCache extends PermissionCache
{
    private static array $memoryCache = [];

    public function getUserPermissions(Authenticatable $user): array
    {
        $key = $user->getAuthIdentifier();

        if (isset(self::$memoryCache[$key])) {
            return self::$memoryCache[$key];
        }

        $permissions = parent::getUserPermissions($user);
        self::$memoryCache[$key] = $permissions;

        return $permissions;
    }

    public function clearUser(Authenticatable $user): void
    {
        unset(self::$memoryCache[$user->getAuthIdentifier()]);
        parent::clearUser($user);
    }

    public function clearAll(): void
    {
        self::$memoryCache = [];
        parent::clearAll();
    }
}