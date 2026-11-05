# Seolyticize for PHP sites

The analytics tag, the IndexNow key file, deploy notification and server-side events for any PHP site — Laravel, Symfony, plain PHP. Using WordPress? Use the [plugin](https://github.com/Seolyticize/wordpress).

```sh
composer config repositories.seolyticize vcs https://github.com/Seolyticize/site-php
composer require seolyticize/site:dev-main
```

```php
use Seolyticize\Site;

echo Site::analyticsTag('SITE_KEY', ['exclude' => ['/account/*', '/verify']]); // in your <head>

// routes: serve the IndexNow key at /KEY.txt
Route::get('/KEY.txt', fn () => response('KEY')->header('Content-Type', 'text/plain')); // Laravel
// or anywhere: Site::serveIndexNowKey('KEY');

Site::notifyDeploy('https://seolyticize.com/api/v1/hooks/deploy/dh_…'); // after each deploy
Site::trackEvent('SITE_KEY', 'https://example.com/pricing', 'Signup');  // server-side event
```

## Server-side collection

Reports page views from the server — above all the AI assistants (ChatGPT-User, Claude-User, Perplexity-User) that read pages without running the tag. Needs the server key from `issue_server_collect_key`.

```php
use Seolyticize\Collector;

$collector = new Collector('SITE_KEY', getenv('SEOLYTICIZE_SERVER_KEY'), [
    'exclude' => ['/admin/*', '/api/*'],
    'only' => 'agents', // with the browser tag installed too; 'all' without it
]);
register_shutdown_function(fn () => $collector->trackCurrentRequest()); // after the page is sent
// Laravel: call $collector->trackCurrentRequest(null, $response->getStatusCode()) from a terminable middleware's terminate().
```

Only the path with the `utm_*` parameters (change with `allowParams`), the referrer's origin, the visitor's address, user agent and country are sent; Seolyticize hashes the address and user agent under a daily salt and stores neither. Bots are dropped before anything is sent.

To credit orders to the visit they came from, send a reference from the checkout — the tag's `seolyticize('event', 'Checkout', {ref: cartId})`, or from the server `$collector->track($checkoutUrl, ['name' => 'Checkout', 'props' => ['ref' => $cartId], 'userAgent' => …, 'ip' => …])` with the visitor's own agent and address so it joins their visit — and put the same value in the order's metadata as `seolyticize_ref` (Stripe metadata, a Shopify cart attribute, Paddle `custom_data`).

A conversion that happens after the visit — a payment confirmed by webhook, a trial that converts next week — is credited to the visit by its token. Ask for it while the visitor is there and keep it on your server with the account (never in a cookie or the page); the token lasts 35 days:

```php
$visit = $collector->trackForVisit($signupUrl, 'Signup', ['userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? '', 'ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
// store $visit with the account; days later, from the payment webhook:
$collector->trackLater($account->seolyticizeVisit, 'Top-up', ['value' => 25]);
```

The site key and IndexNow key come from `onboard_site` ([agent quick start](https://seolyticize.com/AGENTS.md)). License: MIT.
