<?php

namespace App\Http\Controllers\Handy;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Handy Terminal Web Application Controller
 *
 * BHT-M60ハンディターミナル向けWebベースアプリケーション
 */
class HandyController extends Controller
{
    /**
     * Display the login page
     */
    public function login(Request $request): View
    {
        $apiKeys = config('api.keys', []);
        $apiKey = $apiKeys[0] ?? '';

        return view('handy.login', [
            'apiKey' => $apiKey,
        ]);
    }

    /**
     * Display the home page (warehouse selection, incoming/outgoing buttons)
     *
     * URL Parameters:
     * - auth_key: Sanctum token from login
     */
    public function home(Request $request): View
    {
        $apiKeys = config('api.keys', []);
        $apiKey = $apiKeys[0] ?? '';
        $authKey = $request->query('auth_key');

        return view('handy.home', [
            'apiKey' => $apiKey,
            'authKey' => $authKey,
        ]);
    }

    /**
     * Display the outgoing (picking/shipping) SPA view
     *
     * URL Parameters:
     * - auth_key: Sanctum token from login
     * - warehouse_id: Warehouse ID
     */
    public function outgoing(Request $request): View
    {
        $apiKeys = config('api.keys', []);
        $apiKey = $apiKeys[0] ?? '';
        $authKey = $request->query('auth_key');
        $warehouseId = $request->query('warehouse_id');

        return view('handy.outgoing', [
            'apiKey' => $apiKey,
            'authKey' => $authKey,
            'warehouseId' => $warehouseId,
        ]);
    }
}
