<?php
// filepath: c:\xampp\htdocs\mercado_admin\src\media.php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Unified media storage in PostgreSQL.
 * Stores images as base64 in the media_files table so they persist across deploys.
 *
 * entity_type values:
 *   'product'         – main product image (cover)
 *   'product_gallery'  – additional gallery images
 *   'avatar'           – user avatar / profile photo
 */

function mediaGetConnection() {
    static $conn = null;
    if ($conn === null) {
        $conn = (new Database())->connect();
    }
    return $conn;
}

/**
 * Save an uploaded file ($_FILES entry) to the media_files table.
 * Returns the media ID on success, null on failure.
 */
function mediaSaveFromUpload(array $file, string $entityType, int $entityId, bool $isCover = false, int $sortOrder = 0): ?int {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) return null;

    $mime = mime_content_type($tmp) ?: 'application/octet-stream';
    if (!str_starts_with($mime, 'image/')) return null;

    $maxSize = 5 * 1024 * 1024; // 5MB
    if ((int)($file['size'] ?? 0) > $maxSize) return null;

    $data = file_get_contents($tmp);
    if ($data === false) return null;

    $b64 = base64_encode($data);
    $originalName = basename((string)($file['name'] ?? 'image.jpg'));

    return mediaInsertRow($entityType, $entityId, $b64, $mime, $originalName, $isCover, $sortOrder);
}

/**
 * Save raw binary data to media_files.
 */
function mediaSaveFromData(string $data, string $mime, string $entityType, int $entityId, bool $isCover = false, string $originalName = 'image.jpg', int $sortOrder = 0): ?int {
    $b64 = base64_encode($data);
    return mediaInsertRow($entityType, $entityId, $b64, $mime, $originalName, $isCover, $sortOrder);
}

function mediaInsertRow(string $entityType, int $entityId, string $b64, string $mime, string $originalName, bool $isCover, int $sortOrder): ?int {
    $conn = mediaGetConnection();

    try {
        // If setting as cover, unset previous covers
        if ($isCover) {
            $st = $conn->prepare("UPDATE media_files SET is_cover = FALSE WHERE entity_type = ? AND entity_id = ? AND is_cover = TRUE");
            if ($st) {
                $st->bind_param('si', $entityType, $entityId);
                $st->execute();
                $st->close();
            }
        }

        // Use string 'true'/'false' for PostgreSQL boolean compatibility
        $coverStr = $isCover ? 'true' : 'false';
        $st = $conn->prepare("INSERT INTO media_files (entity_type, entity_id, file_data, mime_type, original_name, is_cover, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if (!$st) {
            error_log('[Media] FAIL: prepare INSERT returned false');
            return null;
        }
        $st->bind_param('ssssssi', $entityType, $entityId, $b64, $mime, $originalName, $coverStr, $sortOrder);
        $ok = $st->execute();
        $st->close();

        if (!$ok) {
            error_log('[Media] FAIL: execute INSERT returned false');
            return null;
        }

        $id = (int)($conn->insert_id ?? 0);
        if ($id <= 0) {
            $res = $conn->query("SELECT MAX(id) AS last_id FROM media_files WHERE entity_type = '" . addslashes($entityType) . "' AND entity_id = " . (int)$entityId);
            $row = $res ? $res->fetch_assoc() : null;
            $id = (int)($row['last_id'] ?? 0);
        }

        return $id > 0 ? $id : null;
    } catch (\Throwable $e) {
        error_log('[Media] ERROR in mediaInsertRow: ' . $e->getMessage());
        return null;
    }
}

/**
 * Get the public URL for a media file by ID.
 */
function mediaUrl(int $id): string {
    if ($id <= 0) return '';

    // Build URL relative to app root
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    // Try to extract the base path (e.g. /mercado_admin/public)
    $basePath = '';
    if (preg_match('#^(/.+?/public)#', $script, $m)) {
        $basePath = $m[1];
    }

    return $basePath . '/api/media?id=' . $id;
}

/**
 * Resolve any image reference to a displayable URL.
 * Handles: 'media:123', filesystem paths, full URLs, empty values.
 */
function mediaResolveUrl(string $raw, string $default = ''): string {
    $raw = trim($raw);
    if ($raw === '') return $default;

    // Already a full URL
    if (preg_match('#^https?://#i', $raw)) return $raw;

    // Database media reference: 'media:123'
    if (str_starts_with($raw, 'media:')) {
        $id = (int)substr($raw, 6);
        if ($id <= 0) return $default;
        return mediaUrl($id);
    }

    // Legacy filesystem path — pass through to uploadsPublicUrl
    if (function_exists('uploadsPublicUrl')) {
        return uploadsPublicUrl($raw, $default);
    }

    return $raw ?: $default;
}

/**
 * List media files for a given entity.
 */
function mediaListByEntity(string $entityType, int $entityId): array {
    $conn = mediaGetConnection();
    $st = $conn->prepare("SELECT id, entity_type, entity_id, mime_type, original_name, is_cover, sort_order, created_at FROM media_files WHERE entity_type = ? AND entity_id = ? ORDER BY is_cover DESC, sort_order ASC, id ASC");
    $st->bind_param('si', $entityType, $entityId);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

/**
 * Get a single media file row (without file_data for performance).
 */
function mediaGetMeta(int $id): ?array {
    $conn = mediaGetConnection();
    $st = $conn->prepare("SELECT id, entity_type, entity_id, mime_type, original_name, is_cover, sort_order, created_at FROM media_files WHERE id = ? LIMIT 1");
    $st->bind_param('i', $id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

/**
 * Get the raw base64 data and mime for serving.
 */
function mediaGetData(int $id): ?array {
    $conn = mediaGetConnection();
    $st = $conn->prepare("SELECT file_data, mime_type FROM media_files WHERE id = ? LIMIT 1");
    $st->bind_param('i', $id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

/**
 * Delete a media file by ID.
 */
function mediaDelete(int $id): bool {
    $conn = mediaGetConnection();
    $st = $conn->prepare("DELETE FROM media_files WHERE id = ?");
    $st->bind_param('i', $id);
    try {
        $ok = $st->execute();
    } catch (\Throwable) {
        return false;
    }
    $affected = (int)$st->affected_rows;
    $st->close();
    return $ok && $affected > 0;
}

function mediaDeleteForEntity(int $id, string $entityType, int $entityId): bool {
    if ($id <= 0 || $entityId <= 0) return false;

    $conn = mediaGetConnection();
    $st = $conn->prepare("DELETE FROM media_files WHERE id = ? AND entity_type = ? AND entity_id = ?");
    $st->bind_param('isi', $id, $entityType, $entityId);
    try {
        $ok = $st->execute();
    } catch (\Throwable) {
        return false;
    }
    $affected = (int)$st->affected_rows;
    $st->close();
    return $ok && $affected > 0;
}

/**
 * Delete all media for an entity.
 */
function mediaDeleteByEntity(string $entityType, int $entityId): int {
    $conn = mediaGetConnection();
    $st = $conn->prepare("DELETE FROM media_files WHERE entity_type = ? AND entity_id = ?");
    $st->bind_param('si', $entityType, $entityId);
    try {
        $st->execute();
    } catch (\Throwable) {
        return 0;
    }
    $affected = (int)$st->affected_rows;
    $st->close();
    return $affected;
}

/**
 * Set a specific media as the cover image for its entity.
 */
function mediaSetCover(int $mediaId): bool {
    $meta = mediaGetMeta($mediaId);
    if (!$meta) return false;

    $conn = mediaGetConnection();

    // Unset all covers for this entity
    $st = $conn->prepare("UPDATE media_files SET is_cover = FALSE WHERE entity_type = ? AND entity_id = ?");
    $st->bind_param('si', $meta['entity_type'], $meta['entity_id']);
    $st->execute();
    $st->close();

    // Set new cover
    $st = $conn->prepare("UPDATE media_files SET is_cover = TRUE WHERE id = ?");
    $st->bind_param('i', $mediaId);
    $ok = $st->execute();
    $st->close();
    return $ok;
}
