<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('crate')->create('builds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('served_repo_id')->nullable()->constrained('served_repos')->nullOnDelete();
            $table->string('trigger');
            $table->string('status')->default('queued');
            $table->text('output')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('crate')->dropIfExists('builds');
    }
};
