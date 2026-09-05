<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Activity extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'payload'    => 'array',
        'created_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function suite(): BelongsTo
    {
        return $this->belongsTo(TestSuite::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
