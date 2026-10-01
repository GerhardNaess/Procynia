<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Kvalitet module's landing page.
 *
 * The module exists in the rail as a real destination rather than a dimmed placeholder, so this
 * gives it a page to land on while the module itself is being built. It reads nothing and decides
 * nothing; the quality work that exists today still lives where it always has, in Wiki review and
 * claim approval, and this page points at it.
 */
class QualityController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('App/Quality/Index');
    }
}
