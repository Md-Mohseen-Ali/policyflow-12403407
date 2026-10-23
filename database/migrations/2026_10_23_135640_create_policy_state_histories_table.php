<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_state_histories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('policy_request_id')
                ->constrained('policy_requests')
                ->cascadeOnDelete();

            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->string('reason', 255)->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['policy_request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_state_histories');
    }
};
