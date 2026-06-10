<?php

namespace App\Services\WebAuthn;

final readonly class AssertionResult
{
    public function __construct(public int $signCount) {}
}
