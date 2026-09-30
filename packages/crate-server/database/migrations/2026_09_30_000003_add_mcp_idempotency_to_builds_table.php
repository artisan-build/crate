<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('crate')->table('builds', function (Blueprint $table): void {
            $table->string('requested_by')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->string('request_fingerprint', 64)->nullable();
            $table->unique(['requested_by', 'idempotency_key'], 'builds_mcp_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('crate')->table('builds', function (Blueprint $table): void {
            $table->dropUnique('builds_mcp_idempotency_unique');
            $table->dropColumn(['requested_by', 'idempotency_key', 'request_fingerprint']);
        });
    }
};
