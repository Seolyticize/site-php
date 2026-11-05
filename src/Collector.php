<?php

declare(strict_types=1);

namespace Seolyticize;

/**
 * Server-side collection for the cookieless analytics, for any PHP site.
 *
 * Reports page views from the server — above all for the visitors that never
 * run the browser tag, AI assistants such as ChatGPT-User, Claude-User and
 * Perplexity-User — with the visitor's own address and user agent, which
 * Seolyticize hashes under a daily salt and never stores. Needs the server key
 * from issue_server_collect_key.
 *
 * Only the path with the campaign tags (every other query parameter is
 * stripped here), the referrer's origin, the visitor's address, user agent
 * and country leave the site. Bots are dropped before anything is sent.
 *
 *   $collector = new \Seolyticize\Collector('SITE_KEY', getenv('SEOLYTICIZE_SERVER_KEY'), [
 *       'exclude' => ['/admin/*'],
 *       'only' => 'agents', // with the browser tag installed too
 *   ]);
 *   register_shutdown_function(fn () => $collector->trackCurrentRequest());
 *
 * A conversion that happens after the visit — a payment confirmed by webhook,
 * a trial that converts next week — is credited to the visit by its token:
 * trackForVisit() reports an event for the visitor in front of you and
 * returns the token, which you keep on your server with the account or order
 * (never in a cookie or a page); trackLater() sends the later event with it.
 *
 *   $token = $collector->trackForVisit('https://shop.example/signup', 'Signup', [
 *       'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? '', 'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
 *   ]);
 *   // ... days later, from the payment webhook:
 *   $collector->trackLater($account->visitToken, 'Top-up', ['value' => 25]);
 */
final class Collector
{
    public const DEFAULT_ENDPOINT = 'https://seolyticize.com/api/collect';
    public const DEFAULT_ALLOWED_PARAMS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    private const AGENT = '/chatgpt-user|claude-user|perplexity-user|mistralai-user/i';
    private const BOT = '/bot\b|crawl|spider|slurp|mediapartners|facebookexternalhit|embedly|whatsapp|headless|phantomjs|selenium|webdriver|puppeteer|playwright|lighthouse|pagespeed|gtmetrix|pingdom|uptime|monitor|preview|prerender|curl\/|wget\/|httpie|python-requests|python-urllib|aiohttp|httpx|go-http-client|java\/|okhttp|apache-httpclient|node-fetch|undici|axios\/|libwww|scrapy|feedfetcher/i';

    private string $endpoint;
    /** @var string[] */
    private array $allowParams;
    private ?string $exclude;
    private ?string $include;
    private string $only;
    private float $timeout;
    /** @var (callable(string, string, array<string, string>, float): int)|null */
    private $send;
    /** @var (callable(string, string, array<string, string>, float): array{0: int, 1: string})|null */
    private $request;
    /** @var (callable(\Throwable): void)|null */
    private $onError;

    /**
     * @param array{endpoint?: string, exclude?: string[], include?: string[], allowParams?: string[], only?: 'all'|'agents', timeout?: float, send?: callable, request?: callable, onError?: callable} $options
     */
    public function __construct(
        private string $siteKey,
        private string $serverKey,
        array $options = [],
    ) {
        $this->endpoint = $options['endpoint'] ?? self::DEFAULT_ENDPOINT;
        $this->allowParams = $options['allowParams'] ?? self::DEFAULT_ALLOWED_PARAMS;
        $this->exclude = self::pattern($options['exclude'] ?? []);
        $this->include = self::pattern($options['include'] ?? []);
        $this->only = $options['only'] ?? 'all';
        $this->timeout = (float) ($options['timeout'] ?? 2.0);
        $this->send = $options['send'] ?? null;
        $this->request = $options['request'] ?? null;
        $this->onError = $options['onError'] ?? null;
    }

    /** "person", "agent" (an AI assistant reading for someone) or "bot". */
    public static function classifyAgent(string $userAgent, ?string $signatureAgent = null): string
    {
        if ($signatureAgent !== null && preg_match('/chatgpt\.com/i', $signatureAgent) === 1) {
            return 'agent';
        }
        if (preg_match(self::AGENT, $userAgent) === 1) {
            return 'agent';
        }
        if (strlen($userAgent) < 20) {
            return 'bot';
        }
        if (preg_match(self::BOT, str_ireplace('cubot', '', $userAgent)) === 1) {
            return 'bot';
        }
        return 'person';
    }

    /**
     * The URL with only the allowed query parameters and no fragment.
     *
     * @param string[] $allowed
     */
    public static function stripQuery(string $url, array $allowed = self::DEFAULT_ALLOWED_PARAMS): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }
        $keep = array_map('strtolower', $allowed);
        $query = [];
        foreach (explode('&', $parts['query'] ?? '') as $pair) {
            if ($pair === '') {
                continue;
            }
            $name = urldecode(explode('=', $pair, 2)[0]);
            if (in_array(strtolower($name), $keep, true)) {
                $query[] = $pair;
            }
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '/';
        return $parts['scheme'] . '://' . $parts['host'] . $port . $path . ($query ? '?' . implode('&', $query) : '');
    }

    /** Whether a path would be reported under the include and exclude lists. */
    public function reports(string $path): bool
    {
        $value = strlen($path) > 1 && str_ends_with($path, '/') ? substr($path, 0, -1) : $path;
        if ($this->exclude !== null && preg_match($this->exclude, $value) === 1) {
            return false;
        }
        return $this->include === null || preg_match($this->include, $value) === 1;
    }

    /**
     * What would be sent for this page view, or null when nothing would be.
     *
     * @param array{referrer?: ?string, userAgent?: ?string, ip?: ?string, country?: ?string, clientHints?: ?string, signatureAgent?: ?string, name?: string, props?: array<string, scalar>} $view
     * @return array<string, mixed>|null
     */
    public function payload(string $url, array $view = [], bool $everyone = false): ?array
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!$this->reports(is_string($path) && $path !== '' ? $path : '/')) {
            return null;
        }
        $userAgent = (string) ($view['userAgent'] ?? '');
        $kind = self::classifyAgent($userAgent, $view['signatureAgent'] ?? null);
        if ($kind === 'bot' || (!$everyone && $this->only === 'agents' && $kind !== 'agent')) {
            return null;
        }
        $body = [
            'k' => $this->siteKey,
            'n' => $view['name'] ?? 'pageview',
            'u' => self::stripQuery($url, $this->allowParams),
            'ua' => substr($userAgent, 0, 1024),
        ];
        $referrer = $view['referrer'] ?? null;
        if (is_string($referrer) && preg_match('#^(https?://[^/?\#]+)#i', $referrer, $match) === 1) {
            $body['r'] = $match[1] . '/';
        }
        if (!empty($view['ip'])) {
            $body['ip'] = substr((string) $view['ip'], 0, 64);
        }
        if (!empty($view['country'])) {
            $body['c'] = substr((string) $view['country'], 0, 2);
        }
        if (!empty($view['clientHints'])) {
            $body['h'] = substr((string) $view['clientHints'], 0, 512);
        }
        if (!empty($view['signatureAgent'])) {
            $body['sa'] = substr((string) $view['signatureAgent'], 0, 256);
        }
        if (!empty($view['props'])) {
            $body['p'] = $view['props'];
        }
        return $body;
    }

    /**
     * Reports one page view (or, with 'name', a custom event). Never throws.
     *
     * @param array{referrer?: ?string, userAgent?: ?string, ip?: ?string, country?: ?string, clientHints?: ?string, signatureAgent?: ?string, name?: string, props?: array<string, scalar>} $view
     */
    public function track(string $url, array $view = []): void
    {
        try {
            $body = $this->payload($url, $view);
            if ($body === null) {
                return;
            }
            $headers = [
                'content-type' => 'application/json',
                'authorization' => 'Bearer ' . $this->serverKey,
                'user-agent' => 'seolyticize-php-collect/0.1.0',
            ];
            $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $status = $this->send !== null
                ? ($this->send)($this->endpoint, $json, $headers, $this->timeout)
                : self::post($this->endpoint, $json, $headers, $this->timeout);
            if ($status === 401) {
                throw new \RuntimeException('Seolyticize refused the server key. Issue a new one.');
            }
        } catch (\Throwable $error) {
            if ($this->onError !== null) {
                ($this->onError)($error);
            }
        }
    }

    /**
     * Reports an event for the visitor in front of you — a sign-up, a checkout
     * started — whatever 'only' says, and returns that visit's token, or null
     * when none was issued (a bot, an agent, an excluded path, a failure).
     * Keep the token server-side with the account or order. Never throws.
     *
     * @param array{referrer?: ?string, userAgent?: ?string, ip?: ?string, country?: ?string, clientHints?: ?string, signatureAgent?: ?string, props?: array<string, scalar>} $view
     */
    public function trackForVisit(string $url, string $name, array $view = []): ?string
    {
        try {
            $body = $this->payload($url, ['name' => $name] + $view, true);
            if ($body === null) {
                return null;
            }
            [$status, $answer] = $this->exchange($body);
            if ($status !== 202 || $answer === '') {
                return null;
            }
            $decoded = json_decode($answer, true);
            return is_array($decoded) && is_string($decoded['visit'] ?? null) ? $decoded['visit'] : null;
        } catch (\Throwable $error) {
            if ($this->onError !== null) {
                ($this->onError)($error);
            }
            return null;
        }
    }

    /**
     * Credits an event that happened after the visit — a top-up confirmed by
     * webhook, a trial converting — to the visit the token names. 'value' in
     * $props is what a goal without a fixed worth sums. True when credited;
     * never throws.
     *
     * @param array<string, scalar> $props
     */
    public function trackLater(string $visit, string $name, array $props = [], ?string $url = null): bool
    {
        try {
            $body = ['k' => $this->siteKey, 'n' => $name, 'visit' => $visit];
            if ($url !== null && $url !== '') {
                $body['u'] = self::stripQuery($url, $this->allowParams);
            }
            if ($props) {
                $body['p'] = $props;
            }
            [$status] = $this->exchange($body);
            if ($status === 202) {
                return true;
            }
            // 410: the token outlived its 35 days; 404: its visit was not
            // found; 400: not a token for this site.
            throw new \RuntimeException("Seolyticize did not credit the event (HTTP {$status}).");
        } catch (\Throwable $error) {
            if ($this->onError !== null) {
                ($this->onError)($error);
            }
            return false;
        }
    }

    /**
     * Posts a body with the server key and reads the answer.
     *
     * @param array<string, mixed> $body
     * @return array{0: int, 1: string}
     */
    private function exchange(array $body): array
    {
        $headers = [
            'content-type' => 'application/json',
            'authorization' => 'Bearer ' . $this->serverKey,
            'user-agent' => 'seolyticize-php-collect/0.1.0',
        ];
        $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $answer = $this->request !== null
            ? ($this->request)($this->endpoint, $json, $headers, $this->timeout)
            : self::request($this->endpoint, $json, $headers, $this->timeout);
        if ($answer[0] === 401) {
            throw new \RuntimeException('Seolyticize refused the server key. Issue a new one.');
        }
        return $answer;
    }

    /**
     * Reports the request PHP is serving now, if it is a successful page view.
     * Call it once the response is sent — register_shutdown_function, Laravel's
     * terminable middleware, or after fastcgi_finish_request() — so the page
     * never waits for it.
     *
     * @param array<string, mixed>|null $server Defaults to $_SERVER.
     */
    public function trackCurrentRequest(?array $server = null, ?int $status = null): void
    {
        $server ??= $_SERVER;
        $status ??= (int) (http_response_code() ?: 200);
        if ($status < 200 || $status >= 300) {
            return;
        }
        $header = static fn (string $name): ?string => isset($server['HTTP_' . $name]) ? (string) $server['HTTP_' . $name] : null;
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET' && $method !== 'HEAD') {
            return;
        }
        $purpose = ($header('PURPOSE') ?? '') . ' ' . ($header('SEC_PURPOSE') ?? '');
        if (preg_match('/prefetch|prerender/i', $purpose) === 1) {
            return;
        }
        $dest = $header('SEC_FETCH_DEST');
        if ($dest !== null && $dest !== 'document') {
            return;
        }
        $accept = $header('ACCEPT') ?? '';
        if ($accept !== '' && !str_contains($accept, 'text/html') && !str_contains($accept, '*/*')) {
            return;
        }
        $host = explode(',', $header('X_FORWARDED_HOST') ?? $header('HOST') ?? (string) ($server['SERVER_NAME'] ?? 'localhost'))[0];
        $https = ($server['HTTPS'] ?? '') !== '' && ($server['HTTPS'] ?? '') !== 'off';
        $scheme = explode(',', $header('X_FORWARDED_PROTO') ?? ($https ? 'https' : 'http'))[0];
        $forwarded = $header('X_FORWARDED_FOR');
        $ip = $header('CF_CONNECTING_IP')
            ?? $header('X_REAL_IP')
            ?? ($forwarded !== null ? trim(explode(',', $forwarded)[0]) : null)
            ?? (isset($server['REMOTE_ADDR']) ? (string) $server['REMOTE_ADDR'] : null);
        $this->track(trim($scheme) . '://' . trim($host) . (string) ($server['REQUEST_URI'] ?? '/'), [
            'referrer' => $header('REFERER'),
            'userAgent' => $header('USER_AGENT'),
            'ip' => $ip,
            'country' => $header('CF_IPCOUNTRY'),
            'clientHints' => $header('SEC_CH_UA'),
            'signatureAgent' => $header('SIGNATURE_AGENT'),
        ]);
    }

    /** @param string[] $patterns */
    private static function pattern(array $patterns): ?string
    {
        $parts = [];
        foreach ($patterns as $pattern) {
            $pattern = trim($pattern);
            if ($pattern !== '') {
                $parts[] = str_replace('\*', '.*', preg_quote($pattern, '#'));
            }
        }
        return $parts ? '#^(?:' . implode('|', $parts) . ')$#' : null;
    }

    /** @param array<string, string> $headers */
    private static function post(string $url, string $json, array $headers, float $timeout): int
    {
        return self::request($url, $json, $headers, $timeout)[0];
    }

    /**
     * @param array<string, string> $headers
     * @return array{0: int, 1: string}
     */
    private static function request(string $url, string $json, array $headers, float $timeout): array
    {
        $lines = '';
        foreach ($headers as $name => $value) {
            $lines .= $name . ': ' . $value . "\r\n";
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $lines,
                'content' => $json,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new \RuntimeException("Seolyticize: no response from {$url}");
        }
        $status = 0;
        /** @var string[] $http_response_header */
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
                $status = (int) $match[1];
            }
        }
        return [$status, (string) $response];
    }
}
