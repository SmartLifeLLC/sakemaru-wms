<?php

namespace App\Http\Controllers\Handy;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Handy Incoming SPA Controller
 *
 * BHT-M60ハンディターミナル向けWebベース入庫管理SPA
 */
class HandyIncomingController extends Controller
{
    /**
     * Display the incoming SPA view
     *
     * URL Parameters:
     * - auth_key: Sanctum token to skip login
     * - warehouse_id: Warehouse ID to skip warehouse selection
     */
    public function index(Request $request): View
    {
        // API Key for X-API-Key header (use first key from config)
        $apiKeys = config('api.keys', []);
        $apiKey = $apiKeys[0] ?? '';

        // URL parameters for auto-login and warehouse selection
        $authKey = $request->query('auth_key');
        $warehouseId = $request->query('warehouse_id');

        return view('handy.incoming', [
            'apiKey' => $apiKey,
            'authKey' => $authKey,
            'warehouseId' => $warehouseId,
        ]);
    }
}
