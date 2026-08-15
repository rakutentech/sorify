<?php

namespace App\Http\Controllers;

use App\Models\DashboardNote;
use Illuminate\Http\Request;

class DashboardNoteController extends Controller
{
    public function update(Request $request)
    {
        $data = $request->validate(['content' => ['nullable', 'string']]);

        DashboardNote::current()->update([
            'content'    => $data['content'] ?? '',
            'updated_by' => $request->user()->id,
        ]);

        return back();
    }
}
