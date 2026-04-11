<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\WmsBuyerDeliveryCourseSwitchSettings\Pages\CreateWmsBuyerDeliveryCourseSwitchSetting;
use App\Filament\Resources\WmsBuyerDeliveryCourseSwitchSettings\Pages\EditWmsBuyerDeliveryCourseSwitchSetting;
use App\Filament\Resources\WmsBuyerDeliveryCourseSwitchSettings\Pages\ListWmsBuyerDeliveryCourseSwitchSettings;
use App\Models\Sakemaru\User;
use App\Models\WmsBuyerDeliveryCourseSwitchSetting;
use Livewire\Livewire;
use Tests\TestCase;

class WmsBuyerDeliveryCourseSwitchSettingResourceTest extends TestCase
{
    private ?User $user = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::first();
        if (! $this->user) {
            $this->markTestSkipped('No user found in database for authentication');
        }
    }

    public function test_list_page_can_render(): void
    {
        Livewire::actingAs($this->user)
            ->test(ListWmsBuyerDeliveryCourseSwitchSettings::class)
            ->assertSuccessful();
    }

    public function test_create_page_can_render(): void
    {
        Livewire::actingAs($this->user)
            ->test(CreateWmsBuyerDeliveryCourseSwitchSetting::class)
            ->assertSuccessful();
    }

    public function test_edit_page_can_render(): void
    {
        $record = WmsBuyerDeliveryCourseSwitchSetting::first();
        if (! $record) {
            $this->markTestSkipped('No WmsBuyerDeliveryCourseSwitchSetting record found');
        }

        Livewire::actingAs($this->user)
            ->test(EditWmsBuyerDeliveryCourseSwitchSetting::class, ['record' => $record->getRouteKey()])
            ->assertSuccessful();
    }
}
