<?php

// Extensions of the maker-generated entity classes (web/classes/*.php).
//
// Every dashboard query is scoped by user_id: a row is only ever read,
// changed or deleted together with its owner's id in the WHERE clause, so
// one account can never touch another's websites or places.

class usersClassEx extends usersClass {
    static function getUserAccount( $uname ) {
        $sql = "SELECT * FROM users WHERE uname=:uname";
        $st = dbConnection::getConnection()->prepare( $sql );
        $st->bindValue(":uname", $uname, PDO::PARAM_STR);
        $st->execute();
        $row = $st->fetch();

        if($row) {
            $rclass = new usersClass( "users");
            $rclass->loadFields( $row );
            return $rclass;
        } else return (null);
    }
}

class dashLinksClassEx extends dashLinksClass {
    /** @return array<int,array<string,mixed>> */
    static function forUser(int $userId): array {
        $st = dbConnection::getConnection()->prepare(
            "SELECT id, title, url, sort_order, (icon IS NOT NULL) AS has_icon,
                    (icon_fetched IS NULL) AS needs_icon, UNIX_TIMESTAMP(icon_fetched) AS icon_ts
             FROM dash_links WHERE user_id=:uid ORDER BY sort_order, id");
        $st->bindValue(':uid', $userId, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    static function create(int $userId, string $title, string $url): void {
        $st = dbConnection::getConnection()->prepare(
            "INSERT INTO dash_links (user_id, title, url, sort_order)
             VALUES (:uid, :title, :url, (SELECT COALESCE(MAX(m.sort_order), 0) + 1 FROM dash_links m WHERE m.user_id=:uid2))");
        $st->bindValue(':uid', $userId, PDO::PARAM_INT);
        $st->bindValue(':uid2', $userId, PDO::PARAM_INT);
        $st->bindValue(':title', $title);
        $st->bindValue(':url', $url);
        $st->execute();
    }

    /** @return bool whether a row owned by $userId was updated */
    static function change(int $userId, int $id, string $title, string $url): bool {
        $st = dbConnection::getConnection()->prepare(
            "UPDATE dash_links SET title=:title, url=:url WHERE id=:id AND user_id=:uid");
        $st->bindValue(':title', $title);
        $st->bindValue(':url', $url);
        $st->bindValue(':id', $id, PDO::PARAM_INT);
        $st->bindValue(':uid', $userId, PDO::PARAM_INT);
        $st->execute();
        // rowCount() is 0 for an unchanged row too, so confirm ownership separately.
        return $st->rowCount() > 0 || self::owns($userId, $id);
    }

    /** The user's own row (id, url) or null. Never selects the icon bytes. */
    static function mine(int $userId, int $id): ?array {
        $st = dbConnection::getConnection()->prepare("SELECT id, url FROM dash_links WHERE id=:id AND user_id=:uid");
        $st->bindValue(':id', $id, PDO::PARAM_INT);
        $st->bindValue(':uid', $userId, PDO::PARAM_INT);
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array{icon:string, icon_mime:string}|null the stored icon, only for its owner */
    static function iconOf(int $userId, int $id): ?array {
        $st = dbConnection::getConnection()->prepare(
            "SELECT icon, icon_mime FROM dash_links WHERE id=:id AND user_id=:uid AND icon IS NOT NULL");
        $st->bindValue(':id', $id, PDO::PARAM_INT);
        $st->bindValue(':uid', $userId, PDO::PARAM_INT);
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    static function storeIcon(int $userId, int $id, string $bytes, string $mime): bool {
        $st = dbConnection::getConnection()->prepare(
            "UPDATE dash_links SET icon=:icon, icon_mime=:mime, icon_fetched=NOW() WHERE id=:id AND user_id=:uid");
        $st->bindValue(':icon', $bytes, PDO::PARAM_LOB);
        $st->bindValue(':mime', $mime);
        $st->bindValue(':id', $id, PDO::PARAM_INT);
        $st->bindValue(':uid', $userId, PDO::PARAM_INT);
        $st->execute();
        return $st->rowCount() > 0;
    }

    /** Records that a fetch was tried, so the page does not ask again on every visit. */
    static function markIconTried(int $userId, int $id): void {
        $st = dbConnection::getConnection()->prepare("UPDATE dash_links SET icon_fetched=NOW() WHERE id=:id AND user_id=:uid");
        $st->bindValue(':id', $id, PDO::PARAM_INT);
        $st->bindValue(':uid', $userId, PDO::PARAM_INT);
        $st->execute();
    }

    /** Forgets the icon (the address moved to another site), so it is fetched again. */
    static function clearIcon(int $userId, int $id): void {
        $st = dbConnection::getConnection()->prepare(
            "UPDATE dash_links SET icon=NULL, icon_mime=NULL, icon_fetched=NULL WHERE id=:id AND user_id=:uid");
        $st->bindValue(':id', $id, PDO::PARAM_INT);
        $st->bindValue(':uid', $userId, PDO::PARAM_INT);
        $st->execute();
    }

    static function owns(int $userId, int $id): bool {
        $st = dbConnection::getConnection()->prepare("SELECT 1 FROM dash_links WHERE id=:id AND user_id=:uid");
        $st->bindValue(':id', $id, PDO::PARAM_INT);
        $st->bindValue(':uid', $userId, PDO::PARAM_INT);
        $st->execute();
        return (bool)$st->fetchColumn();
    }

    static function remove(int $userId, int $id): bool {
        $st = dbConnection::getConnection()->prepare("DELETE FROM dash_links WHERE id=:id AND user_id=:uid");
        $st->bindValue(':id', $id, PDO::PARAM_INT);
        $st->bindValue(':uid', $userId, PDO::PARAM_INT);
        $st->execute();
        return $st->rowCount() > 0;
    }
}

class dashPlacesClassEx extends dashPlacesClass {
    /** @return array<int,array<string,mixed>> */
    static function forUser(int $userId): array {
        $st = dbConnection::getConnection()->prepare(
            "SELECT id, name, address, lat, lon, sort_order FROM dash_places WHERE user_id=:uid ORDER BY sort_order, id");
        $st->bindValue(':uid', $userId, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    static function create(int $userId, string $name, ?string $address, ?string $lat, ?string $lon): void {
        $st = dbConnection::getConnection()->prepare(
            "INSERT INTO dash_places (user_id, name, address, lat, lon, sort_order)
             VALUES (:uid, :name, :address, :lat, :lon, (SELECT COALESCE(MAX(m.sort_order), 0) + 1 FROM dash_places m WHERE m.user_id=:uid2))");
        $st->bindValue(':uid', $userId, PDO::PARAM_INT);
        $st->bindValue(':uid2', $userId, PDO::PARAM_INT);
        $st->bindValue(':name', $name);
        $st->bindValue(':address', $address);
        $st->bindValue(':lat', $lat);
        $st->bindValue(':lon', $lon);
        $st->execute();
    }

    static function change(int $userId, int $id, string $name, ?string $address, ?string $lat, ?string $lon): bool {
        $st = dbConnection::getConnection()->prepare(
            "UPDATE dash_places SET name=:name, address=:address, lat=:lat, lon=:lon WHERE id=:id AND user_id=:uid");
        $st->bindValue(':name', $name);
        $st->bindValue(':address', $address);
        $st->bindValue(':lat', $lat);
        $st->bindValue(':lon', $lon);
        $st->bindValue(':id', $id, PDO::PARAM_INT);
        $st->bindValue(':uid', $userId, PDO::PARAM_INT);
        $st->execute();
        return $st->rowCount() > 0 || self::owns($userId, $id);
    }

    static function owns(int $userId, int $id): bool {
        $st = dbConnection::getConnection()->prepare("SELECT 1 FROM dash_places WHERE id=:id AND user_id=:uid");
        $st->bindValue(':id', $id, PDO::PARAM_INT);
        $st->bindValue(':uid', $userId, PDO::PARAM_INT);
        $st->execute();
        return (bool)$st->fetchColumn();
    }

    static function remove(int $userId, int $id): bool {
        $st = dbConnection::getConnection()->prepare("DELETE FROM dash_places WHERE id=:id AND user_id=:uid");
        $st->bindValue(':id', $id, PDO::PARAM_INT);
        $st->bindValue(':uid', $userId, PDO::PARAM_INT);
        $st->execute();
        return $st->rowCount() > 0;
    }
}

/**
 * Ordering of a user's websites and places. Only ever moves one item one
 * step, and renumbers the whole list 0..n-1 each time, so duplicate or gappy
 * sort_order values (older rows, rows that share the default) cannot make a
 * move do nothing or move the wrong item.
 */
class dashOrder {
    private const TABLES = ['dash_links', 'dash_places'];

    /** @return bool false when the item is not one of this user's (or the arguments are invalid) */
    static function move(string $table, int $userId, int $id, string $dir): bool {
        if(!in_array($table, self::TABLES, true) || !in_array($dir, ['up', 'down'], true)) return false;

        $pdo = dbConnection::getConnection();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("SELECT id FROM `$table` WHERE user_id=:uid ORDER BY sort_order, id FOR UPDATE");
            $st->bindValue(':uid', $userId, PDO::PARAM_INT);
            $st->execute();
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

            $i = array_search($id, $ids, true);
            if($i === false) {
                $pdo->rollBack();
                return false;
            }
            $j = $dir === 'up' ? $i - 1 : $i + 1;
            if($j >= 0 && $j < count($ids)) {
                [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            }

            $upd = $pdo->prepare("UPDATE `$table` SET sort_order=:pos WHERE id=:id AND user_id=:uid");
            foreach($ids as $pos => $rowId) {
                $upd->bindValue(':pos', $pos, PDO::PARAM_INT);
                $upd->bindValue(':id', $rowId, PDO::PARAM_INT);
                $upd->bindValue(':uid', $userId, PDO::PARAM_INT);
                $upd->execute();
            }
            $pdo->commit();
            return true;
        } catch(\Throwable $e) {
            if($pdo->inTransaction()) $pdo->rollBack();
            error_log('dashOrder::move failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Puts the user's items in the order given by $ids (as set by drag-and-drop). Ids that are not the
     * user's, and repeats, are ignored; the user's items missing from $ids keep their current order after
     * the listed ones, so a stale page (an item added or removed elsewhere) can never lose or foreign-write
     * anything. Renumbers 0..n-1.
     *
     * @param int[] $ids
     * @return bool false only when the arguments or the database fail
     */
    static function setOrder(string $table, int $userId, array $ids): bool {
        if(!in_array($table, self::TABLES, true)) return false;

        $pdo = dbConnection::getConnection();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("SELECT id FROM `$table` WHERE user_id=:uid ORDER BY sort_order, id FOR UPDATE");
            $st->bindValue(':uid', $userId, PDO::PARAM_INT);
            $st->execute();
            $owned = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            $ownedSet = array_flip($owned);

            $new = [];
            foreach($ids as $id) {
                $id = (int)$id;
                if(isset($ownedSet[$id]) && !in_array($id, $new, true)) $new[] = $id;
            }
            foreach($owned as $id) {
                if(!in_array($id, $new, true)) $new[] = $id;
            }

            $upd = $pdo->prepare("UPDATE `$table` SET sort_order=:pos WHERE id=:id AND user_id=:uid");
            foreach($new as $pos => $rowId) {
                $upd->bindValue(':pos', $pos, PDO::PARAM_INT);
                $upd->bindValue(':id', $rowId, PDO::PARAM_INT);
                $upd->bindValue(':uid', $userId, PDO::PARAM_INT);
                $upd->execute();
            }
            $pdo->commit();
            return true;
        } catch(\Throwable $e) {
            if($pdo->inTransaction()) $pdo->rollBack();
            error_log('dashOrder::setOrder failed: ' . $e->getMessage());
            return false;
        }
    }
}
