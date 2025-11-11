<?php

namespace Database\Factories;

use App\Models\LedgerCredential;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LedgerCredential>
 */
class LedgerCredentialFactory extends Factory
{
    protected $model = LedgerCredential::class;

    public function definition(): array
    {
        $keyPair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keyPair);
        $publicKeyDer = hex2bin('302a300506032b6570032100');

        if ($publicKeyDer === false) {
            $publicKeyDer = '';
        }

        return [
            'user_handle' => 'ledger-form-user',
            'credential_id' => Str::uuid()->toString(),
            'type' => 'public-key',
            'transports' => null,
            'attestation_type' => 'none',
            'public_key' => base64_encode($publicKeyDer . $publicKey),
            'public_key_algorithm' => -8,
            'sign_count' => 0,
        ];
    }
}
