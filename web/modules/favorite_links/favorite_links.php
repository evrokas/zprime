<?php

// Favorite websites: a grid of cards, with add/edit/delete.
class favoriteLinksModule extends moduleClass {
    function render($params = array()) {
        $uid = zprime_current_user_id();
        if($uid === null) return '';

        $links = array();
        foreach(dashLinksClassEx::forUser($uid) as $row) {
            $row['label'] = zprime_url_label($row['url']);
            $row['initial'] = mb_strtoupper(mb_substr(trim($row['title']), 0, 1)) ?: '?';
            $row['hue'] = crc32(zprime_url_label($row['url'])) % 360;
            $row['icon_url'] = $row['has_icon']
                ? rel_url('/links/' . $row['id'] . '/icon') . '?v=' . (int)$row['icon_ts']
                : '';
            $links[] = $row;
        }

        foreach($links as $i => &$l) {
            $l['is_first'] = $i === 0;
            $l['is_last'] = $i === count($links) - 1;
        }
        unset($l);

        return Renderer::render('favorite_links.zetem', [
            'links' => $links,
            'save_url' => rel_url('/links/save'),
            'delete_base' => rel_url('/links'),
        ]);
    }
}

function register_favorite_links_module() {
    global $kernel;
    $kernel->registerModule(new favoriteLinksModule(__DIR__, 'favorite_links', 'favorite_links.zetem'));
}
