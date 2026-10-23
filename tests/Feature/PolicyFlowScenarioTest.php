<?php

namespace Tests\Feature;

use App\Models\InsurerAttempt;
use App\Models\MockInsurerIssue;
use App\Models\PolicyRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyFlowScenarioTest extends TestCase
{
    use RefreshDatabase;

    private function createPolicy(string $clientRequestId): int
    {
        $response = $this->postJson('/api/policy-requests', [
            'client_request_id' => $clientRequestId,
            'agent_id' => 'AGENT-SCENARIO-1',
            'customer_name' => 'Scenario Test Customer',
            'date_of_birth' => '1998-04-12',
            'destination' => 'DOMESTIC',
            'trip_start' => '2027-01-10',
            'trip_end' => '2027-01-12',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ]);

        $response->assertCreated();

        return (int) $response->json('data.id');
    }

    public function test_unsupported_plan_and_coverage_are_rejected(): void
    {
        $payload = [
            'client_request_id' => 'SCENARIO-INVALID-PLAN',
            'agent_id' => 'AGENT-SCENARIO-INVALID',
            'customer_name' => 'Scenario Test Customer',
            'date_of_birth' => '1998-04-12',
            'destination' => 'DOMESTIC',
            'trip_start' => '2027-01-10',
            'trip_end' => '2027-01-12',
            'plan_code' => 'UNSUPPORTED',
            'sum_insured' => 100000,
        ];

        $this->postJson('/api/policy-requests', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('plan_code');

        $payload['client_request_id'] = 'SCENARIO-INVALID-COVERAGE';
        $payload['plan_code'] = 'BASIC';
        $payload['sum_insured'] = 200000;

        $this->postJson('/api/policy-requests', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('sum_insured');
    }

    public function test_temporary_failure_can_be_retried_without_duplicate_issuance(): void
    {
        $id = $this->createPolicy('SCENARIO-TEMP-001');

        $this->postJson("/api/policy-requests/{$id}/submit", [
            'scenario' => 'TEMPORARY_FAILURE',
        ])->assertStatus(503)
          ->assertJsonPath('retryable', true)
          ->assertJsonPath('data.status', PolicyRequest::FAILED);

        $this->postJson("/api/policy-requests/{$id}/submit", [
            'scenario' => 'SUCCESS',
        ])->assertOk()
          ->assertJsonPath('data.status', PolicyRequest::ISSUED);

        $this->assertSame(2, InsurerAttempt::where('policy_request_id', $id)->count());
        $this->assertSame(1, MockInsurerIssue::where('policy_request_id', $id)->count());
    }

    public function test_delayed_callback_completes_issuance_once(): void
    {
        $id = $this->createPolicy('SCENARIO-DELAYED-001');

        $this->postJson("/api/policy-requests/{$id}/submit", [
            'scenario' => 'DELAYED_CALLBACK',
        ])->assertStatus(202)
          ->assertJsonPath('callback_pending', true)
          ->assertJsonPath('data.status', PolicyRequest::PENDING_CONFIRMATION);

        $this->postJson('/api/mock-insurer/callback', [
            'policy_request_id' => $id,
            'provider_reference' => 'MOCK-' . $id,
            'outcome' => 'SUCCESS',
            'policy_number' => 'POL-' . str_pad((string) $id, 8, '0', STR_PAD_LEFT),
        ])->assertOk()
          ->assertJsonPath('duplicate_callback', false)
          ->assertJsonPath('data.status', PolicyRequest::ISSUED);

        $this->assertSame(1, MockInsurerIssue::where('policy_request_id', $id)->count());
    }

    public function test_duplicate_callback_scenario_ignores_replayed_success_callback(): void
    {
        $id = $this->createPolicy('SCENARIO-DUPLICATE-001');

        $this->postJson("/api/policy-requests/{$id}/submit", [
            'scenario' => 'DUPLICATE_CALLBACK',
        ])->assertStatus(202)
          ->assertJsonPath('duplicate_callback_scenario', true)
          ->assertJsonPath('data.status', PolicyRequest::PENDING_CONFIRMATION);

        $callback = [
            'policy_request_id' => $id,
            'provider_reference' => 'MOCK-' . $id,
            'outcome' => 'SUCCESS',
            'policy_number' => 'POL-' . str_pad((string) $id, 8, '0', STR_PAD_LEFT),
        ];

        $this->postJson('/api/mock-insurer/callback', $callback)
            ->assertOk()
            ->assertJsonPath('duplicate_callback', false)
            ->assertJsonPath('data.status', PolicyRequest::ISSUED);

        $this->postJson('/api/mock-insurer/callback', $callback)
            ->assertOk()
            ->assertJsonPath('duplicate_callback', true)
            ->assertJsonPath('data.status', PolicyRequest::ISSUED);

        $policy = PolicyRequest::findOrFail($id);
        $this->assertSame('POL-' . str_pad((string) $id, 8, '0', STR_PAD_LEFT), $policy->policy_number);
        $this->assertSame(1, MockInsurerIssue::where('policy_request_id', $id)->count());
    }

    public function test_duplicate_callback_cannot_overwrite_issued_policy(): void
    {
        $id = $this->createPolicy('SCENARIO-DUP-CALLBACK-001');

        $this->postJson("/api/policy-requests/{$id}/submit", [
            'scenario' => 'SUCCESS',
        ])->assertOk()
          ->assertJsonPath('data.status', PolicyRequest::ISSUED);

        $original = PolicyRequest::findOrFail($id);
        $originalPolicyNumber = $original->policy_number;

        $this->postJson('/api/mock-insurer/callback', [
            'policy_request_id' => $id,
            'provider_reference' => 'MOCK-' . $id,
            'outcome' => 'FAILED',
        ])->assertOk()
          ->assertJsonPath('duplicate_callback', true)
          ->assertJsonPath('data.status', PolicyRequest::ISSUED);

        $this->assertSame($originalPolicyNumber, PolicyRequest::findOrFail($id)->policy_number);
        $this->assertSame(1, MockInsurerIssue::where('policy_request_id', $id)->count());
    }
}
