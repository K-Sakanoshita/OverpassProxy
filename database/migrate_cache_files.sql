-- Apply once to an existing overpass_cache table before deploying the
-- file-backed cache code. Existing LONGBLOB rows remain readable and expire
-- normally; newly written rows store response_body as NULL.

ALTER TABLE overpass_cache
    MODIFY COLUMN response_body LONGBLOB NULL,
    ADD COLUMN response_file VARCHAR(255)
        CHARACTER SET ascii COLLATE ascii_bin NULL AFTER response_body,
    ADD COLUMN stored_bytes BIGINT UNSIGNED NULL AFTER response_file,
    ADD COLUMN response_sha256 CHAR(64)
        CHARACTER SET ascii COLLATE ascii_bin NULL AFTER stored_bytes,
    ADD UNIQUE KEY uq_overpass_cache_response_file (response_file);
