<?php

namespace App\Http\Controllers;

use App\Services\Adjustment\AdjustmentService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class LedgerAdjustmentController extends Controller
{
    public function __construct(private AdjustmentService $service)
    {
    }

    public function show(): View
    {
        return view('ledger.adjustment', [
            'inputs' => [
                'bank_balance' => null,
                'cash_on_hand' => null,
            ],
            'result' => null,
            'status' => null,
            'passkey' => $this->getPasskeyConfig(),
            'passkeyRoutes' => $this->getPasskeyRoutes(),
        ]);
    }

    public function calculate(Request $request): View
    {
        $inputs = $this->validateInputs($request);

        $result = $this->service->calculate($inputs['bank_balance'], $inputs['cash_on_hand']);

        return view('ledger.adjustment', [
            'inputs' => $inputs,
            'result' => $result,
            'status' => null,
            'passkey' => $this->getPasskeyConfig(),
            'passkeyRoutes' => $this->getPasskeyRoutes(),
        ]);
    }

    public function register(Request $request): View
    {
        $inputs = $this->validateInputs($request);

        $result = $this->service->calculate($inputs['bank_balance'], $inputs['cash_on_hand']);

        $status = null;

        try {
            $this->service->registerAdjustment($result);
            $status = [
                'success' => true,
                'message' => '家計簿に調整額を登録しました。',
            ];
        } catch (Throwable $e) {
            report($e);
            $status = [
                'success' => false,
                'message' => 'Notionへの登録に失敗しました。時間をおいて再度お試しください。',
            ];
        }

        return view('ledger.adjustment', [
            'inputs' => $inputs,
            'result' => $result,
            'status' => $status,
            'passkey' => $this->getPasskeyConfig(),
            'passkeyRoutes' => $this->getPasskeyRoutes(),
        ]);
    }

    /**
     * @return array{bank_balance: float, cash_on_hand: float}
     */
    private function validateInputs(Request $request): array
    {
        $validated = $request->validate([
            'bank_balance' => ['required', 'numeric'],
            'cash_on_hand' => ['required', 'numeric'],
        ]);

        return [
            'bank_balance' => (float) $validated['bank_balance'],
            'cash_on_hand' => (float) $validated['cash_on_hand'],
        ];
    }

    /**
     * @return array{rp_id:string,rp_name:string,user_name:string,user_display_name:string,user_handle:string}
     */
    private function getPasskeyConfig(): array
    {
        if (! config()->has('services.ledger_passkey')) {
            throw new RuntimeException('ledger_passkey configuration is missing.');
        }

        $config = config('services.ledger_passkey');

        if (! is_array($config)) {
            throw new RuntimeException('ledger_passkey configuration is missing.');
        }

        return $config;
    }

    /**
     * @return array{register_options:string,register:string}
     */
    private function getPasskeyRoutes(): array
    {
        return [
            'register_options' => route('ledger.passkey.register.options'),
            'register' => route('ledger.passkey.register.store'),
        ];
    }
}
