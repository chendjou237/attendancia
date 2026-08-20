<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    private const SUPPORTED = ['en', 'fr'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale
            ?? $request->session()->get('locale')
            ?? config('app.locale');

        if (in_array($locale, self::SUPPORTED, true)) {
            app()->setLocale($locale);
            // Filament's date/month column formatters (->date(),
            // ->dateTime(), ->translatedFormat()) read Carbon's own
            // static locale, not app()->getLocale() — without this,
            // every month/day name stays English regardless of the
            // line above.
            Carbon::setLocale($locale);
        }

        return $next($request);
    }
}
