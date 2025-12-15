<?php

namespace App\Livewire;

use App\Enums\EMenuCategory;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Support\Collection;
use Livewire\Component;

class MegaMenu extends Component
{
    public array $menuStructure = [];

    public function mount()
    {
        $this->menuStructure = $this->buildMenuStructure();
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
            'outbound' => [
                'label' => '出荷',
                'icon' => 'fa-arrow-up-from-bracket',
                'categories' => [
                    EMenuCategory::OUTBOUND,
                    EMenuCategory::SHORTAGE,
                    EMenuCategory::HORIZONTAL_SHIPMENT,
                ],
            ],
            'inbound' => [
                'label' => '入荷',
                'icon' => 'fa-arrow-down-to-bracket',
                'categories' => [
                    EMenuCategory::INBOUND,
                ],
            ],
            'order' => [
                'label' => '発注',
                'icon' => 'fa-cart-shopping',
                'categories' => [
                    EMenuCategory::AUTO_ORDER,
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
                'label' => 'マスタ管理',
                'icon' => 'fa-database',
                'categories' => [
                    EMenuCategory::MASTER,
                ],
            ],
            'analysis' => [
                'label' => '分析・レポート',
                'icon' => 'fa-chart-bar',
                'categories' => [
                    EMenuCategory::STATISTICS,
                    EMenuCategory::LOGS,
                ],
            ],
            'system' => [
                'label' => 'システム設定',
                'icon' => 'fa-cogs',
                'categories' => [
                    EMenuCategory::SETTINGS,
                    EMenuCategory::TEST_DATA,
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
                                'items' => collect($items)->map(fn(NavigationItem $item) => [
                                    'label' => $item->getLabel(),
                                    'url' => $item->getUrl(),
                                    'isActive' => $item->isActive(),
                                    'icon' => $this->getIconString($item->getIcon()),
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

        return $structure;
    }

    protected function getIconString($icon): ?string
    {
        $iconClass = null;

        if ($icon instanceof \BackedEnum) {
            $iconClass = $icon->value;
        } elseif (is_string($icon)) {
            $iconClass = $icon;
        }

        if ($iconClass && (str_starts_with($iconClass, 'o-') || str_starts_with($iconClass, 's-')) && !str_starts_with($iconClass, 'heroicon-')) {
            return 'heroicon-' . $iconClass;
        }

        return $iconClass;
    }
}
