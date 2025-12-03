<?php
// proxy.php
// Basic PHP web proxy. Put this file on a PHP-enabled webserver.
//
// WARNING: this proxy is minimal but functional. Add auth, IP restrictions,
// or whitelists for production use.

ini_set('display_errors', 0);
error_reporting(0);

function send_status($code){
    http_response_code($code);
    exit("HTTP $code");
}

if (!isset($_GET['url'])) {
    send_status(400);
}

$target = trim($_GET['url']);
$target = filter_var($target, FILTER_UNSAFE_RAW);

// Basic validation: allow only http(s)
if (!preg_match('#^https?://#i', $target)) {
    send_status(400);
}

// Optional: blacklist local addresses to avoid SSRF risks
$blocked_patterns = [
    '#^https?://127\.#i', '#^https?://localhost#i', '#^https?://0\.0\.0\.0#i',
    // RFC1918 private IPs (simple check on host->ip below will catch more)
];

// Resolve host and block private IPs (best-effort)
$parsed = parse_url($target);
$host = $parsed['host'] ?? '';
if (!$host) send_status(400);

// resolve host to IPs
$ips = gethostbynamel($host);
if ($ips === false) send_status(502);

// block private ranges
foreach ($ips as $ip) {
    // IPv4 only checks below; you can extend to IPv6 ranges if needed.
    if (preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $ip)) {
        send_status(403);
    }
    if (strpos($ip, '127.') === 0 || strpos($ip, '0.') === 0) {
        send_status(403);
    }
}

// cURL fetch
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $target);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_MAXREDIRS, 8);
curl_setopt($ch, CURLOPT_USERAGENT, isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'PHP Proxy');
curl_setopt($ch, CURLOPT_HEADER, true); // we will parse headers
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
curl_setopt($ch, CURLOPT_ENCODING, ''); // accept all encodings
// forward some request headers (limited)
$forward_headers = [];
if (!empty($_SERVER['HTTP_ACCEPT'])) $forward_headers[] = 'Accept: '.$_SERVER['HTTP_ACCEPT'];
if (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) $forward_headers[] = 'Accept-Language: '.$_SERVER['HTTP_ACCEPT_LANGUAGE'];
curl_setopt($ch, CURLOPT_HTTPHEADER, $forward_headers);

// If this request had POST data, forward it
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postBody = file_get_contents('php://input');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postBody);
    // forward content-type if present
    if (!empty($_SERVER['CONTENT_TYPE'])) {
        $forward_headers[] = 'Content-Type: '.$_SERVER['CONTENT_TYPE'];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $forward_headers);
    }
}

$response = curl_exec($ch);
if ($response === false) {
    send_status(502);
}

$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$effective_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
curl_close($ch);

// separate headers and body
$raw_headers = substr($response, 0, $header_size);
$body = substr($response, $header_size);

// parse headers to array
$headers = [];
foreach (explode("\r\n", $raw_headers) as $h) {
    if (strpos($h, ':') !== false) {
        list($k,$v) = explode(':', $h, 2);
        $headers[trim($k)] = trim($v);
    }
}

// content-type
$content_type = $headers['Content-Type'] ?? 'application/octet-stream';

// If content is HTML, rewrite links so they route back through this proxy
if (stripos($content_type, 'text/html') !== false) {
    // detect page base for resolving relative URLs
    $baseUrl = $effective_url ?: $target;
    $body = rewrite_html($body, $baseUrl);
    header('Content-Type: text/html; charset=utf-8');
    // Content-Length may change; avoid sending the upstream length
    echo $body;
    exit;
}

// For other content types, pass through headers and raw body
// Set content-type header
if (!headers_sent()) {
    header('Content-Type: ' . $content_type);
}
// Forward Content-Disposition if exists (download filenames)
if (isset($headers['Content-Disposition'])) {
    header('Content-Disposition: ' . $headers['Content-Disposition']);
}
// Optionally forward caching headers or others, but avoid some security headers
$pass_through = ['Last-Modified','ETag','Cache-Control','Expires','Content-Length'];
foreach ($pass_through as $h) {
    if (isset($headers[$h])) header($h . ': ' . $headers[$h]);
}

// Output raw binary
echo $body;
exit;


// -----------------------------
// Helper functions
// -----------------------------
function make_proxy_url($original) {
    // Builds the URL to our proxy for a target original URL
    // Use rawurlencode to be safe
    return 'proxy.php?url=' . rawurlencode($original);
}

function absolute_url($relative, $base) {
    // Build an absolute URL given a base using PHP's parse_url join
    // If $relative already has scheme, return it
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $relative)) return $relative;
    // If starts with //, preserve the scheme from base
    if (substr($relative,0,2) === '//') {
        $p = parse_url($base);
        $scheme = $p['scheme'] ?? 'http';
        return $scheme . ':' . $relative;
    }
    $base_parsed = parse_url($base);
    $scheme = $base_parsed['scheme'] ?? 'http';
    $host = $base_parsed['host'] ?? '';
    $port = isset($base_parsed['port']) ? ':' . $base_parsed['port'] : '';
    $path = $base_parsed['path'] ?? '/';
    // if relative starts with '/', it's root-relative
    if (strpos($relative, '/') === 0) {
        return "$scheme://$host$port" . $relative;
    }
    // else combine paths
    $dir = preg_replace('#/[^/]*$#', '/', $path);
    $abs = "$scheme://$host$port$dir$relative";
    // normalize .. and .
    $abs = preg_replace('#(/\.?/)#', '/', $abs);
    while (preg_match('#/(?!\.\.)[^/]+/\.\./#', $abs)) {
        $abs = preg_replace('#/(?!\.\.)[^/]+/\.\./#', '/', $abs);
    }
    $abs = str_replace('/./', '/', $abs);
    return $abs;
}

function rewrite_html($html, $baseUrl) {
    // 1) insert a <base> tag to help relative URLs (we rewrite everything anyway)
    // 2) rewrite href/src/action values
    // 3) rewrite CSS url(...) values
    // 4) rewrite meta refresh and forms

    // Use DOM when possible for safer rewriting
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    // Provide a proper encoding header for DOM to parse correctly
    $loaded = $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    if ($loaded) {
        $xpath = new DOMXPath($dom);

        // Ensure there's a <base> tag that points to the effective base URL
        $bases = $dom->getElementsByTagName('base');
        if ($bases->length === 0) {
            $baseEl = $dom->createElement('base');
            $baseEl->setAttribute('href', $baseUrl);
            $head = $dom->getElementsByTagName('head')->item(0);
            if ($head) $head->insertBefore($baseEl, $head->firstChild);
        } else {
            // update existing base to effective url
            $bases->item(0)->setAttribute('href', $baseUrl);
        }

        // attributes to rewrite
        $attrs = ['href','src','action','data-src','data-href'];
        foreach ($attrs as $attr) {
            $nodes = $xpath->query("//*[@$attr]");
            foreach ($nodes as $node) {
                $val = $node->getAttribute($attr);
                if (!$val) continue;
                $abs = absolute_url($val, $baseUrl);
                if (preg_match('#^https?://#i', $abs)) {
                    $node->setAttribute($attr, make_proxy_url($abs));
                } else {
                    // keep other schemes as-is (mailto:, tel:, javascript:)
                    $node->setAttribute($attr, $val);
                }
            }
        }

        // rewrite srcset attributes (images)
        $nodes = $xpath->query('//*[@srcset]');
        foreach ($nodes as $node) {
            $parts = preg_split('/\s*,\s*/', $node->getAttribute('srcset'));
            $newparts = [];
            foreach ($parts as $p) {
                $sub = preg_split('/\s+/', $p);
                $urlp = $sub[0];
                $rest = array_slice($sub,1);
                $abs = absolute_url($urlp, $baseUrl);
                if (preg_match('#^https?://#i',$abs)) {
                    $newparts[] = make_proxy_url($abs) . (count($rest)? ' ' . implode(' ', $rest) : '');
                } else {
                    $newparts[] = $p;
                }
            }
            $node->setAttribute('srcset', implode(', ', $newparts));
        }

        // rewrite style attributes containing url(...)
        $nodes = $xpath->query('//*[@style]');
        foreach ($nodes as $node) {
            $style = $node->getAttribute('style');
            $style = preg_replace_callback('#url\((.*?)\)#i', function($m) use ($baseUrl){
                $inner = trim($m[1], " \t\n\r\0\x0B'\"");
                $abs = absolute_url($inner, $baseUrl);
                if (preg_match('#^https?://#i',$abs)) return 'url(' . make_proxy_url($abs) . ')';
                return $m[0];
            }, $style);
            $node->setAttribute('style', $style);
        }

        // rewrite <style> blocks
        $styleNodes = $dom->getElementsByTagName('style');
        foreach ($styleNodes as $sn) {
            $css = $sn->nodeValue;
            $css = preg_replace_callback('#url\((.*?)\)#i', function($m) use ($baseUrl){
                $inner = trim($m[1], " \t\n\r\0\x0B'\"");
                $abs = absolute_url($inner, $baseUrl);
                if (preg_match('#^https?://#i',$abs)) return 'url(' . make_proxy_url($abs) . ')';
                return $m[0];
            }, $css);
            $sn->nodeValue = $css;
        }

        // rewrite meta refresh (e.g. <meta http-equiv="refresh" content="5; url=/...">)
        $metaNodes = $xpath->query("//meta[translate(@http-equiv,'REFSH','refsh')='refresh' or @http-equiv='refresh']");
        foreach ($metaNodes as $mn) {
            $content = $mn->getAttribute('content');
            if (preg_match('/url=(.*)/i', $content, $m)) {
                $urlpart = trim($m[1], " \t\n\r\0\x0B'\"");
                $abs = absolute_url($urlpart, $baseUrl);
                if (preg_match('#^https?://#i',$abs)) {
                    $mn->setAttribute('content', preg_replace('/url=.*/i', 'url=' . make_proxy_url($abs), $content));
                }
            }
        }

        // Output final HTML
        $htmlOut = $dom->saveHTML();

        // Remove the xml encoding prefix we added
        $htmlOut = preg_replace('/^<!DOCTYPE.+?>/', '', $htmlOut);
        $htmlOut = preg_replace('/<\?xml.*?\?>/', '', $htmlOut);
        return $htmlOut;
    } else {
        // Fallback: naive regex replacement (less safe) if DOM failed
        $out = $html;
        // rewrite href/src/action
        $out = preg_replace_callback('#(href|src|action)\s*=\s*([\'"])(.*?)\2#i', function($m) use ($baseUrl){
            $attr = $m[1]; $val = $m[3];
            $abs = absolute_url($val, $baseUrl);
            if (preg_match('#^https?://#i',$abs)) {
                return $attr . '=' . $m[2] . make_proxy_url($abs) . $m[2];
            }
            return $m[0];
        }, $out);
        // rewrite url(...) in styles
        $out = preg_replace_callback('#url\((.*?)\)#i', function($m) use ($baseUrl){
            $inner = trim($m[1], " \t\n\r\0\x0B'\"");
            $abs = absolute_url($inner, $baseUrl);
            if (preg_match('#^https?://#i',$abs)) return 'url(' . make_proxy_url($abs) . ')';
            return $m[0];
        }, $out);
        return $out;
    }
}
