<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class CacheController extends Controller
{
    public function clearAll(Request $request)
    {
        try {
            $key = $request->query('key');
            if (!$key || $key !== env('CACHE_CLEAR_KEY')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized access!'
                ], 401);
            }

            Artisan::call('cache:clear');
            Artisan::call('config:clear');
            Artisan::call('route:clear');
            Artisan::call('view:clear');
            Artisan::call('optimize:clear');

            Log::info('Cache cleared by API request from IP: ' . $request->ip());

            return response()->json([
                'status' => 'success',
                'message' => 'All Laravel caches cleared successfully!'
            ]);
        } catch (\Exception $e) {
            Log::error('Cache clear failed: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to clear cache!',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
