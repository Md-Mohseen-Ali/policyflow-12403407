<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_requests', function (Blueprint $table) {
            $table->id();

            // Idempotency: the same agent cannot submit the same request ID twice.
            $table->string('client_request_id', 100);
            $table->string('agent_id', 100);

            $table->string('customer_name', 150);
            $table->date('date_of_birth');
            $table->string('destination', 20);
            $table->date('trip_start');
            $table->date('trip_end');
            $table->string('plan_code', 20);
            $table->decimal('sum_insured', 12, 2);

            // Immutable premium snapshot.
            $table->unsignedInteger('trip_days');
            $table->decimal('premium_amount', 12, 2);
            $table->decimal('age_multiplier', 5, 2);
            $table->decimal('destination_multiplier', 5, 2);
            $table->decimal('coverage_multiplier', 5, 2);

            $table->string('status', 40)->default('RECEIVED');
            $table->string('provider_reference', 150)->nullable();
            $table->string('policy_number', 100)->nullable()->unique();
            $table->text('failure_reason')->nullable();

            $table->timestamps();

            $table->unique(
                ['agent_id', 'client_request_id'],
                'policy_requests_agent_client_unique'
            );
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_requests');
    }
};
