<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keystone_access_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keystone_id')->nullable()->index();
            $table->string('keystoneable_type')->nullable();
            $table->unsignedBigInteger('keystoneable_id')->nullable();
            $table->string('tenant_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->string('method', 10);
            $table->string('path', 1024);
            $table->json('scopes_used')->nullable();
            $table->unsignedSmallInteger('status_code');
            $table->string('event', 40);
            $table->timestamp('created_at')->useCurrent()->index('keystone_access_logs_created_at_index');
            $table->index(['keystone_id', 'created_at'], 'keystone_access_logs_key_time_index');
            $table->index(['keystoneable_type', 'keystoneable_id', 'created_at'], 'keystone_access_logs_owner_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keystone_access_logs');
    }
};
