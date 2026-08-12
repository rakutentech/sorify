<?php

namespace App\Models;

use Cron\CronExpression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestSuiteSchedule extends Model
{
    protected $fillable = [
        'test_suite_id',
        'cron_expression',
        'timezone',
        'is_enabled',
        'last_run_at',
        'next_run_at',
        'created_by',
    ];

    protected $casts = [
        'is_enabled'  => 'boolean',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
    ];

    public function testSuite(): BelongsTo
    {
        return $this->belongsTo(TestSuite::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function nextRunAfter(\DateTimeInterface $after): \DateTime
    {
        return (new CronExpression($this->cron_expression))
            ->getNextRunDate($after, 0, false, $this->timezone ?: 'UTC');
    }
}
