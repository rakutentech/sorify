<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestSuiteProxyRule extends Model
{
    protected $fillable = [
        'test_suite_id',
        'domain',
        'proxy',
    ];

    public function testSuite(): BelongsTo
    {
        return $this->belongsTo(TestSuite::class);
    }
}
