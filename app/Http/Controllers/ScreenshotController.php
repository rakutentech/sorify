<?php

namespace App\Http\Controllers;

use App\Models\Screenshot;
use App\Models\TestResult;
use Illuminate\Support\Facades\Storage;

class ScreenshotController extends Controller
{
    public function index(TestResult $result)
    {
        return response()->json(
            $result->screenshots->map(fn ($s) => [
                'id'          => $s->id,
                'filename'    => $s->filename,
                'label'       => $s->label,
                'taken_at_ms' => $s->taken_at_ms,
                'url'         => $s->url,
            ])
        );
    }

    public function show(Screenshot $screenshot)
    {
        if (! Storage::disk('screenshots')->exists($screenshot->path)) {
            abort(404);
        }

        return response()->file(
            Storage::disk('screenshots')->path($screenshot->path)
        );
    }
}
