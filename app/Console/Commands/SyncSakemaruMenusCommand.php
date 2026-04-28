<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Navigation\MegaMenuBuilder;
use Filament\Facades\Filament;
use Illuminate\Console\Command;
use Sakemaru\Auth\Models\SakemaruMenu;

class SyncSakemaruMenusCommand extends Command
{
    protected $signature = 'sakemaru:sync-menus {--user=admin@sakemaru.ai : メニュー収集に利用するユーザー}';

    protected $description = '現在のWMSメガメニューを sakemaru_menus へ同期する';

    public function handle(MegaMenuBuilder $builder): int
    {
        $user = User::query()->where('email', $this->option('user'))->first();
        if ($user === null) {
            $this->error('指定ユーザーが見つかりません。');
            return self::FAILURE;
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        auth()->guard('web')->login($user);

        $system = config('sakemaru.system', 'wms');
        $panel = 'admin';
        $rows = $builder->buildCatalogRows();
        $itemKeys = collect($rows)->pluck('item_key')->all();

        $existingKeys = SakemaruMenu::query()
            ->where('system', $system)
            ->where('panel', $panel)
            ->pluck('item_key')
            ->all();

        $created = count(array_diff($itemKeys, $existingKeys));
        $updated = count(array_intersect($itemKeys, $existingKeys));

        SakemaruMenu::query()->upsert(
            $rows,
            ['system', 'panel', 'item_key'],
            [
                'permission_resource',
                'target_system',
                'tab_key',
                'tab_label',
                'group_key',
                'group_label',
                'item_label',
                'url',
                'source_type',
                'is_external',
                'opens_in_new_tab',
                'tab_sort',
                'group_sort',
                'item_sort',
                'updated_at',
            ]
        );

        $deleted = SakemaruMenu::query()
            ->where('system', $system)
            ->where('panel', $panel)
            ->when($itemKeys !== [], fn ($query) => $query->whereNotIn('item_key', $itemKeys))
            ->delete();

        auth()->guard('web')->logout();

        $this->info("同期完了: created={$created}, updated={$updated}, deleted={$deleted}");

        return self::SUCCESS;
    }
}
