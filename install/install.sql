CREATE TABLE IF NOT EXISTS `virtfusion_push_settings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `module_row_id` int(10) unsigned NOT NULL,
  `api_url` varchar(255) DEFAULT NULL,
  `api_token` varchar(255) DEFAULT NULL,
  `enable_all` tinyint(1) NOT NULL DEFAULT 0,
  `allowed_client_ids` text,
  `allowed_package_ids` text,
  `push_cooldown_days` int(10) unsigned NOT NULL DEFAULT 0,
  `push_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `push_price_currency` varchar(3) NOT NULL DEFAULT 'USD',
  `allow_all_packages` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `module_row_id` (`module_row_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `virtfusion_push_transfers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `service_id` int(10) unsigned NOT NULL,
  `from_client_id` int(10) unsigned NOT NULL,
  `to_client_id` int(10) unsigned DEFAULT NULL,
  `from_email` varchar(255) NOT NULL,
  `to_email` varchar(255) NOT NULL,
  `virtfusion_server_id` int(10) unsigned DEFAULT NULL,
  `status` enum('pending','awaiting_payment','payment_confirmed','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
  `error_message` text,
  `transferred_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `invoice_id` int(10) unsigned DEFAULT NULL,
  `push_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `current_step` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `service_id` (`service_id`),
  KEY `from_client_id` (`from_client_id`),
  KEY `to_client_id` (`to_client_id`),
  KEY `status` (`status`),
  KEY `invoice_id` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `virtfusion_push_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `transfer_id` int(10) unsigned DEFAULT NULL,
  `service_id` int(10) unsigned NOT NULL,
  `client_id` int(10) unsigned NOT NULL,
  `action` varchar(100) NOT NULL,
  `message` text NOT NULL,
  `details` text,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `transfer_id` (`transfer_id`),
  KEY `service_id` (`service_id`),
  KEY `client_id` (`client_id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
