<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WikiGraphController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $rawRunId = $request->query('run_id');
        $rawPageId = $request->query('page_id');

        return Inertia::render('App/Wiki/Graph', [
            'initialRunId' => $rawRunId !== null && $rawRunId !== '' ? (int) $rawRunId : null,
            'initialPageId' => $rawPageId !== null && $rawPageId !== '' ? (int) $rawPageId : null,
        ]);
    }
}
