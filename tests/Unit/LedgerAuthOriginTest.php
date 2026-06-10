<?php

namespace Tests\Unit;

use App\Http\Controllers\LedgerAuthController;
use App\Services\WebAuthn\AssertionValidator;
use App\Services\WebAuthn\CborDecoder;
use App\Services\WebAuthn\RegistrationValidator;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

class LedgerAuthOriginTest extends TestCase
{
    public function test_expected_origin_ignores_untrusted_origin_header(): void
    {
        config([
            'app.url' => 'https://clb-biahoi.net/notion_ledger_sync',
        ]);

        $request = Request::create('https://attacker.clb-biahoi.net/webauthn/authenticate', 'POST');
        $request->headers->set('Origin', 'https://attacker.clb-biahoi.net');

        $controller = new LedgerAuthController(
            new AssertionValidator,
            new RegistrationValidator(new CborDecoder),
        );
        $method = new ReflectionMethod($controller, 'resolveExpectedOrigin');

        $this->assertSame('https://clb-biahoi.net', $method->invoke($controller, $request));
    }
}
