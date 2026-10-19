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
            $links[] = $row;
        }

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
