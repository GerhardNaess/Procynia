<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class WikiHelpController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('App/Wiki/Help', [
            'help' => trans('procynia.wiki.help'),
            'back_url' => route('app.wiki.index'),
        ]);
    }
}
