<?php

namespace Tests\Feature\Api;

use App\Models\WmsPicker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    /**
     * Test login with valid credentials
     */
    public function test_can_login_with_valid_credentials(): void
    {
        // Ensure test user exists with known password
        $picker = WmsPicker::where('code', 'TEST001')->first();
        if (!$picker) {
            $picker = WmsPicker::create([
                'code' => 'TEST001',
                'name' => 'Test Picker',
                'password' => Hash::make('1234'),
                'default_warehouse_id' => 1,
                'is_active' => true,
            ]);
        } else {
            // Update password to ensure it matches test expectation
            $picker->update(['password' => Hash::make('1234')]);
        }

        $response = $this->withHeaders([
            'X-API-Key' => config('api.keys')[0] ?? 'test-key',
            'Accept' => 'application/json',
        ])->postJson('/api/auth/login', [
            'code' => 'TEST001',
            'password' => '1234',
            'device_id' => 'TEST_DEVICE',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'code' => 'LOGIN_SUCCESS',
                'result' => [
                    'data' => [
                        'picker' => [
                            'code' => 'TEST001',
                        ],
                    ],
                ],
            ]);
            
        $this->assertArrayHasKey('token', $response->json('result.data'));
    }

    /**
     * Test login with invalid credentials
     */
    public function test_cannot_login_with_invalid_credentials(): void
    {
        $response = $this->withHeaders([
            'X-API-Key' => config('api.keys')[0] ?? 'test-key',
            'Accept' => 'application/json',
        ])->postJson('/api/auth/login', [
            'code' => 'TEST001',
            'password' => 'wrong_password',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'code' => 'UNAUTHORIZED',
            ]);
    }

    /**
     * Test me endpoint
     */
    public function test_can_get_me_info(): void
    {
        $picker = WmsPicker::where('code', 'TEST001')->first();
        $token = $picker->createToken('test')->plainTextToken;

        $response = $this->withHeaders([
            'X-API-Key' => config('api.keys')[0] ?? 'test-key',
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ])->getJson('/api/me');

        $response->assertStatus(200)
            ->assertJson([
                'code' => 'SUCCESS',
                'result' => [
                    'data' => [
                        'code' => 'TEST001',
                    ],
                ],
            ]);
    }

    /**
     * Test logout endpoint
     */
    public function test_can_logout(): void
    {
        $picker = WmsPicker::where('code', 'TEST001')->first();
        $token = $picker->createToken('test')->plainTextToken;

        $response = $this->withHeaders([
            'X-API-Key' => config('api.keys')[0] ?? 'test-key',
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ])->postJson('/api/auth/logout');

        $response->assertStatus(200)
            ->assertJson([
                'code' => 'LOGOUT_SUCCESS',
            ]);
            
        // Token should be deleted
        $this->assertDatabaseMissing('personal_access_tokens', [
            'token' => hash('sha256', explode('|', $token)[1]),
        ]);
    }
}
