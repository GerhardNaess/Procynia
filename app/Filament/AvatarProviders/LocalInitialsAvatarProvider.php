<?php

namespace App\Filament\AvatarProviders;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Renders admin avatars locally as an inline SVG data URI.
 *
 * Filament's stock UiAvatarsProvider builds a URL against ui-avatars.com with the user's name in the
 * query string. That meant every admin page load sent an internal employee name to a third party,
 * and it was the one exception Procynia's CSP had to make for images (finding F-05).
 *
 * Same visual result — initials on the panel's gray background — with no outbound request, so the
 * name never leaves the deployment and img-src can stay 'self' data: blob:.
 */
class LocalInitialsAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $initials = str(Filament::getNameForDefaultAvatar($record))
            ->trim()
            ->explode(' ')
            ->map(fn (string $segment): string => filled($segment) ? mb_strtoupper(mb_substr($segment, 0, 1)) : '')
            ->filter()
            ->take(2)
            ->join('');

        $background = Color::convertToHex(FilamentColor::getColor('gray')[950] ?? Color::Gray[950]);

        // The name is user-controlled, so escape it before it reaches markup. Initials are already
        // reduced to single characters, but a name of "<" would still produce one.
        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            .'<rect width="100" height="100" fill="%s"/>'
            .'<text x="50" y="50" dy="0.35em" fill="#FFFFFF" font-family="sans-serif" '
            .'font-size="40" font-weight="500" text-anchor="middle">%s</text>'
            .'</svg>',
            htmlspecialchars($background, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            htmlspecialchars($initials, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        );

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
