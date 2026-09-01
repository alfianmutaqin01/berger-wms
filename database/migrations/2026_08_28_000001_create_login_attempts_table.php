<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('email', 150);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->boolean('is_successful');
            $table->string('failure_reason', 50)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['email', 'created_at'], 'idx_login_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_attempts');
    }
};
