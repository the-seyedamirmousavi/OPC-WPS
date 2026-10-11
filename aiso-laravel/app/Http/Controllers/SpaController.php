<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/** Serves the single-page application shell; React Router renders the screens. */
class SpaController extends Controller
{
    public function index(): View
    {
        return view('app');
    }
}
