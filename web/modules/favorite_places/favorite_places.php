<?php

// Favorite places: each row has a Drive button that opens Google Maps
// driving directions to it.
class favoritePlacesModule extends moduleClass {
    function render($params = array()) {
        $uid = zprime_current_user_id();
        if($uid === null) return '';

        $places = array();
        foreach(dashPlacesClassEx::forUser($uid) as $row) {
            $row['where'] = zprime_place_where($row);
            $row['maps_url'] = zprime_maps_url($row);
            $places[] = $row;
        }

        foreach($places as $i => &$p) {
            $p['is_first'] = $i === 0;
            $p['is_last'] = $i === count($places) - 1;
        }
        unset($p);

        return Renderer::render('favorite_places.zetem', [
            'places' => $places,
            'save_url' => rel_url('/places/save'),
            'delete_base' => rel_url('/places'),
        ]);
    }
}

function register_favorite_places_module() {
    global $kernel;
    $kernel->registerModule(new favoritePlacesModule(__DIR__, 'favorite_places', 'favorite_places.zetem'));
}
