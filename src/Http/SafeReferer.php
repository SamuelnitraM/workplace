<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\Request;

/** Redirection back to the page the member came from, only when that page belongs to this site (no open redirect). */
final class SafeReferer
{
    public static function urlOr(Request $request, string $fallbackUrl): string
    {
        $referer = (string) $request->headers->get('referer', '');
        $isSameSite = $referer !== ''
            && parse_url($referer, PHP_URL_HOST) === $request->getHost()
            && in_array(parse_url($referer, PHP_URL_SCHEME), ['http', 'https'], true);
        return $isSameSite ? $referer : $fallbackUrl;
    }
}
