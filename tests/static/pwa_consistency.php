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
}
