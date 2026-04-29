<?php

namespace App\Filament\Support;

use Filament\Pages\Page;
use Illuminate\Support\Str;
use Sakemaru\Auth\Services\PermissionService;

abstract class AdminPage extends Page
{
    protected static string $permissionResource = '';

    protected static string $permissionAction = 'view';

    protected static function resolvePermissionResource(): string
    {
        if (static::$permissionResource !== '') {
            return static::$permissionResource;
        }

        return Str::of(class_basename(static::class))
            ->beforeLast('Page')
            ->kebab()
            ->value();
    }

    protected static function resolvePermissionName(): string
    {
        return sprintf(
            '%s.%s.%s',
            config('sakemaru.system'),
            static::resolvePermissionResource(),
            static::$permissionAction
        );
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null
            && app(PermissionService::class)->check($user, static::resolvePermissionName());
    }

    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation() && static::canAccess();
    }
}
