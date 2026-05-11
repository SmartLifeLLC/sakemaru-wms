<?php

namespace App\Models\Sakemaru;

use App\Enums\TimeZone;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

class ClientSetting extends CustomModel
{
    use HasFactory;

    private const CACHE_TTL_SECONDS = 600;

    private const CACHE_KEY_FIRST = 'client_settings:first';

    private const CACHE_KEY_CLIENT_PREFIX = 'client_settings:client:';

    private static array $cachedSettings = [];

    protected $guarded = [];

    // client_settingsテーブルにはis_activeカラムがない
    protected bool $hasIsActiveColumn = false;

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    protected static function booted(): void
    {
        static::saved(fn (self $setting) => self::clearCachedSetting($setting->client_id));
        static::deleted(fn (self $setting) => self::clearCachedSetting($setting->client_id));
    }

    public static function cachedFirst(): ?self
    {
        return self::rememberSetting(self::CACHE_KEY_FIRST, fn () => self::query()->first());
    }

    public static function cachedByClient(?int $clientId): ?self
    {
        return self::rememberSetting(
            self::CACHE_KEY_CLIENT_PREFIX.($clientId ?? 'null'),
            fn () => self::query()->where('client_id', $clientId)->first(),
        );
    }

    public static function clearCachedSetting(?int $clientId = null): void
    {
        unset(self::$cachedSettings[self::CACHE_KEY_FIRST]);
        Cache::forget(self::CACHE_KEY_FIRST);

        $clientKey = self::CACHE_KEY_CLIENT_PREFIX.($clientId ?? 'null');
        unset(self::$cachedSettings[$clientKey]);
        Cache::forget($clientKey);
    }

    /**
     * @param  int|null  $client_id  (client id deprecated for a whole system)
     */
    public static function systemDate(bool $default_now = false, ?int $client_id = null): ?Carbon
    {
        //        if ($client_id) {
        //            $client_setting = ClientSetting::firstWhere('client_id', $client_id);
        //        } else {
        //            $client_setting = auth()->user()?->client?->setting;
        //        }
        //        if ($client_setting?->system_date) {
        //            return new Carbon($client_setting->system_date);
        //        }
        //        if ($default_now) {
        //            return TimeZone::TOKYO->now();
        //        }

        $systemDate = self::cachedFirst()?->system_date;

        if ($systemDate) {
            return new Carbon($systemDate);
        }

        if ($default_now) {
            return TimeZone::TOKYO->now();
        }

        return null;

    }

    public static function systemYesterdayYMD(): string
    {
        return self::systemDate()->copy()->subDay()->format('Y-m-d');
    }

    public static function systemDateYMD(): string
    {
        return self::systemDate()->format('Y-m-d');
    }

    public static function systemMonth(): ?int
    {
        $client_setting = auth()->user()?->client?->setting;
        if ($client_setting?->system_month) {
            return $client_setting->system_month;
        }

        return null;
    }

    public static function endOfSystemMonth(bool $default_now = false): ?Carbon
    {
        $client_setting = auth()->user()?->client?->setting;
        $client_setting->refresh();
        $date = null;
        if ($client_setting?->system_month) {
            $date = Carbon::create($client_setting->system_year, $client_setting->system_month, 1);
        } else {
            if ($default_now) {
                $date = TimeZone::TOKYO->now();
            }
        }

        return $date?->endOfMonth();
    }

    public static function isLocked(): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        return cacheValue("locked-{$user->id}", function () use ($user) {
            return (bool) $user->client?->setting?->is_locked;
        });
    }

    /**
     * 操作をロックする
     */
    public static function lock(bool $is_lock = true): void
    {
        $user = auth()->user();
        $setting = $user?->client?->setting;
        if ($setting) {
            $setting->is_locked = $is_lock;
            $setting->save();
        }
        Artisan::call('cache:clear file');

        if ($is_lock) {
            sleep(config('app.lock_sleep_time')); // テストでわかりやすくするために一定時間sleep
        }
    }

    /**
     * 操作をアンロックする
     */
    public static function unlock(): void
    {
        self::lock(false);
    }

    public static function hasWms()
    {
        $client_id = auth()?->user()?->client_id ?? null;

        return self::cachedByClient($client_id)?->has_wms ?? false;
    }

    private static function rememberSetting(string $key, callable $resolver): ?self
    {
        if (array_key_exists($key, self::$cachedSettings)) {
            return self::$cachedSettings[$key];
        }

        return self::$cachedSettings[$key] = Cache::remember(
            $key,
            self::CACHE_TTL_SECONDS,
            $resolver,
        );
    }

    public static function authSetting(): ?self
    {
        $user = auth()->user();

        return $user?->client?->setting;
    }

    /**
     * 酒丸シリーズのサブドメインURLを生成
     *
     * @param  string  $subdomain  search, trade, documents, delivery, insights, knowledge
     */
    public static function getSakemaruSubdomainUrl(string $subdomain): string
    {
        $coreUrl = parse_url(config('app.core_url'));
        $scheme = $coreUrl['scheme'] ?? 'https';
        $host = $coreUrl['host'] ?? 'localhost';

        return "{$scheme}://{$subdomain}.{$host}";
    }
}
