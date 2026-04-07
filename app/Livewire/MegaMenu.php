<?php

namespace App\Livewire;

use App\Enums\EMenuCategory;
use App\Models\Sakemaru\ClientSetting;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Livewire\Component;

class MegaMenu extends Component
{
    public array $menuStructure = [];

    public string $systemDateDisplay = '';

    public string $systemDayOfWeek = '';

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

    public function getUserMenuItems(): array
    {
        return Filament::getUserMenuItems();
    }

    protected function buildMenuStructure(): array
    {
        $navigation = Filament::getNavigation(); // Returns array of NavigationGroup

        // Map EMenuCategory to Tabs
        $tabs = [
            'order' => [
                'label' => '発注',
                'icon' => 'fa-cart-shopping',
                'categories' => [
                    EMenuCategory::AUTO_ORDER,
                    EMenuCategory::ORDER_HISTORY,
                    EMenuCategory::ORDER_SETTINGS,
                ],
            ],
            'inbound' => [
                'label' => '入荷',
                'icon' => 'fa-download',
                'categories' => [
                    EMenuCategory::INBOUND,
                ],
            ],
            'outbound' => [
                'label' => '出荷',
                'icon' => 'fa-arrow-up-from-bracket',
                'categories' => [
                    EMenuCategory::OUTBOUND,
                    EMenuCategory::SHORTAGE,
                    EMenuCategory::HORIZONTAL_SHIPMENT,
                ],
            ],
            'inventory' => [
                'label' => '在庫',
                'icon' => 'fa-boxes-stacked',
                'categories' => [
                    EMenuCategory::INVENTORY,
                ],
            ],
            'master' => [
                'label' => 'マスタ',
                'icon' => 'fa-database',
                'categories' => [
                    EMenuCategory::MASTER_WAREHOUSE,
                    EMenuCategory::MASTER_ORDER,
                    EMenuCategory::MASTER_PICKING,
                ],
            ],
            'analysis' => [
                'label' => '統計',
                'icon' => 'fa-chart-bar',
                'categories' => [
                    EMenuCategory::STATISTICS,
                    EMenuCategory::LOGS,
                ],
            ],
            'system' => [
                'label' => 'システム',
                'icon' => 'fa-cogs',
                'categories' => [
                    EMenuCategory::SETTINGS,
                    EMenuCategory::TEST_DATA,
                ],
            ],
            'guide' => [
                'label' => '解説',
                'icon' => 'fa-book-open',
                'categories' => [
                    EMenuCategory::GUIDE_ORDER,
                ],
            ],
        ];

        $structure = [];

        foreach ($tabs as $key => $tab) {
            $groupsForTab = [];

            foreach ($tab['categories'] as $category) {
                // Find the Filament NavigationGroup that matches this Category's label
                $categoryLabel = $category->label();

                // Filament::getNavigation returns array of NavigationGroup objects
                foreach ($navigation as $navGroup) {
                    if ($navGroup instanceof NavigationGroup && $navGroup->getLabel() === $categoryLabel) {
                        // Extract items
                        $items = $navGroup->getItems();
                        if (count($items) > 0) {
                            $groupsForTab[] = [
                                'label' => $categoryLabel,
                                'icon' => $category->icon(), // Heroicon name from Enum
                                'items' => collect($items)->map(fn (NavigationItem $item) => [
                                    'label' => $item->getLabel(),
                                    'url' => $item->getUrl(),
                                    'isActive' => $item->isActive(),
                                    'icon' => $this->getIconString($item->getIcon()),
                                    'openInSplitView' => $item->shouldOpenUrlInNewTab(),
                                ])->toArray(),
                            ];
                        }
                    }
                }
            }

            if (count($groupsForTab) > 0) {
                $structure[] = [
                    'id' => $key,
                    'label' => $tab['label'],
                    'icon' => $tab['icon'],
                    'groups' => $groupsForTab,
                ];
            }
        }

        // 酒丸シリーズ（外部システム）のメニューを追加
        $sakemaruTab = $this->buildSakemaruSeriesTab();
        if ($sakemaruTab) {
            $structure[] = $sakemaruTab;
        }

        return $structure;
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
                'icon' => null,
                'openInSplitView' => true,
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
