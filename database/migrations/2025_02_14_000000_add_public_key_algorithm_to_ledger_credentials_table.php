<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ledger_credentials', static function (Blueprint $table): void {
            $table->integer('public_key_algorithm')->default(-8)->after('public_key');
        });
    }

    public function down(): void
    {
        Schema::table('ledger_credentials', static function (Blueprint $table): void {
            $table->dropColumn('public_key_algorithm');
        });
    }
};
