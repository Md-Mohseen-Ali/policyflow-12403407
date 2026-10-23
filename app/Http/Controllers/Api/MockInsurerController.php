<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MockInsurerController extends Controller
{
    /**
     * Receive an insurer callback.
     *
     * Duplicate callbacks must never issue a second policy
     * or overwrite an already-issued policy.
     */
    public function callback(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'policy_request_id' => ['required', 'integer', 'min:1'],
            'provider_reference' => ['required', 'string', 'max:150'],
            'outcome' => [
                'required',
                'string',
                Rule::in(['SUCCESS', 'FAILED']),
            ],
            'policy_number' => [
                'nullable',
                'string',
                'max:100',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $result = DB::transaction(function () use ($data) {
            $policy = PolicyRequest::whereKey(
                $data['policy_request_id']
            )->lockForUpdate()->first();

            if (!$policy) {
                return ['error' => 'not_found'];
            }

            // Reject callbacks for a different insurer reference.
            if (
                $policy->provider_reference !== null
                && $policy->provider_reference !== $data['provider_reference']
            ) {
                return ['error' => 'reference_mismatch'];
            }

            // An issued policy is immutable through callbacks.
            if ($policy->status === PolicyRequest::ISSUED) {
                return [
                    'duplicate' => true,
                    'policy' => $policy,
                ];
            }

            if (!in_array($policy->status, [
                PolicyRequest::SUBMITTED,
                PolicyRequest::PENDING_CONFIRMATION,
                PolicyRequest::NEEDS_RECONCILIATION,
            ], true)) {
                return [
                    'error' => 'invalid_state',
                    'status' => $policy->status,
                ];
            }

            $previousStatus = $policy->status;

            if ($data['outcome'] === 'SUCCESS') {
                $policyNumber = $data['policy_number']
                    ?? ('POL-' . str_pad(
                        (string) $policy->id,
                        8,
                        '0',
                        STR_PAD_LEFT
                    ));

                $policy->update([
                    'status' => PolicyRequest::ISSUED,
                    'provider_reference' => $data['provider_reference'],
                    'policy_number' => $policyNumber,
                    'failure_reason' => null,
                ]);

                $newStatus = PolicyRequest::ISSUED;
                $reason = 'Insurer success callback received.';
            } else {
                $policy->update([
                    'status' => PolicyRequest::FAILED,
                    'provider_reference' => $data['provider_reference'],
                    'failure_reason' => 'Insurer reported issuance failure.',
                ]);

                $newStatus = PolicyRequest::FAILED;
                $reason = 'Insurer failure callback received.';
            }

            PolicyStateHistory::create([
                'policy_request_id' => $policy->id,
                'from_status' => $previousStatus,
                'to_status' => $newStatus,
                'reason' => $reason,
                'metadata' => [
                    'provider_reference' => $data['provider_reference'],
                    'callback_outcome' => $data['outcome'],
                ],
            ]);

            return [
                'duplicate' => false,
                'policy' => $policy->fresh(),
            ];
        });

        if (($result['error'] ?? null) === 'not_found') {
            return response()->json([
                'message' => 'Policy request not found.',
            ], 404);
        }

        if (($result['error'] ?? null) === 'reference_mismatch') {
            return response()->json([
                'message' => 'Provider reference does not match this request.',
            ], 409);
        }

        if (($result['error'] ?? null) === 'invalid_state') {
            return response()->json([
                'message' => 'Callback cannot be applied in the current state.',
                'current_status' => $result['status'],
            ], 409);
        }

        if ($result['duplicate']) {
            return response()->json([
                'message' => 'Policy is already issued; duplicate callback ignored.',
                'data' => $result['policy'],
                'duplicate_callback' => true,
            ], 200);
        }

        return response()->json([
            'message' => 'Insurer callback processed successfully.',
            'data' => $result['policy'],
            'duplicate_callback' => false,
        ], 200);
    }
}
