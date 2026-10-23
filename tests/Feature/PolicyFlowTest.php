<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyFlowTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'client_request_id' => 'TEST-' . uniqid(),
            'agent_id' => 'TEST-AGENT',
            'customer_name' => 'PolicyFlow Test Customer',
            'date_of_birth' => '1998-04-10',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-12-01',
            'trip_end' => '2026-12-03',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ], $overrides);
    }

    public function test_valid_intake_calculates_premium_and_validated_status(): void
    {
        $response = $this->postJson(
            '/api/policy-requests',
            $this->validPayload()
        );

        $response->assertCreated()
            ->assertJsonPath('data.status', 'VALIDATED')
            ->assertJsonPath('data.trip_days', 3)
            ->assertJsonPath('data.premium_amount', '36.00');
    }

    public function test_invalid_trip_dates_are_rejected(): void
    {
        $response = $this->postJson(
            '/api/policy-requests',
            $this->validPayload([
                'client_request_id' => 'TEST-INVALID-DATES',
                'trip_start' => '2026-12-05',
                'trip_end' => '2026-12-03',
            ])
        );

        $response->assertUnprocessable();
    }

    public function test_duplicate_intake_is_idempotent(): void
    {
        $payload = $this->validPayload([
            'client_request_id' => 'TEST-IDEMPOTENT',
            'agent_id' => 'TEST-AGENT-IDEMPOTENT',
        ]);

        $first = $this->postJson('/api/policy-requests', $payload);

        $first->assertCreated();

        $second = $this->postJson('/api/policy-requests', $payload);

        $second->assertOk()
            ->assertJsonPath('idempotent_replay', true);

        $this->assertSame(
            $first->json('data.id'),
            $second->json('data.id')
        );
    }

    public function test_successful_submission_issues_exactly_one_policy(): void
    {
        $intake = $this->postJson(
            '/api/policy-requests',
            $this->validPayload()
        );

        $intake->assertCreated();

        $id = $intake->json('data.id');

        $this->postJson(
            "/api/policy-requests/{$id}/submit",
            ['scenario' => 'SUCCESS']
        )
            ->assertOk()
            ->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath(
                'data.policy_number',
                'POL-' . str_pad((string) $id, 8, '0', STR_PAD_LEFT)
            );

        $this->postJson(
            "/api/policy-requests/{$id}/submit",
            ['scenario' => 'SUCCESS']
        )
            ->assertOk()
            ->assertJsonPath('already_issued', true);

        $this->assertDatabaseHas('mock_insurer_issues', [
            'policy_request_id' => $id,
        ]);

        $this->assertSame(
            1,
            \Illuminate\Support\Facades\DB::table('mock_insurer_issues')
                ->where('policy_request_id', $id)
                ->count()
        );
    }

    public function test_timeout_can_be_reconciled_without_reissuing_policy(): void
    {
        $intake = $this->postJson(
            '/api/policy-requests',
            $this->validPayload()
        );

        $intake->assertCreated();

        $id = $intake->json('data.id');

        $this->postJson(
            "/api/policy-requests/{$id}/submit",
            ['scenario' => 'TIMEOUT_AFTER_ISSUE']
        )
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'PENDING_CONFIRMATION')
            ->assertJsonPath('reconciliation_required', true);

        $this->postJson("/api/policy-requests/{$id}/reconcile")
            ->assertOk()
            ->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath('reconciled', true);

        $this->assertDatabaseHas('mock_insurer_issues', [
            'policy_request_id' => $id,
        ]);

        $this->assertSame(
            1,
            \Illuminate\Support\Facades\DB::table('mock_insurer_issues')
                ->where('policy_request_id', $id)
                ->count()
        );

        $this->getJson("/api/policy-requests/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath('data.insurer_attempts.0.outcome', 'RECONCILED');
    }
}
