<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

use Illuminate\Support\Facades\Route;

function get_content($path) {
    $request = Illuminate\Http\Request::create($path);
    try {
        $response = Route::dispatch($request);
        return $response->getContent();
    } catch (\Exception $e) {
        return "";
    }
}

function check_url($url) {
    if (str_starts_with($url, 'http')) {
        // Skip external for now or hit them once
        return 200;
    }

    $path = parse_url($url, PHP_URL_PATH);
    if (!$path) return 200;

    $request = Illuminate\Http\Request::create($path);
    try {
        $response = Route::dispatch($request);
        return $response->getStatusCode();
    } catch (\Exception $e) {
        return 500;
    }
}

$start_urls = ['/', '/christ', '/church', '/community', '/christ/sermons'];
$visited = [];
$to_visit = $start_urls;
$broken = [];

while (!empty($to_visit)) {
    $url = array_shift($to_visit);
    if (in_array($url, $visited)) continue;
    $visited[] = $url;

    echo "Visiting $url...\n";
    $content = get_content($url);

    preg_match_all('/href=\"([^\"]+)\"/', $content, $matches);
    foreach ($matches[1] as $link) {
        if (str_starts_with($link, '#')) continue;
        if (str_starts_with($link, 'tel:')) continue;
        if (str_starts_with($link, 'mailto:')) continue;

        $status = check_url($link);
        if ($status === 404) {
            $broken[] = ["from" => $url, "link" => $link, "status" => $status];
        } else if ($status === 200 && !in_array($link, $visited) && !str_starts_with($link, 'http')) {
            // Only crawl internal links and don't go too deep into sermons to avoid explosion
            if (!str_contains($link, '/sermons/20') && !str_contains($link, '/sermons/19')) {
                 $to_visit[] = $link;
            }
        }
    }
}

echo "BROKEN LINKS FOUND:\n";
print_r($broken);
