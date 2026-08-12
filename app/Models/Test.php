<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Test extends Model
{
    protected $fillable = [
        'test_suite_id',
        'name',
        'description',
        'uploaded_by',
        'playwright_code',
        'status',
        'last_run_at',
        'last_run_status',
    ];

    protected $casts = [
        'last_run_at' => 'datetime',
    ];

    public function testSuite(): BelongsTo
    {
        return $this->belongsTo(TestSuite::class);
    }

    public function testResults(): HasMany
    {
        return $this->hasMany(TestResult::class);
    }

    public function codeVersions(): HasMany
    {
        return $this->hasMany(TestCodeVersion::class)->orderByDesc('version_number');
    }
}
