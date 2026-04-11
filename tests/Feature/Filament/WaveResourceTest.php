<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Waves\Pages\ListWaves;
use App\Models\Sakemaru\User;
use Livewire\Livewire;
use Tests\TestCase;

class WaveResourceTest extends TestCase
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
            ->test(ListWaves::class)
            ->assertSuccessful();
    }
}
