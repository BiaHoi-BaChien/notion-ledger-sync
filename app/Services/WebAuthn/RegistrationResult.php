<?php

namespace App\Services\WebAuthn;

final readonly class RegistrationResult
{
    public function __construct(
        public string $credentialId,
        public string $publicKey,
        public int $publicKeyAlgorithm,
        public int $signCount,
        public string $attestationType,
    ) {}
}
