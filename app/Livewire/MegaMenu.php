<?php

namespace App\Livewire;

use App\Models\Sakemaru\ClientSetting;
use App\Services\Navigation\MegaMenuBuilder;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class MegaMenu extends Component
{
    public array $menuStructure = [];

    public string $systemDateDisplay = '';

    public string $systemDayOfWeek = '';

    #[Locked]
    public bool $bookmarksLoaded = false;

    public function mount()
    {
        $this->menuStructure = $this->buildMenuStructure();

        $systemDate = ClientSetting::systemDate();
        $weekdays = ['日', '月', '火', '水', '木', '金', '土'];
        $this->systemDateDisplay = $systemDate->format('m.d');
        $this->systemDayOfWeek = $weekdays[$systemDate->dayOfWeek];
    }

    public function render()
    {
        return view('livewire.mega-menu');
    }

    public function loadBookmarks(): void
    {
        $this->bookmarksLoaded = true;
    }

    #[Computed]
    public function bookmarks(): ?Collection
    {
        $user = auth()->user();
        if (! $user) {
            return collect();
        }

        try {
            return $user->getConnection()->table('menu_bookmarks')
                ->where('user_id', $user->getKey())
                ->orderBy('order')
                ->orderBy('id')
                ->get(['id', 'title', 'url'])
                ->filter(fn ($bookmark) => $this->isBookmarkUrl($bookmark->url));
        } catch (QueryException $exception) {
            report($exception);

            return null;
        }
    }

    public function addBookmark(string $url, string $title): void
    {
        $user = auth()->user();
        abort_unless($user, 403);

        if (strlen($url) > 65535 || ! $this->isBookmarkUrl($url)
            || ! str_starts_with($url, request()->getSchemeAndHttpHost().'/')) {
            Notification::make()->danger()->title('このページはブックマークできません')->send();

            return;
        }

        $title = mb_substr(trim($title) ?: $url, 0, 255);
        $connection = $user->getConnection();
        $userId = $user->getKey();

        try {
            $connection->transaction(function () use ($connection, $user, $userId, $url, $title) {
                // Core and the other apps lock the same user row before registering a bookmark.
                $user->newQuery()->whereKey($userId)->lockForUpdate()->firstOrFail([$user->getKeyName()]);

                $bookmarks = $connection->table('menu_bookmarks');
                $existingUrls = (clone $bookmarks)->where('user_id', $userId)->where('url', $url)->pluck('url');
                if ($existingUrls->containsStrict($url)) {
                    return;
                }

                $timestamp = now();
                $bookmarks->insert([
                    'user_id' => $userId,
                    'url' => $url,
                    'title' => $title,
                    'order' => 9999,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            }, 3);
        } catch (QueryException $exception) {
            report($exception);
            Notification::make()->danger()->title('ブックマークを登録できませんでした')->send();

            return;
        }

        Notification::make()->success()->title('ブックマークに登録しました')->send();
    }

    private function isBookmarkUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true);
    }

    public function getUserMenuItems(): array
    {
        return Filament::getUserMenuItems();
    }

    protected function buildMenuStructure(): array
    {
        return app(MegaMenuBuilder::class)->build();
    }

    /**
     * 酒丸シリーズの外部システムメニュータブを構築
     */
    protected function buildSakemaruSeriesTab(): ?array
    {
        $appUrl = config('app.url');
        $parsed = parse_url($appUrl);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? 'localhost';

        // wms.sakemaru.test → sakemaru.test
        $baseDomain = preg_replace('/^[^.]+\./', '', $host);

        $sakemaruSystems = [
            ['label' => '酒丸（基幹システム）', 'subdomain' => null, 'desc' => '基幹業務システム'],
            ['label' => '酒丸千里眼', 'subdomain' => 'search', 'desc' => '高度検索システム'],
            ['label' => '酒丸乃蓮', 'subdomain' => 'trade', 'desc' => '取引管理システム'],
            ['label' => '酒丸帳場', 'subdomain' => 'documents', 'desc' => '帳票管理システム'],
            ['label' => '酒丸飛脚', 'subdomain' => 'delivery', 'desc' => '配送管理システム'],
            ['label' => '酒丸算盤', 'subdomain' => 'insights', 'desc' => '分析・レポートシステム'],
            ['label' => '酒丸通い帳', 'subdomain' => 'knowledge', 'desc' => 'ナレッジ管理システム'],
        ];

        $items = [];

        foreach ($sakemaruSystems as $system) {
            $url = $system['subdomain']
                ? "{$scheme}://{$system['subdomain']}.{$baseDomain}"
                : "{$scheme}://{$baseDomain}";

            $items[] = [
                'label' => $system['label'],
                'url' => $url,
                'isActive' => false,
                'icon' => 'heroicon-o-arrow-top-right-on-square',
                'openInNewTab' => true,
                'desc' => $system['desc'],
            ];
        }

        return [
            'id' => 'sakemaru_series',
            'label' => '酒丸',
            'icon' => 'fa-box',
            'groups' => [
                [
                    'label' => '酒丸シリーズ',
                    'icon' => 'heroicon-o-arrow-top-right-on-square',
                    'items' => $items,
                ],
            ],
        ];
    }

    protected function getIconString($icon): ?string
    {
        $iconClass = null;

        if ($icon instanceof \BackedEnum) {
            $iconClass = $icon->value;
        } elseif (is_string($icon)) {
            $iconClass = $icon;
        }

        if ($iconClass && (str_starts_with($iconClass, 'o-') || str_starts_with($iconClass, 's-')) && ! str_starts_with($iconClass, 'heroicon-')) {
            return 'heroicon-'.$iconClass;
        }

        return $iconClass;
    }
}
