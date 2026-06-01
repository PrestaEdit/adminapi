CREATE TABLE IF NOT EXISTS `PREFIX_adminapi_client` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id`     VARCHAR(80)  NOT NULL,
  `client_secret` VARCHAR(255) NOT NULL,
  `client_name`   VARCHAR(255) NOT NULL,
  `scopes`        TEXT,
  `active`        TINYINT(1)   NOT NULL DEFAULT 1,
  `date_add`      DATETIME     NOT NULL,
  `date_upd`      DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_client_id` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_adminapi_access_token` (
  `id`         VARCHAR(255) NOT NULL,
  `client_id`  VARCHAR(80)  NOT NULL,
  `scopes`     TEXT,
  `revoked`    TINYINT(1)   NOT NULL DEFAULT 0,
  `expires_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_client_id` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
