<?php

namespace App\Support;

class FrontendUrl
{
    public static function get(): string
    {
        $configuredUrl = config('app.frontend_url');
        $allowedOrigins = config('cors.allowed_origins', []);

        if (is_string($configuredUrl) && in_array($configuredUrl, $allowedOrigins, true)) {
            return rtrim($configuredUrl, '/');
        }

        foreach ($allowedOrigins as $origin) {
            $host = is_string($origin) ? parse_url($origin, PHP_URL_HOST) : null;
            if (in_array($host, ['localhost', '127.0.0.1'], true)) {
                return rtrim($origin, '/');
            }
        }

        return rtrim(is_string($configuredUrl) ? $configuredUrl : 'http://localhost:5174', '/');
    }
}
