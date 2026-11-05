<?php

declare(strict_types=1);

require __DIR__ . '/../src/Collector.php';

use Seolyticize\Collector;

$failures = 0;
function check(bool $ok, string $what): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}

$chrome = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

check(Collector::classifyAgent('Mozilla/5.0 (compatible; ChatGPT-User/1.0; +https://openai.com/bot)') === 'agent', 'an assistant reading for someone is an agent');
check(Collector::classifyAgent($chrome, '"https://chatgpt.com"') === 'agent', 'a signing agent is an agent');
check(Collector::classifyAgent('Mozilla/5.0 (compatible; Googlebot/2.1)') === 'bot', 'a crawler is a bot');
check(Collector::classifyAgent('curl/8.4.0') === 'bot', 'a script is a bot');
check(Collector::classifyAgent($chrome) === 'person', 'a browser is a person');

check(
    Collector::stripQuery('https://shop.example/p?utm_source=news&email=a%40b.com#top') === 'https://shop.example/p?utm_source=news',
    'only the campaign tags survive',
);

$calls = [];
$send = function (string $url, string $json, array $headers, float $timeout) use (&$calls): int {
    $calls[] = [$url, json_decode($json, true), $headers];
    return 202;
};

$collector = new Collector('site_key_0123456789', 'slyc_secret', ['send' => $send, 'exclude' => ['/admin/*']]);
$collector->track('https://shop.example/pricing?utm_campaign=autumn&session=abc', [
    'referrer' => 'https://www.google.com/search?q=private',
    'userAgent' => $chrome,
    'ip' => '198.51.100.7',
    'country' => 'GB',
]);
check(count($calls) === 1, 'a page view is sent');
check($calls[0][2]['authorization'] === 'Bearer slyc_secret', 'with the server key');
check($calls[0][1]['u'] === 'https://shop.example/pricing?utm_campaign=autumn', 'with the query stripped');
check($calls[0][1]['r'] === 'https://www.google.com/', 'with only the referrer origin');
check($calls[0][1]['ip'] === '198.51.100.7' && $calls[0][1]['c'] === 'GB', 'with the visitor address and country');

$collector->track('https://shop.example/admin/users', ['userAgent' => $chrome]);
$collector->track('https://shop.example/', ['userAgent' => 'curl/8.4.0']);
check(count($calls) === 1, 'excluded paths and bots are not sent');

$agents = new Collector('site_key_0123456789', 'slyc_secret', ['send' => $send, 'only' => 'agents']);
$agents->track('https://shop.example/', ['userAgent' => $chrome]);
$agents->track('https://shop.example/', ['userAgent' => 'Mozilla/5.0 (compatible; Claude-User/1.0)']);
check(count($calls) === 2, 'agents-only mode sends only assistants');

$collector->trackCurrentRequest([
    'REQUEST_METHOD' => 'GET',
    'REQUEST_URI' => '/docs?utm_source=x&secret=1',
    'HTTPS' => 'on',
    'HTTP_HOST' => 'shop.example',
    'HTTP_ACCEPT' => 'text/html',
    'HTTP_USER_AGENT' => $chrome,
    'REMOTE_ADDR' => '203.0.113.5',
], 200);
check($calls[2][1]['u'] === 'https://shop.example/docs?utm_source=x', 'the current request is reported from $_SERVER');
check($calls[2][1]['ip'] === '203.0.113.5', 'with the remote address when no proxy header says otherwise');

$collector->trackCurrentRequest(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_HOST' => 'shop.example', 'HTTP_USER_AGENT' => $chrome], 404);
check(count($calls) === 3, 'an unsuccessful response is not a page view');

$errors = [];
$refused = new Collector('site_key_0123456789', 'slyc_wrong', [
    'send' => fn () => 401,
    'onError' => function (\Throwable $error) use (&$errors): void {
        $errors[] = $error;
    },
]);
$refused->track('https://shop.example/', ['userAgent' => $chrome]);
check(count($errors) === 1, 'a refused key is reported, not thrown');

// Conversions after the visit: a token for the visit, then a later event with it.
$exchanges = [];
$answering = function (int $status, string $body) use (&$exchanges): callable {
    return function (string $url, string $json, array $headers, float $timeout) use (&$exchanges, $status, $body): array {
        $exchanges[] = [$url, json_decode($json, true), $headers];
        return [$status, $body];
    };
};
$visits = new Collector('site_key_0123456789', 'slyc_secret', [
    'request' => $answering(202, '{"visit":"v1.abc.def"}'),
    'only' => 'agents',
]);
$token = $visits->trackForVisit('https://shop.example/signup', 'Signup', ['userAgent' => $chrome, 'ip' => '198.51.100.7']);
check($token === 'v1.abc.def', 'an event for the visitor returns the visit token, whatever only says');
check($exchanges[0][1]['n'] === 'Signup' && $exchanges[0][1]['ip'] === '198.51.100.7', 'with the event and the visitor');
check($visits->trackForVisit('https://shop.example/', 'Signup', ['userAgent' => 'curl/8.4.0']) === null, 'a bot gets no token');

$later = new Collector('site_key_0123456789', 'slyc_secret', ['request' => $answering(202, '')]);
check($later->trackLater('v1.abc.def', 'Top-up', ['value' => 25]) === true, 'a later event is credited');
check(
    end($exchanges)[1] === ['k' => 'site_key_0123456789', 'n' => 'Top-up', 'visit' => 'v1.abc.def', 'p' => ['value' => 25]],
    'with the token and no visitor fields',
);

$expiredErrors = [];
$expired = new Collector('site_key_0123456789', 'slyc_secret', [
    'request' => $answering(410, ''),
    'onError' => function (\Throwable $error) use (&$expiredErrors): void {
        $expiredErrors[] = $error;
    },
]);
check($expired->trackLater('v1.old.token', 'Top-up') === false && count($expiredErrors) === 1, 'an expired token is reported, not thrown');

exit($failures === 0 ? 0 : 1);
