<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('keystoneable_type');
            $table->unsignedBigInteger('keystoneable_id');
            $table->string('name');
            $table->string('api_key', 80)->unique();
            $table->string('secret_key', 80);
            $table->json('scopes')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['keystoneable_type', 'keystoneable_id'], 'api_keys_keystoneable_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
