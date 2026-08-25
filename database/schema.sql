CREATE TABLE IF NOT EXISTS overpass_cache (
    cache_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    query_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    normalized_bbox VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    normalized_south DECIMAL(10, 6) NOT NULL,
    normalized_west DECIMAL(10, 6) NOT NULL,
    normalized_north DECIMAL(10, 6) NOT NULL,
    normalized_east DECIMAL(10, 6) NOT NULL,
    bbox_area DECIMAL(20, 12) NOT NULL,
    response_body LONGBLOB NOT NULL,
    body_encoding VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    content_type VARCHAR(255) NOT NULL,
    status_code SMALLINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (cache_key),
    KEY idx_overpass_cache_lookup (query_hash, bbox_area, expires_at),
    KEY idx_overpass_cache_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
