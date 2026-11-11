<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

class HealthController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('SELECT 1');
        } catch (Throwable $exception) {
            throw new ServiceUnavailableHttpException(null, 'Database readiness check failed.', $exception);
        }

        return response()->json([
            'data' => [
                'application' => config('app.name'),
                'api_version' => config('api.version'),
                'status' => 'ready',
            ],
        ]);
    }
}
