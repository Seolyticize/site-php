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

The site key and IndexNow key come from `onboard_site` ([agent quick start](https://seolyticize.com/AGENTS.md)). License: MIT.
