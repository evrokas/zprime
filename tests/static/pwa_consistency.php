<?php
/* The installable-app files: manifest.webmanifest must be valid JSON, every
 * icon it names must exist with the size it claims, and the page shell must
 * link the manifest. */

function zprime_static_pwa_consistency(TestRunner $runner): void {
    $root = ZPRIME_TEST_APPDIR . '/web';

    $runner->add('manifest.webmanifest is valid JSON with the required members', function () use ($root) {
        $m = json_decode((string)file_get_contents($root . '/manifest.webmanifest'), true);
        assert_true(is_array($m), 'manifest.webmanifest is not valid JSON');
        foreach (['name', 'start_url', 'display', 'icons'] as $k) assert_true(isset($m[$k]), "manifest is missing '$k'");
        assert_equal('standalone', $m['display'], 'display should be standalone for a home-screen app');
    });

    $runner->add('every manifest icon exists at its declared size', function () use ($root) {
        $m = json_decode((string)file_get_contents($root . '/manifest.webmanifest'), true);
        foreach ($m['icons'] as $icon) {
            $path = $root . '/' . $icon['src'];
            assert_true(is_file($path), "icon missing: {$icon['src']}");
            [$w, $h] = getimagesize($path);
            assert_equal($icon['sizes'], "{$w}x{$h}", "{$icon['src']} is {$w}x{$h}, manifest says {$icon['sizes']}");
        }
        assert_true(is_file($root . '/assets/apple-touch-icon.png'), 'apple-touch-icon.png missing');
    });

    $runner->add('the page shell links the manifest and the apple touch icon', function () use ($root) {
        $main = (string)file_get_contents($root . '/templates/page/main.zetem');
        assert_contains('manifest.webmanifest', $main);
        assert_contains('apple-touch-icon', $main);
    });

    $runner->add('app icons: rounded corners transparent, Apple and maskable icons opaque, right sizes', function () use ($root) {
        $alphaAt = function (string $file, int $x, int $y): int {
            $im = imagecreatefrompng($file);
            return (imagecolorat($im, $x, $y) >> 24) & 127;      // 0 opaque .. 127 transparent
        };
        $a = $root . '/assets/';
        assert_equal([512, 512], array_slice(getimagesize($a . 'icon-512.png'), 0, 2));
        assert_equal([192, 192], array_slice(getimagesize($a . 'icon-192.png'), 0, 2));
        assert_equal([180, 180], array_slice(getimagesize($a . 'apple-touch-icon.png'), 0, 2));
        assert_equal(127, $alphaAt($a . 'icon-512.png', 1, 1), 'the large icon has no transparent corner');
        assert_equal(0, $alphaAt($a . 'icon-512.png', 256, 256), 'the large icon centre is not opaque');
        // iOS and Android fill the whole square themselves: no transparency allowed in these two
        foreach (['apple-touch-icon.png' => [0, 0, 179, 179], 'icon-maskable-512.png' => [0, 0, 511, 511]] as $file => [$x1, $y1, $x2, $y2]) {
            foreach ([[$x1, $y1], [$x2, $y1], [$x1, $y2], [$x2, $y2]] as [$x, $y]) {
                assert_equal(0, $alphaAt($a . $file, $x, $y), "$file has a transparent corner");
            }
        }
    });

    $runner->add('favicon: small icon as png (32, 48) and as a real .ico holding 16, 32 and 48 px', function () use ($root) {
        assert_equal([32, 32], array_slice(getimagesize($root . '/assets/favicon-32.png'), 0, 2));
        assert_equal([48, 48], array_slice(getimagesize($root . '/assets/favicon-48.png'), 0, 2));
        $ico = (string)file_get_contents($root . '/favicon.ico');
        assert_equal([0, 1], array_values(array_slice(unpack('v3', $ico), 0, 2)), 'favicon.ico is not an icon file');   // reserved 0, type 1 = icon
        $count = unpack('v', substr($ico, 4, 2))[1];
        $sizes = [];
        for ($i = 0; $i < $count; $i++) {
            $e = unpack('Cw/Ch', substr($ico, 6 + 16 * $i, 2));
            $sizes[] = $e['w'];
        }
        sort($sizes);
        assert_equal([16, 32, 48], $sizes);
        if (!function_exists('zprime_icon_mime')) require_once ZPRIME_TEST_APPDIR . '/web/dash_lib.php';   // not loaded yet in --static-only runs
        assert_equal('image/vnd.microsoft.icon', zprime_icon_mime($ico), 'PHP does not read favicon.ico as an icon');
    });

    $runner->add('the tab icon in the page config is the favicon, not the app icon', function () {
        $yaml = (string)file_get_contents(ZPRIME_TEST_APPDIR . '/config/settings.info.yaml');
        assert_true((bool)preg_match('/favicon:\s*\n\s*type: image\/png\s*\n\s*src: assets\/favicon-32\.png/', $yaml), 'favicon is not assets/favicon-32.png');
    });
}
