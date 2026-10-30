<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('crate')->create('exchange_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code_hash', 64)->unique();
            $table->string('audience');
            $table->string('user_id')->nullable();
            $table->string('project')->nullable();
            $table->string('requested_by');
            $table->timestamp('expires_at');
            $table->timestamp('redeemed_at')->nullable();
            $table->string('credential_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('crate')->dropIfExists('exchange_codes');
    }
};
