<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InsurerAttempt;
use App\Models\MockInsurerIssue;
use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use App\Services\PremiumCalculator;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PolicyRequestController extends Controller
{
    /**
     * Create a policy request and calculate its premium.
     */
    public function store(
        Request $request,
        PremiumCalculator $calculator
    ): JsonResponse {
        $validator = Validator::make($request->all(), [
            'client_request_id' => ['required', 'string', 'max:100'],
            'agent_id' => ['required', 'string', 'max:100'],
            'customer_name' => ['required', 'string', 'max:150'],
            'date_of_birth' => [
                'required',
                'date',
                'before_or_equal:trip_start',
            ],
            'destination' => [
                'required',
                'string',
                Rule::in(['DOMESTIC', 'INTERNATIONAL']),
            ],
            'trip_start' => ['required', 'date'],
            'trip_end' => [
                'required',
                'date',
                'after_or_equal:trip_start',
            ],
            'plan_code' => [
                'required',
                'string',
                Rule::in(['BASIC', 'PLUS', 'PREMIUM']),
            ],
            'sum_insured' => [
                'required',
                'integer',
                Rule::in([100000, 300000, 500000]),
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        try {
            $premium = $calculator->calculate(
                $data['date_of_birth'],
                $data['trip_start'],
                $data['trip_end'],
                $data['destination'],
                $data['plan_code'],
                (int) $data['sum_insured']
            );

            $policy = DB::transaction(function () use ($data, $premium) {
                $policy = PolicyRequest::create([
                    'client_request_id' => $data['client_request_id'],
                    'agent_id' => $data['agent_id'],
                    'customer_name' => $data['customer_name'],
                    'date_of_birth' => $data['date_of_birth'],
                    'destination' => strtoupper($data['destination']),
                    'trip_start' => $data['trip_start'],
                    'trip_end' => $data['trip_end'],
                    'plan_code' => strtoupper($data['plan_code']),
                    'sum_insured' => $data['sum_insured'],
                    'trip_days' => $premium['trip_days'],
                    'premium_amount' => $premium['premium_amount'],
                    'age_multiplier' => $premium['age_multiplier'],
                    'destination_multiplier' =>
                        $premium['destination_multiplier'],
                    'coverage_multiplier' =>
                        $premium['coverage_multiplier'],
                    'status' => PolicyRequest::RECEIVED,
                ]);

                PolicyStateHistory::create([
                    'policy_request_id' => $policy->id,
                    'from_status' => null,
                    'to_status' => PolicyRequest::RECEIVED,
                    'reason' => 'Policy request received.',
                ]);

                $policy->update([
                    'status' => PolicyRequest::VALIDATED,
                ]);

                PolicyStateHistory::create([
                    'policy_request_id' => $policy->id,
                    'from_status' => PolicyRequest::RECEIVED,
                    'to_status' => PolicyRequest::VALIDATED,
                    'reason' => 'Request validated and premium calculated.',
                ]);

                return $policy->fresh();
            });

            return response()->json([
                'message' => 'Policy request created successfully.',
                'data' => $policy,
                'idempotent_replay' => false,
            ], 201);
        } catch (QueryException $exception) {
            $sqlState = $exception->errorInfo[0] ?? null;
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);

            $isUniqueViolation =
                $sqlState === '23505' ||
                ($sqlState === '23000' && $driverCode === 19) ||
                ($sqlState === '23000' && $driverCode === 1062);

            if (!$isUniqueViolation) {
                throw $exception;
            }

            $existing = PolicyRequest::where(
                'agent_id',
                $data['agent_id']
            )->where(
                'client_request_id',
                $data['client_request_id']
            )->first();

            if ($existing) {
                return response()->json([
                    'message' => 'This request has already been received.',
                    'data' => $existing,
                    'idempotent_replay' => true,
                ], 200);
            }

            throw $exception;
        }
    }

    /**
     * Submit a validated policy to the mock insurer.
     *
     * SUCCESS: insurer issues and the response is received.
     * TIMEOUT_AFTER_ISSUE: insurer issues but the response is lost.
     */
    public function submit(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'scenario' => [
                'sometimes',
                'string',
                Rule::in(['SUCCESS', 'TIMEOUT_AFTER_ISSUE']),
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $scenario = $request->input('scenario', 'SUCCESS');

        $result = DB::transaction(function () use ($id, $scenario) {
            $policy = PolicyRequest::whereKey($id)
                ->lockForUpdate()
                ->first();

            if (!$policy) {
                return ['error' => 'not_found'];
            }

            if ($policy->status === PolicyRequest::ISSUED) {
                return [
                    'already_issued' => true,
                    'policy' => $policy,
                ];
            }

            if ($policy->status === PolicyRequest::PENDING_CONFIRMATION) {
                return [
                    'error' => 'pending_confirmation',
                    'policy' => $policy,
                ];
            }

            if ($policy->status !== PolicyRequest::VALIDATED) {
                return [
                    'error' => 'invalid_state',
                    'status' => $policy->status,
                ];
            }

            $previousStatus = $policy->status;

            $policy->update([
                'status' => PolicyRequest::SUBMITTED,
            ]);

            PolicyStateHistory::create([
                'policy_request_id' => $policy->id,
                'from_status' => $previousStatus,
                'to_status' => PolicyRequest::SUBMITTED,
                'reason' => 'Request submitted to mock insurer.',
            ]);

            $attemptNumber = InsurerAttempt::where(
                'policy_request_id',
                $policy->id
            )->count() + 1;

            $attempt = InsurerAttempt::create([
                'policy_request_id' => $policy->id,
                'attempt_number' => $attemptNumber,
                'operation' => 'ISSUE',
                'outcome' => 'STARTED',
                'started_at' => now(),
            ]);

            $providerReference = 'MOCK-' . $policy->id;

            $policyNumber = 'POL-' . str_pad(
                (string) $policy->id,
                8,
                '0',
                STR_PAD_LEFT
            );

            MockInsurerIssue::firstOrCreate(
                ['policy_request_id' => $policy->id],
                [
                    'provider_reference' => $providerReference,
                    'policy_number' => $policyNumber,
                    'status' => 'ISSUED',
                ]
            );

            if ($scenario === 'TIMEOUT_AFTER_ISSUE') {
                $policy->update([
                    'status' => PolicyRequest::PENDING_CONFIRMATION,
                    'provider_reference' => $providerReference,
                    'failure_reason' =>
                        'Insurer response timed out; reconciliation required.',
                ]);

                $attempt->update([
                    'outcome' => 'TIMEOUT',
                    'provider_reference' => $providerReference,
                    'error_message' =>
                        'Simulated timeout after insurer issuance.',
                    'finished_at' => now(),
                ]);

                PolicyStateHistory::create([
                    'policy_request_id' => $policy->id,
                    'from_status' => PolicyRequest::SUBMITTED,
                    'to_status' => PolicyRequest::PENDING_CONFIRMATION,
                    'reason' =>
                        'Insurer may have issued the policy; response timed out.',
                    'metadata' => [
                        'provider_reference' => $providerReference,
                        'scenario' => $scenario,
                    ],
                ]);

                return [
                    'timed_out' => true,
                    'policy' => $policy->fresh(),
                ];
            }

            $policy->update([
                'status' => PolicyRequest::ISSUED,
                'provider_reference' => $providerReference,
                'policy_number' => $policyNumber,
                'failure_reason' => null,
            ]);

            $attempt->update([
                'outcome' => 'SUCCESS',
                'provider_reference' => $providerReference,
                'finished_at' => now(),
            ]);

            PolicyStateHistory::create([
                'policy_request_id' => $policy->id,
                'from_status' => PolicyRequest::SUBMITTED,
                'to_status' => PolicyRequest::ISSUED,
                'reason' => 'Mock insurer confirmed successful issuance.',
                'metadata' => [
                    'provider_reference' => $providerReference,
                    'policy_number' => $policyNumber,
                ],
            ]);

            return [
                'policy' => $policy->fresh(),
            ];
        });

        if (($result['error'] ?? null) === 'not_found') {
            return response()->json([
                'message' => 'Policy request not found.',
            ], 404);
        }

        if (($result['error'] ?? null) === 'pending_confirmation') {
            return response()->json([
                'message' =>
                    'Policy is awaiting reconciliation; do not resubmit.',
                'data' => $result['policy'],
            ], 409);
        }

        if (($result['error'] ?? null) === 'invalid_state') {
            return response()->json([
                'message' =>
                    'Policy request cannot be submitted in its current state.',
                'current_status' => $result['status'],
            ], 409);
        }

        if (!empty($result['already_issued'])) {
            return response()->json([
                'message' => 'Policy has already been issued.',
                'data' => $result['policy'],
                'already_issued' => true,
            ], 200);
        }

        if (!empty($result['timed_out'])) {
            return response()->json([
                'message' =>
                    'Insurer response timed out. Reconciliation is required.',
                'data' => $result['policy'],
                'reconciliation_required' => true,
            ], 202);
        }

        return response()->json([
            'message' => 'Policy issued successfully.',
            'data' => $result['policy'],
        ], 200);
    }

    /**
     * Reconcile a pending request against the mock insurer's record.
     */
    public function reconcile(int $id): JsonResponse
    {
        $result = DB::transaction(function () use ($id) {
            $policy = PolicyRequest::whereKey($id)
                ->lockForUpdate()
                ->first();

            if (!$policy) {
                return ['error' => 'not_found'];
            }

            if ($policy->status === PolicyRequest::ISSUED) {
                return [
                    'already_issued' => true,
                    'policy' => $policy,
                ];
            }

            if (!in_array($policy->status, [
                PolicyRequest::PENDING_CONFIRMATION,
                PolicyRequest::NEEDS_RECONCILIATION,
            ], true)) {
                return [
                    'error' => 'invalid_state',
                    'status' => $policy->status,
                ];
            }

            $insurerIssue = MockInsurerIssue::where(
                'policy_request_id',
                $policy->id
            )->first();

            if (!$insurerIssue) {
                return ['error' => 'insurer_result_not_found'];
            }

            $previousStatus = $policy->status;

            if ($insurerIssue->status !== 'ISSUED') {
                return [
                    'error' => 'unknown_insurer_status',
                    'status' => $insurerIssue->status,
                ];
            }

            $policy->update([
                'status' => PolicyRequest::ISSUED,
                'provider_reference' => $insurerIssue->provider_reference,
                'policy_number' => $insurerIssue->policy_number,
                'failure_reason' => null,
            ]);

            $attempt = InsurerAttempt::where(
                'policy_request_id',
                $policy->id
            )->where('outcome', 'TIMEOUT')
                ->orderByDesc('attempt_number')
                ->first();

            if ($attempt) {
                $attempt->update([
                    'outcome' => 'RECONCILED',
                    'provider_reference' => $insurerIssue->provider_reference,
                    'finished_at' => now(),
                ]);
            }

            PolicyStateHistory::create([
                'policy_request_id' => $policy->id,
                'from_status' => $previousStatus,
                'to_status' => PolicyRequest::ISSUED,
                'reason' =>
                    'Reconciliation confirmed issuance with the mock insurer.',
                'metadata' => [
                    'provider_reference' => $insurerIssue->provider_reference,
                    'policy_number' => $insurerIssue->policy_number,
                    'reconciliation' => 'CONFIRMED',
                ],
            ]);

            return [
                'policy' => $policy->fresh(),
            ];
        });

        if (($result['error'] ?? null) === 'not_found') {
            return response()->json([
                'message' => 'Policy request not found.',
            ], 404);
        }

        if (($result['error'] ?? null) === 'invalid_state') {
            return response()->json([
                'message' => 'Policy is not awaiting reconciliation.',
                'current_status' => $result['status'],
            ], 409);
        }

        if (($result['error'] ?? null) === 'insurer_result_not_found') {
            return response()->json([
                'message' =>
                    'No insurer issuance record found; manual review is required.',
                'reconciliation_required' => true,
            ], 409);
        }

        if (($result['error'] ?? null) === 'unknown_insurer_status') {
            return response()->json([
                'message' => 'Insurer result is not yet confirmed.',
                'insurer_status' => $result['status'],
            ], 409);
        }

        if (!empty($result['already_issued'])) {
            return response()->json([
                'message' => 'Policy is already issued; no changes made.',
                'data' => $result['policy'],
                'already_issued' => true,
            ], 200);
        }

        return response()->json([
            'message' => 'Reconciliation successful. Policy issuance confirmed.',
            'data' => $result['policy'],
            'reconciled' => true,
        ], 200);
    }

    /**
     * Retrieve a policy with state history and insurer attempts.
     */
    public function show(int $id): JsonResponse
    {
        $policy = PolicyRequest::with([
            'stateHistories',
            'insurerAttempts',
        ])->find($id);

        if (!$policy) {
            return response()->json([
                'message' => 'Policy request not found.',
            ], 404);
        }

        return response()->json([
            'data' => $policy,
        ], 200);
    }

    /**
     * List requests with status filtering and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'status' => [
                'nullable',
                'string',
                Rule::in([
                    PolicyRequest::RECEIVED,
                    PolicyRequest::VALIDATED,
                    PolicyRequest::SUBMITTED,
                    PolicyRequest::PENDING_CONFIRMATION,
                    PolicyRequest::ISSUED,
                    PolicyRequest::FAILED,
                    PolicyRequest::NEEDS_RECONCILIATION,
                ]),
            ],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = PolicyRequest::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $policies = $query->paginate(
            (int) $request->query('per_page', 10)
        );

        return response()->json([
            'data' => $policies->items(),
            'pagination' => [
                'current_page' => $policies->currentPage(),
                'per_page' => $policies->perPage(),
                'total' => $policies->total(),
                'last_page' => $policies->lastPage(),
                'has_more_pages' => $policies->hasMorePages(),
            ],
        ], 200);
    }
}
