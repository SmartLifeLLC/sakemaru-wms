<?php

namespace App\Livewire;

use App\Models\Sakemaru\ClientSetting;
use App\Services\Navigation\MegaMenuBuilder;
use Filament\Facades\Filament;
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
