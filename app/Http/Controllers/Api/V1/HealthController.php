<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends ApiController
{
    public function __invoke()
    {
        try {
            DB::connection()->getPdo();
            $database = 'ok';
        } catch (Throwable) {
            $database = 'unavailable';
        }

        $healthy = $database === 'ok';

        return $this->data([
            'service' => 'porsca-api',
            'version' => 'v1',
            'environment' => app()->environment(),
            'database' => $database,
            'paymongo_mode' => config('services.paymongo.mode'),
            'status' => $healthy ? 'ok' : 'degraded',
        ], $healthy ? 200 : 503);
    }
}
