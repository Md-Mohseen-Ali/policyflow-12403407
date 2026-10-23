<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PolicyRequest extends Model
{
    use HasFactory;

    public const RECEIVED = 'RECEIVED';
    public const VALIDATED = 'VALIDATED';
    public const SUBMITTED = 'SUBMITTED';
    public const PENDING_CONFIRMATION = 'PENDING_CONFIRMATION';
    public const ISSUED = 'ISSUED';
    public const FAILED = 'FAILED';
    public const NEEDS_RECONCILIATION = 'NEEDS_RECONCILIATION';

    protected $fillable = [
        'client_request_id',
        'agent_id',
        'customer_name',
        'date_of_birth',
        'destination',
        'trip_start',
        'trip_end',
        'plan_code',
        'sum_insured',
        'trip_days',
        'premium_amount',
        'age_multiplier',
        'destination_multiplier',
        'coverage_multiplier',
        'status',
        'provider_reference',
        'policy_number',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'trip_start' => 'date',
            'trip_end' => 'date',
            'sum_insured' => 'decimal:2',
            'premium_amount' => 'decimal:2',
            'age_multiplier' => 'decimal:2',
            'destination_multiplier' => 'decimal:2',
            'coverage_multiplier' => 'decimal:2',
            'trip_days' => 'integer',
        ];
    }

    public function stateHistories(): HasMany
    {
        return $this->hasMany(PolicyStateHistory::class);
    }

    public function insurerAttempts(): HasMany
    {
        return $this->hasMany(InsurerAttempt::class);
    }
}
