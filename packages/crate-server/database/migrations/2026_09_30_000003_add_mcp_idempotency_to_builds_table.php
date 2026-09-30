<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('crate')->table('served_repos', function (Blueprint $table): void {
            $table->boolean('has_source_credential')->default(false);
        });

        DB::connection('crate')->table('served_repos')
            ->select(['id', 'source_credential'])
            ->whereNotNull('source_credential')
            ->orderBy('id')
            ->eachById(function (object $repo): void {
                try {
                    $hasCredential = filled(Crypt::decryptString((string) $repo->source_credential));
                } catch (Throwable) {
                    $hasCredential = true;
                }

                DB::connection('crate')->table('served_repos')->where('id', $repo->id)->update([
                    'has_source_credential' => $hasCredential,
                ]);
            });

        Schema::connection('crate')->table('builds', function (Blueprint $table): void {
            $table->string('scope')->default('full');
            $table->unsignedBigInteger('target_repo_id')->nullable();
            $table->string('target_repo_name')->nullable();
            $table->string('requested_by')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->string('request_fingerprint', 64)->nullable();
            $table->uuid('claim_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->unique(['requested_by', 'idempotency_key'], 'builds_mcp_idempotency_unique');
            $table->index(['trigger', 'status', 'lease_expires_at'], 'builds_mcp_recovery_index');
        });

        DB::connection('crate')->table('builds')
            ->select(['id', 'served_repo_id'])
            ->whereNotNull('served_repo_id')
            ->orderBy('id')
            ->eachById(function (object $build): void {
                $name = DB::connection('crate')->table('served_repos')
                    ->where('id', $build->served_repo_id)
                    ->value('name');

                if (! is_string($name)) {
                    return;
                }

                DB::connection('crate')->table('builds')->where('id', $build->id)->update([
                    'scope' => 'repository',
                    'target_repo_id' => $build->served_repo_id,
                    'target_repo_name' => $name,
                ]);
            });
    }

    public function down(): void
    {
        Schema::connection('crate')->table('builds', function (Blueprint $table): void {
            $table->dropIndex('builds_mcp_recovery_index');
            $table->dropUnique('builds_mcp_idempotency_unique');
            $table->dropColumn([
                'scope',
                'target_repo_id',
                'target_repo_name',
                'requested_by',
                'idempotency_key',
                'request_fingerprint',
                'claim_token',
                'lease_expires_at',
            ]);
        });

        Schema::connection('crate')->table('served_repos', function (Blueprint $table): void {
            $table->dropColumn('has_source_credential');
        });
    }
};
