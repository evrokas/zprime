<?php
/* A fake website for the favicon tests, run by IconSite (functional/helpers.php)
 * under `php -S`. Behaviour depends on the path, and on the Host header so one
 * server plays two sites: "127.0.0.1" has a /favicon.ico, "localhost" does not
 * (it answers 200 with an HTML page there, like a site with a catch-all route). */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$onLocalhost = stripos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === 0;
$dir = getenv('ZPRIME_ICON_SITE_DIR');

function icon_png(int $size, array $rgb): string {
    $im = imagecreatetruecolor($size, $size);
    imagefill($im, 0, 0, imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]));
    ob_start(); imagepng($im); return (string)ob_get_clean();
}
function send_png(string $bytes): never { header('Content-Type: image/png'); echo $bytes; exit; }
function send_html(string $body, int $status = 200): never { http_response_code($status); header('Content-Type: text/html'); echo "<!doctype html><html><head>$body</head><body>x</body></html>"; exit; }

switch(true) {
    case $path === '/a/':
        send_html('<link rel="icon" type="image/svg+xml" href="/x.svg">'
            . '<link rel="apple-touch-icon" sizes="180x180" href="/icons/a-180.png">'
            . '<link rel="shortcut icon" sizes="32x32" href="/icons/a-32.png">');
    case $path === '/icons/a-32.png':  send_png(icon_png(32, [200, 30, 30]));
    case $path === '/icons/a-180.png': send_png(icon_png(180, [30, 30, 200]));

    case $path === '/rel/dir/page.html':
        send_html('<link rel="icon" href="../icons/rel-16.png">');
    case $path === '/rel/icons/rel-16.png': send_png(icon_png(16, [10, 150, 10]));

    case $path === '/data/':
        send_html('<link rel="icon" href="data:image/png;base64,' . base64_encode(icon_png(20, [90, 90, 90])) . '">');

    case $path === '/nolinks/':
        send_html('<title>no icon links here</title>');

    case $path === '/oversize/':
        send_html('<link rel="icon" href="/icons/huge.png">');
    case $path === '/icons/huge.png': send_png(icon_png(16, [1, 2, 3]) . str_repeat("\0", 80000));

    case $path === '/svgonly/':
        send_html('<link rel="icon" type="image/svg+xml" href="/logo.svg">');
    case $path === '/logo.svg':
        header('Content-Type: image/svg+xml'); echo '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"><script>alert(1)</script></svg>'; exit;

    case $path === '/redirect/':
        header('Location: /a/', true, 302); exit;
    case $path === '/redirect-loop/':
        header('Location: /redirect-loop/', true, 302); exit;

    case $path === '/toggle/':
        $mode = trim((string)@file_get_contents("$dir/mode"));
        send_html($mode === 'icon' ? '<link rel="icon" href="/icons/a-32.png">' : '<title>icon removed</title>');

    case $path === '/favicon.ico':
        if($onLocalhost) send_html('<title>catch-all page, not an icon</title>');
        $png = icon_png(16, [220, 120, 0]);
        header('Content-Type: image/x-icon');
        echo pack('vvv', 0, 1, 1) . pack('CCCCvvVV', 16, 16, 0, 0, 1, 32, strlen($png), 22) . $png;
        exit;

    default:
        send_html('<title>not found</title>', 404);
}
