<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insurer_attempts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('policy_request_id')
                ->constrained('policy_requests')
                ->cascadeOnDelete();

            $table->unsignedInteger('attempt_number');
            $table->string('operation', 40);
            $table->string('outcome', 40)->default('STARTED');
            $table->string('provider_reference', 150)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['policy_request_id', 'attempt_number'],
                'insurer_attempts_request_attempt_unique'
            );
            $table->index(['policy_request_id', 'operation']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurer_attempts');
    }
};
