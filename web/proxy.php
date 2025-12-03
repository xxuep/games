<?php
// Simple PHP proxy script

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['url'])) {
    $url = $_POST['url'];
    // Basic URL validation
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        http_response_code(400);
        echo "Invalid URL.";
        exit;
    }

    // Use cURL to fetch the remote content
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    // Identify user agent (optional)
    curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
    // Include headers for content-type detection
    curl_setopt($ch, CURLOPT_HEADER, false);

    $response = curl_exec($ch);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 400 || !$response) {
        http_response_code($httpCode ?: 500);
        echo "Failed to fetch the requested URL.";
        exit;
    }

    // If content is HTML, rewrite URLs to route back through proxy.php
    if (strpos($contentType, 'text/html') !== false) {
        $base = parse_url($url, PHP_URL_SCHEME) . "://" . parse_url($url, PHP_URL_HOST);
        // Replace href/src URLs starting with / or relative paths
        // This is a simplistic rewrite and may not cover all cases.
        $response = preg_replace_callback(
            '#(href|src)=("|\')(?!https?://|//)([^"\'>]+)("|\')#i',
            function ($matches) use ($base) {
                $newUrl = $base . '/' . ltrim($matches[3], '/');
                // Route through proxy with GET as fallback
                $proxyUrl = 'proxy.php?url=' . urlencode($newUrl);
                return $matches[1] . '=' . $matches[2] . $proxyUrl . $matches[4];
            },
            $response
        );
        // Also handle absolute URLs to route through proxy on click
        $response = preg_replace_callback(
            '#(href|src)=("|\')(https?://[^"\'>]+)("|\')#i',
            function ($matches) {
                $proxyUrl = 'proxy.php?url=' . urlencode($matches[3]);
                return $matches[1] . '=' . $matches[2] . $proxyUrl . $matches[4];
            },
            $response
        );
        // Output modified HTML
        header('Content-Type: text/html; charset=utf-8');
        echo $response;
    } else {
        // For non-HTML resources like images, styles, scripts, just pass through
        header("Content-Type: $contentType");
        echo $response;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['url'])) {
    // Support proxying when accessed via GET (links rewritten with ?url=)
    $url = $_GET['url'];
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        http_response_code(400);
        echo "Invalid URL.";
        exit;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);

    $response = curl_exec($ch);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 400 || !$response) {
        http_response_code($httpCode ?: 500);
        echo "Failed to fetch the requested URL.";
        exit;
    }

    // Same rewriting logic for HTML if needed
    if (strpos($contentType, 'text/html') !== false) {
        $base = parse_url($url, PHP_URL_SCHEME) . "://" . parse_url($url, PHP_URL_HOST);
        $response = preg_replace_callback(
            '#(href|src)=("|\')(?!https?://|//)([^"\'>]+)("|\')#i',
            function ($matches) use ($base) {
                $newUrl = $base . '/' . ltrim($matches[3], '/');
                $proxyUrl = 'proxy.php?url=' . urlencode($newUrl);
                return $matches[1] . '=' . $matches[2] . $proxyUrl . $matches[4];
            },
            $response
        );
        $response = preg_replace_callback(
            '#(href|src)=("|\')(https?://[^"\'>]+)("|\')#i',
            function ($matches) {
                $proxyUrl = 'proxy.php?url=' . urlencode($matches[3]);
                return $matches[1] . '=' . $matches[2] . $proxyUrl . $matches[4];
            },
            $response
        );
        header('Content-Type: text/html; charset=utf-8');
        echo $response;
    } else {
        header("Content-Type: $contentType");
        echo $response;
    }
} else {
    http_response_code(405);
    echo "Method not allowed.";
}
