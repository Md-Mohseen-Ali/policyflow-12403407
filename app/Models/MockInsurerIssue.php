<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MockInsurerIssue extends Model
{
    protected $fillable = [
        'policy_request_id',
        'provider_reference',
        'policy_number',
        'status',
    ];

    public function policyRequest(): BelongsTo
    {
        return $this->belongsTo(PolicyRequest::class);
    }
}
