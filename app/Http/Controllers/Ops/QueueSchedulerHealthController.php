<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Services\Operations\QueueSchedulerHealthService;
use Illuminate\Http\JsonResponse;

class QueueSchedulerHealthController extends Controller
{
    public function check(QueueSchedulerHealthService $service): JsonResponse
    {
        $result = $service->evaluate();

        return response()->json($result, $result['ok'] ? 200 : 503);
    }
}
