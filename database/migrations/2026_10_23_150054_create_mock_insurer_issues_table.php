<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mock_insurer_issues', function (Blueprint $table) {
            $table->id();

            $table->foreignId('policy_request_id')
                ->unique()
                ->constrained('policy_requests');

            $table->string('provider_reference', 150)->unique();
            $table->string('policy_number', 100)->unique();
            $table->string('status', 30)->default('ISSUED');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mock_insurer_issues');
    }
};
