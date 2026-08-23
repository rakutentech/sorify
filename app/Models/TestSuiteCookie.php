<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestSuiteCookie extends Model
{
    protected $fillable = [
        'test_suite_id',
        'name',
        'value',
        'domain',
        'path',
        'url',
        'expires',
        'http_only',
        'secure',
        'same_site',
    ];

    protected $casts = [
        'expires' => 'integer',
        'http_only' => 'boolean',
        'secure' => 'boolean',
    ];

    public function testSuite(): BelongsTo
    {
        return $this->belongsTo(TestSuite::class);
    }
}
