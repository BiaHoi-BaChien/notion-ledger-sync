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
        return [
            'user_handle' => 'ledger-form-user',
            'credential_id' => Str::uuid()->toString(),
            'type' => 'public-key',
            'transports' => null,
            'attestation_type' => 'none',
            'public_key' => base64_encode(random_bytes(32)),
            'sign_count' => 0,
        ];
    }
}
