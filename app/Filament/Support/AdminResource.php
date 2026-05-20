<?php

namespace App\Filament\Support;

use Filament\Resources\Resource;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Sakemaru\Auth\Filament\HasSakemaruPermissions;

abstract class AdminResource extends Resource
{
    use HasSakemaruPermissions {
        getAuthorizationResponse as protected getSakemaruAuthorizationResponse;
        shouldRegisterNavigation as protected shouldRegisterSakemaruNavigation;
    }

    protected static string $permissionResource = '';

    protected static function resolvePermissionResource(): string
    {
        if (static::$permissionResource !== '') {
            return static::$permissionResource;
        }

        return Str::of(class_basename(static::class))
            ->beforeLast('Resource')
            ->kebab()
            ->value();
    }

    protected static function syncPermissionResource(): void
    {
        static::$permissionResource = static::resolvePermissionResource();
    }

    public static function getAuthorizationResponse(string $action, ?Model $record = null): Response
    {
        static::syncPermissionResource();

        return static::getSakemaruAuthorizationResponse($action, $record);
    }

    public static function shouldRegisterNavigation(): bool
    {
        static::syncPermissionResource();

        return parent::shouldRegisterNavigation()
            && static::shouldRegisterSakemaruNavigation();
    }
}
