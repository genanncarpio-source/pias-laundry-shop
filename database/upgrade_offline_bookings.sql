CREATE TABLE IF NOT EXISTS mobile_booking_requests (
    customer_id INT UNSIGNED NOT NULL,
    client_request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    response_json LONGTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (customer_id, client_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
