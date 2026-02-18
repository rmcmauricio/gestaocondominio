<?php

namespace App\Services;

/**
 * Lightweight mobile detection via User-Agent and viewport (cookie set by JS).
 * Used to redirect condominos to the /m minisite after login when on mobile or small screen.
 */
class MobileDetect
{
    public const COOKIE_VIEW = 'view';
    public const VIEW_FULL = 'full';
    public const VIEW_MOBILE = 'mobile';

    /** Cookie set by JS when viewport width < breakpoint (e.g. 768px) */
    public const COOKIE_VIEWPORT_MOBILE = 'viewport_mobile';

    /** Breakpoint width (px) used by JS to set viewport_mobile cookie */
    public const VIEWPORT_BREAKPOINT = 768;

    /**
     * Check if the request prefers full (desktop) site via cookie.
     */
    public static function prefersFullSite(): bool
    {
        return isset($_COOKIE[self::COOKIE_VIEW]) && $_COOKIE[self::COOKIE_VIEW] === self::VIEW_FULL;
    }

    /**
     * Check if the client reported a small viewport (cookie set by JS).
     * Used when User-Agent is desktop (e.g. Chrome) but user has narrow window.
     */
    public static function isViewportMobile(): bool
    {
        return isset($_COOKIE[self::COOKIE_VIEWPORT_MOBILE]) && $_COOKIE[self::COOKIE_VIEWPORT_MOBILE] === '1';
    }

    /**
     * Check if the request is from a mobile device (User-Agent).
     * Does not consider viewport; use isViewportMobile() or cookie for that.
     */
    public static function isMobile(): bool
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if ($ua === '') {
            return false;
        }
        $ua = strtolower($ua);
        $mobileKeywords = [
            'android',
            'webos',
            'iphone',
            'ipod',
            'blackberry',
            'iemobile',
            'opera mini',
            'opera mobi',
            'mobile',
            'fennec',
            'minimo',
            'symbian',
            'windows phone',
        ];
        foreach ($mobileKeywords as $keyword) {
            if (str_contains($ua, $keyword)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether to serve mobile minisite: mobile UA or small viewport (cookie), and user has not chosen "full" view.
     */
    public static function shouldServeMobile(): bool
    {
        if (self::prefersFullSite()) {
            return false;
        }
        return self::isMobile() || self::isViewportMobile();
    }

    /**
     * Set cookie to prefer full site (used by "Versão completa" link).
     * Call before redirecting to /dashboard.
     */
    public static function setPreferFullSite(): void
    {
        $path = '/';
        $secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        $httponly = true;
        $samesite = 'Lax';
        $maxAge = 60 * 60 * 24 * 365; // 1 year
        setcookie(self::COOKIE_VIEW, self::VIEW_FULL, [
            'expires' => time() + $maxAge,
            'path' => $path,
            'secure' => $secure,
            'httponly' => $httponly,
            'samesite' => $samesite,
        ]);
    }
}
