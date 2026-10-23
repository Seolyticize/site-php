<?php

declare(strict_types=1);

require __DIR__ . '/../src/Site.php';

use Seolyticize\Site;

$failures = 0;
function check(bool $ok, string $what): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}

$tag = Site::analyticsTag('abc123', ['exclude' => ['/app/*', '/verify'], 'track' => ['outbound']]);
check(str_contains($tag, 'data-site="abc123"'), 'tag carries the site key');
check(str_contains($tag, 'data-exclude="/app/*,/verify"'), 'tag carries exclusions');
check(str_contains($tag, 'data-track="outbound"'), 'tag carries tracking');
check(str_contains($tag, 'src="https://seolyticize.com/a.js"'), 'tag loads the script');
check(!str_contains(Site::analyticsTag('"><x'), '"><x'), 'site key is escaped');

exit($failures === 0 ? 0 : 1);
