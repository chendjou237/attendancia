<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The session-based half of SetLocale's fallback chain (user.locale ??
 * session('locale') ?? config('app.locale')) — a logged-in user without
 * an explicit account-level locale can flip their own display language
 * from the panel's user menu without an admin editing their record.
 * Route-level `whereIn` already restricts $locale to a supported value,
 * so nothing here needs to re-validate it.
 */
class LocaleController extends Controller
{
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        $request->session()->put('locale', $locale);

        return redirect()->back();
    }
}
