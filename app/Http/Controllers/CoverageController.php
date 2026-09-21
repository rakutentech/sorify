<?php

namespace App\Http\Controllers;

use App\Models\TestRun;
use App\Services\CoverageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CoverageController extends Controller
{
    public function __construct(private readonly CoverageService $coverage) {}

    /**
     * Serve the run's merged HTML coverage report. The report references its
     * assets with relative paths, so the canonical URL must be the report
     * directory's index.html — the bare /report URL would resolve assets
     * against /coverage/ and 404. Any file inside the report directory is
     * addressable: /sorify/runs/{run}/coverage/report/index.html|base.css|…
     */
    public function report(Request $request, TestRun $run, ?string $path = null)
    {
        $this->authorize('view', $run->testSuite);

        // Redirect the bare URL to the canonical index.html so relative asset
        // and per-file links inside the report resolve correctly.
        if ($path === null || trim($path, '/') === '') {
            return redirect()->to($request->url().'/index.html');
        }

        $path = ltrim($path, '/');

        // Path traversal guard — only files inside this run's report dir.
        if ($path === '' || str_contains($path, '..') || str_contains($path, "\0")) {
            abort(404);
        }

        $disk = Storage::disk('coverage');
        $dir = $this->coverage->runDir($run->test_suite_id, $run->id).'/report/';

        // Istanbul names each per-file page after the script's full URL,
        // INCLUDING its query string (e.g. "hn.js?Ro13umrbtHiT536hT1ka.html").
        // Browsers send that "?" suffix as the request's query string, so a
        // plain lookup misses it — reattach the query string when the file
        // only exists with it. The query suffix is restricted to safe
        // characters so it cannot smuggle a traversal path.
        if (! $disk->exists($dir.$path)) {
            $qs = (string) $request->server('QUERY_STRING');

            if ($qs !== '' && preg_match('/^[A-Za-z0-9._=&-]+$/', $qs) && $disk->exists($dir.$path.'?'.$qs)) {
                $path = $path.'?'.$qs;
            }
        }

        $relative = $dir.$path;

        if (! $disk->exists($relative)) {
            abort(404);
        }

        // Content-Type is forced by extension: finfo content-sniffing
        // misidentifies CSS/JS under FrankenPHP (e.g. as text/plain),
        // which browsers then refuse to load — same reasoning as the
        // sorify/build/{path} route in routes/web.php.
        $mimeTypes = [
            'html' => 'text/html',
            'css' => 'text/css',
            'js' => 'text/javascript',
            'json' => 'application/json',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'map' => 'application/json',
        ];

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return response()->file($disk->path($relative), [
            'Content-Type' => $mimeTypes[$extension] ?? 'application/octet-stream',
        ]);
    }

    public function lcov(TestRun $run)
    {
        $this->authorize('view', $run->testSuite);

        $relative = $this->coverage->lcovPath($run);
        $disk = Storage::disk('coverage');

        if (! $disk->exists($relative)) {
            abort(404);
        }

        return response()->download(
            $disk->path($relative),
            "sorify-run-{$run->id}-lcov.info",
            ['Content-Type' => 'text/plain']
        );
    }
}
