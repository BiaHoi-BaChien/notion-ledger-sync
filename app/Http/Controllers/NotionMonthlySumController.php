<?php

namespace App\Http\Controllers;

use App\Http\Requests\MonthlySumRequest;
use App\Services\MonthlySumRunner;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class NotionMonthlySumController extends Controller
{
    public function handle(
        MonthlySumRequest $request,
        MonthlySumRunner $runner
    ) {
        $token = $request->header('X-Webhook-Token');
        if (! $token || $token !== config('services.webhook.token')) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $timezone = config('app.timezone', 'UTC');
        $yearMonth = $request->validated(
            'year_month',
            Carbon::now($timezone)->subMonth()->format('Y-m')
        );

        $runner->run($yearMonth);

        return response()->noContent();
    }
}
