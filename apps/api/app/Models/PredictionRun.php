<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PredictionRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'ml_job_id',
        'user_id',
        'name',
        'input_filename',
        'row_count',
        'status',
        'result',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'result' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function mlJob(): BelongsTo
    {
        return $this->belongsTo(MlJob::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
