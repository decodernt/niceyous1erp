CREATE TABLE IF NOT EXISTS `%%PREFIX%%addon_niceyous1erp_transactions` (
  `transactionid` int(11) NOT NULL AUTO_INCREMENT,
  `productid` int(11) NOT NULL,
  `combinationid` int(11) NOT NULL DEFAULT 0,
  `payload` longtext NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'TODO',
  `message` text NULL,
  `created` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`transactionid`),
  KEY `i_niceyous1erp_transactions_status` (`status`),
  KEY `i_niceyous1erp_transactions_product` (`productid`,`combinationid`)
) ENGINE=InnoDB DEFAULT CHARSET=%%CHARSET%% COLLATE %%COLLATE%%;

CREATE TABLE IF NOT EXISTS `%%PREFIX%%addon_niceyous1erp_product_map` (
  `mapid` int(11) NOT NULL AUTO_INCREMENT,
  `productid` int(11) NOT NULL,
  `combinationid` int(11) NOT NULL DEFAULT 0,
  `erp_mtrl` varchar(50) NOT NULL,
  `last_update` int(11) NULL,
  PRIMARY KEY (`mapid`),
  UNIQUE KEY `u_niceyous1erp_product_map` (`productid`,`combinationid`),
  KEY `i_niceyous1erp_product_map_mtrl` (`erp_mtrl`)
) ENGINE=InnoDB DEFAULT CHARSET=%%CHARSET%% COLLATE %%COLLATE%%;

-- eshop side is BRANDS: NiceYou's MTRCATEGORY list holds brand names.
CREATE TABLE IF NOT EXISTS `%%PREFIX%%addon_niceyous1erp_category_map` (
  `mapid` int(11) NOT NULL AUTO_INCREMENT,
  `brandid` int(11) NULL,
  `erp_cat_id` varchar(50) NOT NULL,
  `erp_title` varchar(500) NULL,
  `brand_title` varchar(500) NULL,
  PRIMARY KEY (`mapid`),
  UNIQUE KEY `u_niceyous1erp_category_map` (`erp_cat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=%%CHARSET%% COLLATE %%COLLATE%%;

CREATE TABLE IF NOT EXISTS `%%PREFIX%%addon_niceyous1erp_customer_map` (
  `customerid` int(11) NOT NULL,
  `erp_trdr` varchar(50) NOT NULL,
  `last_update` int(11) NULL,
  PRIMARY KEY (`customerid`)
) ENGINE=InnoDB DEFAULT CHARSET=%%CHARSET%% COLLATE %%COLLATE%%;

CREATE TABLE IF NOT EXISTS `%%PREFIX%%addon_niceyous1erp_order_receipts` (
  `receipt_id` varchar(20) NOT NULL,
  `fk_order_id` int(11) NOT NULL,
  `datetime` int(11) NULL,
  PRIMARY KEY (`receipt_id`),
  KEY `fk_addon_niceyous1erp_order_receipts_fk_order_id` (`fk_order_id`),
  CONSTRAINT `fk_addon_niceyous1erp_order_receipts_fk_order_id` FOREIGN KEY (`fk_order_id`) REFERENCES `%%PREFIX%%orders` (`orderid`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=%%CHARSET%% COLLATE %%COLLATE%%;

CREATE TABLE IF NOT EXISTS `%%PREFIX%%addon_niceyous1erp_sync_orders_report` (
  `ordersyncid` int(11) NOT NULL AUTO_INCREMENT,
  `naga_order_id` int(11) NOT NULL,
  `receipt_id` varchar(20) NOT NULL,
  `syncType` int(11) NOT NULL,
  `payload` text NULL,
  `message` text NOT NULL,
  `result_type` int(11) NOT NULL,
  `datetime` int(11) NULL,
  PRIMARY KEY (`ordersyncid`),
  KEY `fk_addon_niceyous1erp_sync_orders_report_naga_order_id` (`naga_order_id`),
  KEY `fk_addon_niceyous1erp_sync_orders_report_receipt_id` (`receipt_id`),
  CONSTRAINT `fk_addon_niceyous1erp_sync_orders_report_order_id` FOREIGN KEY (`naga_order_id`) REFERENCES `%%PREFIX%%orders` (`orderid`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=%%CHARSET%% COLLATE %%COLLATE%%;

CREATE TABLE IF NOT EXISTS `%%PREFIX%%addon_niceyous1erp_assocs_payship` (
  `MODULE` varchar(100) NOT NULL,
  `CODE` varchar(50) NOT NULL,
  `LAST_UPDATE` int(11) NULL,
  PRIMARY KEY (`MODULE`)
) ENGINE=InnoDB DEFAULT CHARSET=%%CHARSET%% COLLATE %%COLLATE%%;

CREATE TABLE IF NOT EXISTS `%%PREFIX%%addon_niceyous1erp_assocs_vat_class` (
  `TAX_CLASS_ID` varchar(100) NOT NULL,
  `VAT_CODE` varchar(100) NOT NULL,
  `LAST_UPDATE` int(11) NULL,
  PRIMARY KEY (`TAX_CLASS_ID`)
) ENGINE=InnoDB DEFAULT CHARSET=%%CHARSET%% COLLATE %%COLLATE%%;

CREATE TABLE IF NOT EXISTS `%%PREFIX%%addon_niceyous1erp_webfifo` (
  `mtrl` varchar(50) NOT NULL,
  `name` varchar(500) NULL,
  `purchase_price` decimal(20,4) NOT NULL DEFAULT '0.0000',
  `last_update` int(11) NULL,
  `applied` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`mtrl`),
  KEY `i_niceyous1erp_webfifo_applied` (`applied`)
) ENGINE=InnoDB DEFAULT CHARSET=%%CHARSET%% COLLATE %%COLLATE%%;
