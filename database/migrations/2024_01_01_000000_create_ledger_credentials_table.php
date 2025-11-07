<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ledger_credentials', static function (Blueprint $table): void {
            $table->id();
            $table->string('user_handle');
            $table->string('credential_id')->unique();
            $table->string('type')->default('public-key');
            $table->json('transports')->nullable();
            $table->string('attestation_type')->nullable();
            $table->text('public_key');
            $table->unsignedBigInteger('sign_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_credentials');
    }
};
