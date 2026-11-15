<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MlJob extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'project_id',
        'dataset_id',
        'job_type',
        'status',
        'model_name',
        'target_column',
        'params',
        'result',
        'error_message',
        'queued_at',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'params' => 'array',
        'result' => 'array',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }
}
