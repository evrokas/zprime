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
            "SELECT id, title, url, sort_order FROM dash_links WHERE user_id=:uid ORDER BY sort_order, id");
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
