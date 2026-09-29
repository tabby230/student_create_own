<?php
/*
 * api/avatar-map-lookup.php — THE shared resolver (single source of truth).
 *
 * Called by BOTH:
 *   - api/students.php  (grid loop 13..124 AND the always validated map=JSON check)
 *   - api/student.php   (fallback profile builder)
 *
 * Reads the byte-verified api/avatar-map.json ONCE (cached) and returns the
 * EXACT cloudinary/local URL for a student id, or null when the id has no
 * avatar (e.g. id 43, id 124). Because both endpoints resolve through this
 * one function + this one JSON, they can never produce a different URL or
 * field for the same id.
 */

function edutrackAvatarById(): array {
    static $map = null;
    if ($map === null) {
        $json = file_get_contents(__DIR__ . '/avatar-map.json');
        $data = json_decode($json, true);
        $map  = is_array($data) && isset($data['avatar_by_id']) ? $data['avatar_by_id'] : [];
    }
    return $map;
}

function edutrackAvatarUrlForId($id): ?string {
    $id  = (int)$id;
    $map = edutrackAvatarById();
    return array_key_exists($id, $map) && isset($map[$id]) ? (string)$map[$id] : null;
}
