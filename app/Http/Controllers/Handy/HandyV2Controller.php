<?php

namespace App\Http\Controllers\Handy;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class HandyV2Controller extends Controller
{
    public function index(): View
    {
        $apiKeys = config('api.keys', []);
        $apiKey = $apiKeys[0] ?? '';

        return view('handy-v2.app', [
            'apiKey' => $apiKey,
        ]);
    }
}
