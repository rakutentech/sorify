<?php

namespace App\Models;

use Cron\CronExpression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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

    protected $appends = ['test_ids'];

    public function testSuite(): BelongsTo
    {
        return $this->belongsTo(TestSuite::class);
    }

    /**
     * Tests the schedule runs. Empty = every active test in the suite runs.
     */
    public function tests(): BelongsToMany
    {
        return $this->belongsToMany(Test::class, 'test_schedule_test', 'test_suite_schedule_id', 'test_id');
    }

    /**
     * IDs of the tests this schedule runs, for the UI payload.
     *
     * @return array<int, int>
     */
    public function getTestIdsAttribute(): array
    {
        return $this->tests()->allRelatedIds()->all();
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
