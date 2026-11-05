<?php

declare(strict_types=1);

namespace Seolyticize;

/**
 * What a website itself needs from Seolyticize, for any PHP site — Laravel,
 * Symfony, plain PHP. (WordPress has its own plugin.)
 *
 *   echo \Seolyticize\Site::analyticsTag('SITE_KEY', ['exclude' => ['/account/*']]);
 */
final class Site
{
    public const DEFAULT_BASE_URL = 'https://seolyticize.com';

    /**
     * The cookieless analytics <script> for every page's <head>, escaped.
     *
     * @param array{exclude?: string[], include?: string[], track?: string[], baseUrl?: string} $options
     */
    public static function analyticsTag(string $siteKey, array $options = []): string
    {
        $base = rtrim($options['baseUrl'] ?? self::DEFAULT_BASE_URL, '/');
        $attributes = ['defer', sprintf('data-site="%s"', self::escape($siteKey))];
        if (!empty($options['exclude'])) {
            $attributes[] = sprintf('data-exclude="%s"', self::escape(implode(',', $options['exclude'])));
        }
        if (!empty($options['include'])) {
            $attributes[] = sprintf('data-include="%s"', self::escape(implode(',', $options['include'])));
        }
        if (!empty($options['track'])) {
            $attributes[] = sprintf('data-track="%s"', self::escape(implode(' ', $options['track'])));
        }
        $attributes[] = sprintf('src="%s/a.js?v=2"', self::escape($base));
        return '<script ' . implode(' ', $attributes) . '></script>';
    }

    /**
     * Serves the IndexNow key file and stops. Route /<key>.txt to it:
     *   Route::get("/{$key}.txt", fn () => \Seolyticize\Site::serveIndexNowKey($key));
     * In Laravel, return response($key)->header('Content-Type', 'text/plain') instead.
     */
    public static function serveIndexNowKey(string $key): never
    {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        echo $key;
        exit;
    }

    /**
     * Tells Seolyticize a deploy finished ($hookUrl from create_deploy_hook).
     * Without $urls the sitemap is compared with the last deploy's; with them,
     * exactly those go to IndexNow.
     *
     * @param string[]|null $urls
     * @return array<string, mixed>
     */
    public static function notifyDeploy(string $hookUrl, ?array $urls = null): array
    {
        return self::post($hookUrl, $urls === null ? new \stdClass() : ['urls' => array_values($urls)], 120);
    }

    /**
     * Sends one analytics event from the server. Every event from one server
     * address counts as one visitor; send from the browser where you can.
     *
     * @param array<string, scalar> $props
     */
    public static function trackEvent(
        string $siteKey,
        string $url,
        string $name = 'pageview',
        ?string $referrer = null,
        array $props = [],
        string $baseUrl = self::DEFAULT_BASE_URL,
    ): void {
        $body = ['k' => $siteKey, 'n' => $name, 'u' => $url];
        if ($referrer !== null) {
            $body['r'] = $referrer;
        }
        if ($props !== []) {
            $body['p'] = $props;
        }
        self::post(rtrim($baseUrl, '/') . '/api/collect', $body, 10);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * @param array<string, mixed>|\stdClass $body
     * @return array<string, mixed>
     */
    private static function post(string $url, array|\stdClass $body, int $timeout): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nUser-Agent: seolyticize-php-site/0.1.0\r\n",
                'content' => json_encode($body, JSON_THROW_ON_ERROR),
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new \RuntimeException("Seolyticize: no response from {$url}");
        }
        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : [];
    }
}
