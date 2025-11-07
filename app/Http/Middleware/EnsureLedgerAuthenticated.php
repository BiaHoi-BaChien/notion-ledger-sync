<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EnsureLedgerAuthenticated
{
    public function handle(Request $request, Closure $next): Response|RedirectResponse
    {
        if (! $request->session()->get('ledger_authenticated', false)) {
            $request->session()->put('url.intended', $request->fullUrl());

            return redirect()->route('ledger.login.form');
        }

        return $next($request);
    }
}
