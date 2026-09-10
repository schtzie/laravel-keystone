<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keystoneables', function (Blueprint $table): void {
            $table->id();
            $table->string('keystoneable_type');
            $table->unsignedBigInteger('keystoneable_id');
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('client', 80)->unique();
            $table->string('secret', 80);
            $table->json('scopes')->nullable();
            $table->json('ip_allowlist')->nullable();
            $table->json('ip_blocklist')->nullable();
            $table->integer('rate_limit')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('grace_expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable()->index('keystoneables_revoked_at_index');
            $table->timestamps();
            $table->index(['keystoneable_type', 'keystoneable_id'], 'keystoneables_keystoneable_index');
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('keystoneables');
    }
};
