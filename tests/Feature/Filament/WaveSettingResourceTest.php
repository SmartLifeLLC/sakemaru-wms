<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\WaveSettings\Pages\CreateWaveSetting;
use App\Filament\Resources\WaveSettings\Pages\EditWaveSetting;
use App\Filament\Resources\WaveSettings\Pages\ListWaveSettings;
use App\Models\Sakemaru\User;
use App\Models\WaveSetting;
use Livewire\Livewire;
use Tests\TestCase;

class WaveSettingResourceTest extends TestCase
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
            ->test(ListWaveSettings::class)
            ->assertSuccessful();
    }

    public function test_create_page_can_render(): void
    {
        Livewire::actingAs($this->user)
            ->test(CreateWaveSetting::class)
            ->assertSuccessful();
    }

    public function test_edit_page_can_render(): void
    {
        $record = WaveSetting::first();
        if (! $record) {
            $this->markTestSkipped('No WaveSetting record found');
        }

        Livewire::actingAs($this->user)
            ->test(EditWaveSetting::class, ['record' => $record->getRouteKey()])
            ->assertSuccessful();
    }
}
