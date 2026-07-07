<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('crate')->create('served_repos', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('url');
            $table->string('type');
            $table->text('source_credential')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('last_built_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('crate')->dropIfExists('served_repos');
    }
};
