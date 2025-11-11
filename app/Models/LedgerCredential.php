<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LedgerCredential extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_handle',
        'credential_id',
        'type',
        'transports',
        'attestation_type',
        'public_key',
        'public_key_algorithm',
        'sign_count',
        'last_used_at',
    ];

    protected $casts = [
        'transports' => 'array',
        'sign_count' => 'int',
        'public_key_algorithm' => 'int',
        'last_used_at' => 'datetime',
    ];
}
