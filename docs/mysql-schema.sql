-- Offline MySQL 8.4 compilation. Not executed on a server.
create table `users` (`id` bigint unsigned not null auto_increment primary key, `name` varchar(255) not null, `email` varchar(255) not null, `email_verified_at` timestamp null, `password` varchar(255) not null, `remember_token` varchar(100) null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `users` add unique `users_email_unique`(`email`);

create table `password_reset_tokens` (`email` varchar(255) not null, `token` varchar(255) not null, `created_at` timestamp null, primary key (`email`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

create table `sessions` (`id` varchar(255) not null, `user_id` bigint unsigned null, `ip_address` varchar(45) null, `user_agent` text null, `payload` longtext not null, `last_activity` int not null, primary key (`id`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `sessions` add index `sessions_user_id_index`(`user_id`);

alter table `sessions` add index `sessions_last_activity_index`(`last_activity`);

create table `cache` (`key` varchar(255) not null, `value` mediumtext not null, `expiration` bigint not null, primary key (`key`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `cache` add index `cache_expiration_index`(`expiration`);

create table `cache_locks` (`key` varchar(255) not null, `owner` varchar(255) not null, `expiration` bigint not null, primary key (`key`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `cache_locks` add index `cache_locks_expiration_index`(`expiration`);

create table `jobs` (`id` bigint unsigned not null auto_increment primary key, `queue` varchar(255) not null, `payload` longtext not null, `attempts` smallint unsigned not null, `reserved_at` int unsigned null, `available_at` int unsigned not null, `created_at` int unsigned not null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `jobs` add index `jobs_queue_index`(`queue`);

create table `job_batches` (`id` varchar(255) not null, `name` varchar(255) not null, `total_jobs` int not null, `pending_jobs` int not null, `failed_jobs` int not null, `failed_job_ids` longtext not null, `options` mediumtext null, `cancelled_at` int null, `created_at` int not null, `finished_at` int null, primary key (`id`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

create table `failed_jobs` (`id` bigint unsigned not null auto_increment primary key, `uuid` varchar(255) not null, `connection` varchar(255) not null, `queue` varchar(255) not null, `payload` longtext not null, `exception` longtext not null, `failed_at` timestamp not null default CURRENT_TIMESTAMP) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `failed_jobs` add index `failed_jobs_connection_queue_failed_at_index`(`connection`, `queue`, `failed_at`);

alter table `failed_jobs` add unique `failed_jobs_uuid_unique`(`uuid`);

create table `passkeys` (`id` bigint unsigned not null auto_increment primary key, `user_id` bigint unsigned not null, `name` varchar(255) not null, `credential_id` varchar(255) not null, `credential` json not null, `last_used_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `passkeys` add constraint `passkeys_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete cascade;

alter table `passkeys` add index `passkeys_user_id_index`(`user_id`);

alter table `passkeys` add unique `passkeys_credential_id_unique`(`credential_id`);

alter table `users` add `two_factor_secret` text null after `password`;

alter table `users` add `two_factor_recovery_codes` text null after `two_factor_secret`;

alter table `users` add `two_factor_confirmed_at` timestamp null after `two_factor_recovery_codes`;

create table `companies` (`id` bigint unsigned not null auto_increment primary key, `name` varchar(255) not null, `email` varchar(255) null, `phone` varchar(255) null, `currency` varchar(3) not null default 'TZS', `timezone` varchar(255) not null default 'Africa/Dar_es_Salaam', `settings` json null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

create table `roles` (`id` bigint unsigned not null auto_increment primary key, `name` varchar(255) not null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `roles` add unique `roles_name_unique`(`name`);

create table `permissions` (`id` bigint unsigned not null auto_increment primary key, `name` varchar(255) not null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `permissions` add unique `permissions_name_unique`(`name`);

create table `permission_role` (`role_id` bigint unsigned not null, `permission_id` bigint unsigned not null, primary key (`role_id`, `permission_id`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `permission_role` add constraint `permission_role_role_id_foreign` foreign key (`role_id`) references `roles` (`id`) on delete cascade;

alter table `permission_role` add constraint `permission_role_permission_id_foreign` foreign key (`permission_id`) references `permissions` (`id`) on delete cascade;

alter table `users` add `company_id` bigint unsigned null;

alter table `users` add constraint `users_company_id_foreign` foreign key (`company_id`) references `companies` (`id`) on delete restrict;

alter table `users` add `role_id` bigint unsigned null;

alter table `users` add constraint `users_role_id_foreign` foreign key (`role_id`) references `roles` (`id`) on delete restrict;

alter table `users` add `active` tinyint(1) not null default '1';

alter table `users` add unique `users_company_id_unique`(`company_id`, `id`);

create table `branches` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `name` varchar(255) not null, `address` varchar(255) null, `active` tinyint(1) not null default '1', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `branches` add constraint `branches_company_id_foreign` foreign key (`company_id`) references `companies` (`id`) on delete restrict;

alter table `branches` add unique `branches_company_id_name_unique`(`company_id`, `name`);

alter table `branches` add unique `branches_tenant_uq`(`company_id`, `id`);

create table `branch_user` (`company_id` bigint unsigned not null, `branch_id` bigint unsigned not null, `user_id` bigint unsigned not null, primary key (`company_id`, `branch_id`, `user_id`)) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `branch_user` add constraint `fk_branch_user_branch_id` foreign key (`company_id`, `branch_id`) references `branches` (`company_id`, `id`) on delete restrict;

alter table `branch_user` add constraint `fk_branch_user_user_id` foreign key (`company_id`, `user_id`) references `users` (`company_id`, `id`) on delete restrict;

create table `categories` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `name` varchar(255) not null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `categories` add constraint `categories_company_id_foreign` foreign key (`company_id`) references `companies` (`id`) on delete restrict;

alter table `categories` add unique `categories_company_id_name_unique`(`company_id`, `name`);

alter table `categories` add unique `categories_tenant_uq`(`company_id`, `id`);

create table `units` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `name` varchar(255) not null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `units` add constraint `units_company_id_foreign` foreign key (`company_id`) references `companies` (`id`) on delete restrict;

alter table `units` add unique `units_company_id_name_unique`(`company_id`, `name`);

alter table `units` add unique `units_tenant_uq`(`company_id`, `id`);

create table `stock_locations` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `branch_id` bigint unsigned not null, `name` varchar(255) not null, `type` varchar(10) not null, `active` tinyint(1) not null default '1', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `stock_locations` add constraint `stock_locations_company_id_foreign` foreign key (`company_id`) references `companies` (`id`) on delete restrict;

alter table `stock_locations` add unique `location_name_uq`(`company_id`, `branch_id`, `name`);

alter table `stock_locations` add unique `stock_locations_tenant_uq`(`company_id`, `id`);

alter table `stock_locations` add index `stock_locations_scope_idx`(`company_id`, `branch_id`, `created_at`);

alter table `stock_locations` add constraint `stock_locations_branch_fk` foreign key (`company_id`, `branch_id`) references `branches` (`company_id`, `id`) on delete restrict;

create table `products` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `category_id` bigint unsigned not null, `base_unit_id` bigint unsigned not null, `name` varchar(255) not null, `sku` varchar(255) not null, `barcode` varchar(255) null, `brand` varchar(255) null, `purchase_cost` decimal(16, 2) not null, `minimum_stock` decimal(18, 6) not null default '0', `image_url` varchar(255) null, `visual_style` varchar(255) not null default '', `active` tinyint(1) not null default '1', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `products` add constraint `products_company_id_foreign` foreign key (`company_id`) references `companies` (`id`) on delete restrict;

alter table `products` add constraint `fk_products_category_id` foreign key (`company_id`, `category_id`) references `categories` (`company_id`, `id`) on delete restrict;

alter table `products` add constraint `fk_products_base_unit_id` foreign key (`company_id`, `base_unit_id`) references `units` (`company_id`, `id`) on delete restrict;

alter table `products` add unique `products_company_id_sku_unique`(`company_id`, `sku`);

alter table `products` add index `products_category_idx`(`company_id`, `category_id`, `active`);

alter table `products` add unique `products_tenant_uq`(`company_id`, `id`);

create table `product_units` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `product_id` bigint unsigned not null, `unit_id` bigint unsigned not null, `conversion_quantity` decimal(18, 6) not null, `selling_price` decimal(16, 2) not null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `product_units` add constraint `product_units_company_id_foreign` foreign key (`company_id`) references `companies` (`id`) on delete restrict;

alter table `product_units` add constraint `fk_product_units_product_id` foreign key (`company_id`, `product_id`) references `products` (`company_id`, `id`) on delete restrict;

alter table `product_units` add constraint `fk_product_units_unit_id` foreign key (`company_id`, `unit_id`) references `units` (`company_id`, `id`) on delete restrict;

alter table `product_units` add unique `product_unit_uq`(`company_id`, `product_id`, `unit_id`);

alter table `product_units` add unique `product_units_tenant_uq`(`company_id`, `id`);

create table `stocks` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `branch_id` bigint unsigned not null, `stock_location_id` bigint unsigned not null, `product_id` bigint unsigned not null, `quantity` decimal(18, 6) not null default '0', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `stocks` add constraint `stocks_company_id_foreign` foreign key (`company_id`) references `companies` (`id`) on delete restrict;

alter table `stocks` add constraint `fk_stocks_stock_location_` foreign key (`company_id`, `stock_location_id`) references `stock_locations` (`company_id`, `id`) on delete restrict;

alter table `stocks` add constraint `fk_stocks_product_id` foreign key (`company_id`, `product_id`) references `products` (`company_id`, `id`) on delete restrict;

alter table `stocks` add unique `stock_location_product_uq`(`company_id`, `stock_location_id`, `product_id`);

alter table `stocks` add unique `stocks_tenant_uq`(`company_id`, `id`);

alter table `stocks` add index `stocks_scope_idx`(`company_id`, `branch_id`, `created_at`);

alter table `stocks` add constraint `stocks_branch_fk` foreign key (`company_id`, `branch_id`) references `branches` (`company_id`, `id`) on delete restrict;

create table `suppliers` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `name` varchar(255) not null, `contact` varchar(255) null, `category` varchar(255) null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `suppliers` add constraint `suppliers_company_id_foreign` foreign key (`company_id`) references `companies` (`id`) on delete restrict;

alter table `suppliers` add unique `suppliers_tenant_uq`(`company_id`, `id`);

create table `service_tables` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `branch_id` bigint unsigned not null, `name` varchar(255) not null, `area` varchar(255) not null, `seats` smallint unsigned not null default '4', `reservation` json null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `service_tables` add constraint `service_tables_company_id_foreign` foreign key (`company_id`) references `companies` (`id`) on delete restrict;

alter table `service_tables` add unique `service_table_name_uq`(`company_id`, `branch_id`, `name`);

alter table `service_tables` add unique `service_tables_tenant_uq`(`company_id`, `id`);

alter table `service_tables` add index `service_tables_scope_idx`(`company_id`, `branch_id`, `created_at`);

alter table `service_tables` add constraint `service_tables_branch_fk` foreign key (`company_id`, `branch_id`) references `branches` (`company_id`, `id`) on delete restrict;

create table `shifts` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `branch_id` bigint unsigned not null, `user_id` bigint unsigned not null, `stock_location_id` bigint unsigned not null, `number` varchar(255) not null, `type` varchar(10) not null, `status` varchar(10) not null default 'OPEN', `opened_at` timestamp not null, `closed_at` timestamp null, `opening_cash` decimal(16, 2) not null default '0', `expected_cash` decimal(16, 2) not null default '0', `actual_cash` decimal(16, 2) not null default '0', `cash_difference` decimal(16, 2) not null default '0', `closing_notes` text null, `override_reason` text null, `authorized_by` bigint unsigned null, `override_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `shifts` add constraint `shifts_company_id_foreign` foreign key (`company_id`) references `companies` (`id`) on delete restrict;

alter table `shifts` add constraint `fk_shifts_user_id` foreign key (`company_id`, `user_id`) references `users` (`company_id`, `id`) on delete restrict;

alter table `shifts` add constraint `fk_shifts_stock_location_` foreign key (`company_id`, `stock_location_id`) references `stock_locations` (`company_id`, `id`) on delete restrict;

alter table `shifts` add unique `shifts_company_id_number_unique`(`company_id`, `number`);

alter table `shifts` add index `shift_user_status_idx`(`company_id`, `user_id`, `status`);

alter table `shifts` add unique `shifts_tenant_uq`(`company_id`, `id`);

alter table `shifts` add index `shifts_scope_idx`(`company_id`, `branch_id`, `created_at`);

alter table `shifts` add constraint `shifts_branch_fk` foreign key (`company_id`, `branch_id`) references `branches` (`company_id`, `id`) on delete restrict;

create table `orders` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `branch_id` bigint unsigned not null, `stock_location_id` bigint unsigned not null, `waiter_id` bigint unsigned not null, `service_table_id` bigint unsigned null, `shift_id` bigint unsigned not null, `invoice_number` varchar(255) not null, `subtotal` decimal(16, 2) not null default '0', `discount` decimal(16, 2) not null default '0', `return_total` decimal(16, 2) not null default '0', `total` decimal(16, 2) not null default '0', `amount_paid` decimal(16, 2) not null default '0', `balance` decimal(16, 2) not null default '0', `credit_amount` decimal(16, 2) not null default '0', `status` varchar(25) not null default 'DRAFT', `created_by` bigint unsigned not null, `issued_by` bigint unsigned null, `issued_shift_id` bigint unsigned null, `issued_at` timestamp null, `closed_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `orders` add constraint `orders_company_id_foreign` foreign key (`company_id`) references `companies` (`id`) on delete restrict;

alter table `orders` add constraint `fk_orders_stock_location_` foreign key (`company_id`, `stock_location_id`) references `stock_locations` (`company_id`, `id`) on delete restrict;

alter table `orders` add constraint `fk_orders_waiter_id` foreign key (`company_id`, `waiter_id`) references `users` (`company_id`, `id`) on delete restrict;

alter table `orders` add constraint `fk_orders_service_table_i` foreign key (`company_id`, `service_table_id`) references `service_tables` (`company_id`, `id`) on delete restrict;

alter table `orders` add constraint `fk_orders_shift_id` foreign key (`company_id`, `shift_id`) references `shifts` (`company_id`, `id`) on delete restrict;

alter table `orders` add constraint `fk_orders_created_by` foreign key (`company_id`, `created_by`) references `users` (`company_id`, `id`) on delete restrict;

alter table `orders` add constraint `fk_orders_issued_by` foreign key (`company_id`, `issued_by`) references `users` (`company_id`, `id`) on delete restrict;

alter table `orders` add constraint `fk_orders_issued_shift_id` foreign key (`company_id`, `issued_shift_id`) references `shifts` (`company_id`, `id`) on delete restrict;

alter table `orders` add unique `order_invoice_uq`(`company_id`, `invoice_number`);

alter table `orders` add index `order_status_date_idx`(`company_id`, `branch_id`, `status`, `issued_at`);

alter table `orders` add unique `orders_tenant_uq`(`company_id`, `id`);

alter table `orders` add index `orders_scope_idx`(`company_id`, `branch_id`, `created_at`);

alter table `orders` add constraint `orders_branch_fk` foreign key (`company_id`, `branch_id`) references `branches` (`company_id`, `id`) on delete restrict;

create table `order_items` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `branch_id` bigint unsigned not null, `order_id` bigint unsigned not null, `product_id` bigint unsigned not null, `product_unit_id` bigint unsigned not null, `product_name` varchar(255) not null, `unit_name` varchar(255) not null, `quantity` decimal(18, 6) not null default '0', `base_quantity` decimal(18, 6) not null default '0', `conversion_quantity` decimal(18, 6) not null default '0', `returned_quantity` decimal(18, 6) not null default '0', `unit_price` decimal(16, 2) not null default '0', `purchase_cost` decimal(16, 2) not null default '0', `total` decimal(16, 2) not null default '0', `discount_share` decimal(16, 2) not null default '0', `returned_value` decimal(16, 2) not null default '0', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `order_items` add constraint `order_items_company_id_foreign` foreign key (`company_id`) references `companies` (`id`) on delete restrict;

alter table `order_items` add constraint `fk_order_items_order_id` foreign key (`company_id`, `order_id`) references `orders` (`company_id`, `id`) on delete restrict;

alter table `order_items` add constraint `fk_order_items_product_id` foreign key (`company_id`, `product_id`) references `products` (`company_id`, `id`) on delete restrict;

alter table `order_items` add constraint `fk_order_items_product_unit_id` foreign key (`company_id`, `product_unit_id`) references `product_units` (`company_id`, `id`) on delete restrict;

alter table `order_items` add unique `order_items_tenant_uq`(`company_id`, `id`);

alter table `order_items` add index `order_items_scope_idx`(`company_id`, `branch_id`, `created_at`);

alter table `order_items` add constraint `order_items_branch_fk` foreign key (`company_id`, `branch_id`) references `branches` (`company_id`, `id`) on delete restrict;

create table `payments` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `branch_id` bigint unsigned not null, `shift_id` bigint unsigned not null, `number` varchar(255) not null, `method` varchar(25) not null, `amount` decimal(16, 2) not null, `is_credit` tinyint(1) not null default '0', `received_by` bigint unsigned not null, `external_reference` varchar(255) null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `payments` add constraint `payments_company_id_foreign` foreign key (`company_id`) references `companies` (`id`) on delete restrict;

alter table `payments` add constraint `fk_payments_shift_id` foreign key (`company_id`, `shift_id`) references `shifts` (`company_id`, `id`) on delete restrict;

alter table `payments` add constraint `fk_payments_received_by` foreign key (`company_id`, `received_by`) references `users` (`company_id`, `id`) on delete restrict;

alter table `payments` add unique `payments_company_id_number_unique`(`company_id`, `number`);

alter table `payments` add unique `payments_tenant_uq`(`company_id`, `id`);

alter table `payments` add index `payments_scope_idx`(`company_id`, `branch_id`, `created_at`);

alter table `payments` add constraint `payments_branch_fk` foreign key (`company_id`, `branch_id`) references `branches` (`company_id`, `id`) on delete restrict;

create table `payment_allocations` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `branch_id` bigint unsigned not null, `payment_id` bigint unsigned not null, `order_id` bigint unsigned not null, `amount` decimal(16, 2) not null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `payment_allocations` add constraint `payment_allocations_company_id_foreign` foreign key (`company_id`) references `companies` (`id`) on delete restrict;

alter table `payment_allocations` add constraint `fk_payment_allocat_payment_id` foreign key (`company_id`, `payment_id`) references `payments` (`company_id`, `id`) on delete restrict;

alter table `payment_allocations` add constraint `fk_payment_allocat_order_id` foreign key (`company_id`, `order_id`) references `orders` (`company_id`, `id`) on delete restrict;

alter table `payment_allocations` add unique `payment_order_uq`(`company_id`, `payment_id`, `order_id`);

alter table `payment_allocations` add unique `payment_allocations_tenant_uq`(`company_id`, `id`);

alter table `payment_allocations` add index `payment_allocations_scope_idx`(`company_id`, `branch_id`, `created_at`);

alter table `payment_allocations` add constraint `payment_allocations_branch_fk` foreign key (`company_id`, `branch_id`) references `branches` (`company_id`, `id`) on delete restrict;

create table `returns` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `branch_id` bigint unsigned not null, `order_id` bigint unsigned not null, `shift_id` bigint unsigned not null, `number` varchar(255) not null, `reason` text not null, `total` decimal(16, 2) not null, `created_by` bigint unsigned not null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `returns` add constraint `returns_company_id_foreign` foreign key (`company_id`) references `companies` (`id`) on delete restrict;

alter table `returns` add constraint `fk_returns_order_id` foreign key (`company_id`, `order_id`) references `orders` (`company_id`, `id`) on delete restrict;

alter table `returns` add constraint `fk_returns_shift_id` foreign key (`company_id`, `shift_id`) references `shifts` (`company_id`, `id`) on delete restrict;

alter table `returns` add constraint `fk_returns_created_by` foreign key (`company_id`, `created_by`) references `users` (`company_id`, `id`) on delete restrict;

alter table `returns` add unique `returns_company_id_number_unique`(`company_id`, `number`);

alter table `returns` add unique `returns_tenant_uq`(`company_id`, `id`);

alter table `returns` add index `returns_scope_idx`(`company_id`, `branch_id`, `created_at`);

alter table `returns` add constraint `returns_branch_fk` foreign key (`company_id`, `branch_id`) references `branches` (`company_id`, `id`) on delete restrict;

create table `return_items` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `branch_id` bigint unsigned not null, `return_id` bigint unsigned not null, `order_item_id` bigint unsigned not null, `quantity` decimal(18, 6) not null, `base_quantity` decimal(18, 6) not null, `value` decimal(16, 2) not null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `return_items` add constraint `return_items_company_id_foreign` foreign key (`company_id`) references `companies` (`id`) on delete restrict;

alter table `return_items` add constraint `fk_return_items_return_id` foreign key (`company_id`, `return_id`) references `returns` (`company_id`, `id`) on delete restrict;

alter table `return_items` add constraint `fk_return_items_order_item_id` foreign key (`company_id`, `order_item_id`) references `order_items` (`company_id`, `id`) on delete restrict;

alter table `return_items` add unique `return_items_tenant_uq`(`company_id`, `id`);

alter table `return_items` add index `return_items_scope_idx`(`company_id`, `branch_id`, `created_at`);

alter table `return_items` add constraint `return_items_branch_fk` foreign key (`company_id`, `branch_id`) references `branches` (`company_id`, `id`) on delete restrict;

create table `void_requests` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `branch_id` bigint unsigned not null, `order_id` bigint unsigned not null, `number` varchar(255) not null, `reason` text not null, `status` varchar(10) not null default 'PENDING', `previous_status` varchar(25) not null, `requested_by` bigint unsigned not null, `approved_by` bigint unsigned null, `decided_at` timestamp null, `decision_reason` text null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `void_requests` add constraint `void_requests_company_id_foreign` foreign key (`company_id`) references `companies` (`id`) on delete restrict;

alter table `void_requests` add constraint `fk_void_requests_order_id` foreign key (`company_id`, `order_id`) references `orders` (`company_id`, `id`) on delete restrict;

alter table `void_requests` add constraint `fk_void_requests_requested_by` foreign key (`company_id`, `requested_by`) references `users` (`company_id`, `id`) on delete restrict;

alter table `void_requests` add unique `void_requests_company_id_number_unique`(`company_id`, `number`);

alter table `void_requests` add unique `void_requests_tenant_uq`(`company_id`, `id`);

alter table `void_requests` add index `void_requests_scope_idx`(`company_id`, `branch_id`, `created_at`);

alter table `void_requests` add constraint `void_requests_branch_fk` foreign key (`company_id`, `branch_id`) references `branches` (`company_id`, `id`) on delete restrict;

create table `goods_receipts` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `branch_id` bigint unsigned not null, `supplier_id` bigint unsigned not null, `stock_location_id` bigint unsigned not null, `number` varchar(255) not null, `supplier_invoice` varchar(255) not null, `received_on` date not null, `total_cost` decimal(16, 2) not null, `status` varchar(10) not null default 'POSTED', `created_by` bigint unsigned not null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `goods_receipts` add constraint `goods_receipts_company_id_foreign` foreign key (`company_id`) references `companies` (`id`) on delete restrict;

alter table `goods_receipts` add constraint `fk_goods_receipts_supplier_id` foreign key (`company_id`, `supplier_id`) references `suppliers` (`company_id`, `id`) on delete restrict;

alter table `goods_receipts` add constraint `fk_goods_receipts_stock_location_` foreign key (`company_id`, `stock_location_id`) references `stock_locations` (`company_id`, `id`) on delete restrict;

alter table `goods_receipts` add constraint `fk_goods_receipts_created_by` foreign key (`company_id`, `created_by`) references `users` (`company_id`, `id`) on delete restrict;

alter table `goods_receipts` add unique `goods_receipts_company_id_number_unique`(`company_id`, `number`);

alter table `goods_receipts` add unique `grn_supplier_invoice_uq`(`company_id`, `supplier_id`, `supplier_invoice`);

alter table `goods_receipts` add unique `goods_receipts_tenant_uq`(`company_id`, `id`);

alter table `goods_receipts` add index `goods_receipts_scope_idx`(`company_id`, `branch_id`, `created_at`);

alter table `goods_receipts` add constraint `goods_receipts_branch_fk` foreign key (`company_id`, `branch_id`) references `branches` (`company_id`, `id`) on delete restrict;

create table `goods_receipt_items` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `branch_id` bigint unsigned not null, `goods_receipt_id` bigint unsigned not null, `product_id` bigint unsigned not null, `product_unit_id` bigint unsigned not null, `quantity` decimal(18, 6) not null, `base_quantity` decimal(18, 6) not null, `unit_cost` decimal(16, 2) not null, `total_cost` decimal(16, 2) not null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `goods_receipt_items` add constraint `goods_receipt_items_company_id_foreign` foreign key (`company_id`) references `companies` (`id`) on delete restrict;

alter table `goods_receipt_items` add constraint `fk_goods_receipt_i_goods_receipt_i` foreign key (`company_id`, `goods_receipt_id`) references `goods_receipts` (`company_id`, `id`) on delete restrict;

alter table `goods_receipt_items` add constraint `fk_goods_receipt_i_product_id` foreign key (`company_id`, `product_id`) references `products` (`company_id`, `id`) on delete restrict;

alter table `goods_receipt_items` add constraint `fk_goods_receipt_i_product_unit_id` foreign key (`company_id`, `product_unit_id`) references `product_units` (`company_id`, `id`) on delete restrict;

alter table `goods_receipt_items` add unique `goods_receipt_items_tenant_uq`(`company_id`, `id`);

alter table `goods_receipt_items` add index `goods_receipt_items_scope_idx`(`company_id`, `branch_id`, `created_at`);

alter table `goods_receipt_items` add constraint `goods_receipt_items_branch_fk` foreign key (`company_id`, `branch_id`) references `branches` (`company_id`, `id`) on delete restrict;

create table `stock_transfers` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `branch_id` bigint unsigned not null, `source_location_id` bigint unsigned not null, `destination_location_id` bigint unsigned not null, `number` varchar(255) not null, `status` varchar(15) not null default 'DRAFT', `notes` text null, `created_by` bigint unsigned not null, `received_by` bigint unsigned null, `sent_at` timestamp null, `received_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `stock_transfers` add constraint `stock_transfers_company_id_foreign` foreign key (`company_id`) references `companies` (`id`) on delete restrict;

alter table `stock_transfers` add constraint `fk_stock_transfers_source_location` foreign key (`company_id`, `source_location_id`) references `stock_locations` (`company_id`, `id`) on delete restrict;

alter table `stock_transfers` add constraint `fk_stock_transfers_destination_loc` foreign key (`company_id`, `destination_location_id`) references `stock_locations` (`company_id`, `id`) on delete restrict;

alter table `stock_transfers` add constraint `fk_stock_transfers_created_by` foreign key (`company_id`, `created_by`) references `users` (`company_id`, `id`) on delete restrict;

alter table `stock_transfers` add unique `stock_transfers_company_id_number_unique`(`company_id`, `number`);

alter table `stock_transfers` add unique `stock_transfers_tenant_uq`(`company_id`, `id`);

alter table `stock_transfers` add index `stock_transfers_scope_idx`(`company_id`, `branch_id`, `created_at`);

alter table `stock_transfers` add constraint `stock_transfers_branch_fk` foreign key (`company_id`, `branch_id`) references `branches` (`company_id`, `id`) on delete restrict;

create table `stock_transfer_items` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `branch_id` bigint unsigned not null, `stock_transfer_id` bigint unsigned not null, `product_id` bigint unsigned not null, `product_unit_id` bigint unsigned not null, `quantity` decimal(18, 6) not null, `base_quantity` decimal(18, 6) not null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `stock_transfer_items` add constraint `stock_transfer_items_company_id_foreign` foreign key (`company_id`) references `companies` (`id`) on delete restrict;

alter table `stock_transfer_items` add constraint `fk_stock_transfer__stock_transfer_` foreign key (`company_id`, `stock_transfer_id`) references `stock_transfers` (`company_id`, `id`) on delete restrict;

alter table `stock_transfer_items` add constraint `fk_stock_transfer__product_id` foreign key (`company_id`, `product_id`) references `products` (`company_id`, `id`) on delete restrict;

alter table `stock_transfer_items` add constraint `fk_stock_transfer__product_unit_id` foreign key (`company_id`, `product_unit_id`) references `product_units` (`company_id`, `id`) on delete restrict;

alter table `stock_transfer_items` add unique `stock_transfer_items_tenant_uq`(`company_id`, `id`);

alter table `stock_transfer_items` add index `stock_transfer_items_scope_idx`(`company_id`, `branch_id`, `created_at`);

alter table `stock_transfer_items` add constraint `stock_transfer_items_branch_fk` foreign key (`company_id`, `branch_id`) references `branches` (`company_id`, `id`) on delete restrict;

create table `stock_adjustments` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `branch_id` bigint unsigned not null, `stock_location_id` bigint unsigned not null, `number` varchar(255) not null, `reason` varchar(25) not null, `notes` text not null, `status` varchar(10) not null default 'PENDING', `requested_by` bigint unsigned not null, `approved_by` bigint unsigned null, `decided_at` timestamp null, `decision_reason` text null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `stock_adjustments` add constraint `stock_adjustments_company_id_foreign` foreign key (`company_id`) references `companies` (`id`) on delete restrict;

alter table `stock_adjustments` add constraint `fk_stock_adjustmen_stock_location_` foreign key (`company_id`, `stock_location_id`) references `stock_locations` (`company_id`, `id`) on delete restrict;

alter table `stock_adjustments` add constraint `fk_stock_adjustmen_requested_by` foreign key (`company_id`, `requested_by`) references `users` (`company_id`, `id`) on delete restrict;

alter table `stock_adjustments` add unique `stock_adjustments_company_id_number_unique`(`company_id`, `number`);

alter table `stock_adjustments` add unique `stock_adjustments_tenant_uq`(`company_id`, `id`);

alter table `stock_adjustments` add index `stock_adjustments_scope_idx`(`company_id`, `branch_id`, `created_at`);

alter table `stock_adjustments` add constraint `stock_adjustments_branch_fk` foreign key (`company_id`, `branch_id`) references `branches` (`company_id`, `id`) on delete restrict;

create table `stock_adjustment_items` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `branch_id` bigint unsigned not null, `stock_adjustment_id` bigint unsigned not null, `product_id` bigint unsigned not null, `product_unit_id` bigint unsigned not null, `quantity` decimal(18, 6) not null, `base_quantity` decimal(18, 6) not null, `cost` decimal(16, 2) not null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `stock_adjustment_items` add constraint `stock_adjustment_items_company_id_foreign` foreign key (`company_id`) references `companies` (`id`) on delete restrict;

alter table `stock_adjustment_items` add constraint `fk_stock_adjustmen_stock_adjustmen` foreign key (`company_id`, `stock_adjustment_id`) references `stock_adjustments` (`company_id`, `id`) on delete restrict;

alter table `stock_adjustment_items` add constraint `fk_stock_adjustmen_product_id` foreign key (`company_id`, `product_id`) references `products` (`company_id`, `id`) on delete restrict;

alter table `stock_adjustment_items` add constraint `fk_stock_adjustmen_product_unit_id` foreign key (`company_id`, `product_unit_id`) references `product_units` (`company_id`, `id`) on delete restrict;

alter table `stock_adjustment_items` add unique `stock_adjustment_ite_tenant_uq`(`company_id`, `id`);

alter table `stock_adjustment_items` add index `stock_adjustment_ite_scope_idx`(`company_id`, `branch_id`, `created_at`);

alter table `stock_adjustment_items` add constraint `stock_adjustment_ite_branch_fk` foreign key (`company_id`, `branch_id`) references `branches` (`company_id`, `id`) on delete restrict;

create table `stock_movements` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `branch_id` bigint unsigned not null, `stock_location_id` bigint unsigned not null, `product_id` bigint unsigned not null, `shift_id` bigint unsigned null, `movement_type` varchar(30) not null, `quantity` decimal(18, 6) not null, `balance_after` decimal(18, 6) not null, `unit_cost` decimal(16, 2) not null, `reference_type` varchar(35) not null, `reference_id` bigint unsigned not null, `reversal_of` bigint unsigned null, `created_by` bigint unsigned not null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `stock_movements` add constraint `stock_movements_company_id_foreign` foreign key (`company_id`) references `companies` (`id`) on delete restrict;

alter table `stock_movements` add constraint `fk_stock_movements_stock_location_` foreign key (`company_id`, `stock_location_id`) references `stock_locations` (`company_id`, `id`) on delete restrict;

alter table `stock_movements` add constraint `fk_stock_movements_product_id` foreign key (`company_id`, `product_id`) references `products` (`company_id`, `id`) on delete restrict;

alter table `stock_movements` add constraint `fk_stock_movements_shift_id` foreign key (`company_id`, `shift_id`) references `shifts` (`company_id`, `id`) on delete restrict;

alter table `stock_movements` add constraint `fk_stock_movements_created_by` foreign key (`company_id`, `created_by`) references `users` (`company_id`, `id`) on delete restrict;

alter table `stock_movements` add index `movement_source_idx`(`company_id`, `reference_type`, `reference_id`);

alter table `stock_movements` add unique `stock_movements_tenant_uq`(`company_id`, `id`);

alter table `stock_movements` add index `stock_movements_scope_idx`(`company_id`, `branch_id`, `created_at`);

alter table `stock_movements` add constraint `stock_movements_branch_fk` foreign key (`company_id`, `branch_id`) references `branches` (`company_id`, `id`) on delete restrict;

create table `waiter_ledger_entries` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `branch_id` bigint unsigned not null, `waiter_id` bigint unsigned not null, `shift_id` bigint unsigned not null, `order_id` bigint unsigned not null, `payment_id` bigint unsigned null, `return_id` bigint unsigned null, `entry_type` varchar(15) not null, `reference_type` varchar(35) not null, `reference_id` bigint unsigned not null, `debit` decimal(16, 2) not null default '0', `credit` decimal(16, 2) not null default '0', `description` text not null, `created_by` bigint unsigned not null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `waiter_ledger_entries` add constraint `waiter_ledger_entries_company_id_foreign` foreign key (`company_id`) references `companies` (`id`) on delete restrict;

alter table `waiter_ledger_entries` add constraint `fk_waiter_ledger_e_waiter_id` foreign key (`company_id`, `waiter_id`) references `users` (`company_id`, `id`) on delete restrict;

alter table `waiter_ledger_entries` add constraint `fk_waiter_ledger_e_shift_id` foreign key (`company_id`, `shift_id`) references `shifts` (`company_id`, `id`) on delete restrict;

alter table `waiter_ledger_entries` add constraint `fk_waiter_ledger_e_order_id` foreign key (`company_id`, `order_id`) references `orders` (`company_id`, `id`) on delete restrict;

alter table `waiter_ledger_entries` add constraint `fk_waiter_ledger_e_payment_id` foreign key (`company_id`, `payment_id`) references `payments` (`company_id`, `id`) on delete restrict;

alter table `waiter_ledger_entries` add constraint `fk_waiter_ledger_e_return_id` foreign key (`company_id`, `return_id`) references `returns` (`company_id`, `id`) on delete restrict;

alter table `waiter_ledger_entries` add constraint `fk_waiter_ledger_e_created_by` foreign key (`company_id`, `created_by`) references `users` (`company_id`, `id`) on delete restrict;

alter table `waiter_ledger_entries` add index `waiter_balance_idx`(`company_id`, `waiter_id`, `created_at`);

alter table `waiter_ledger_entries` add unique `waiter_ledger_entrie_tenant_uq`(`company_id`, `id`);

alter table `waiter_ledger_entries` add index `waiter_ledger_entrie_scope_idx`(`company_id`, `branch_id`, `created_at`);

alter table `waiter_ledger_entries` add constraint `waiter_ledger_entrie_branch_fk` foreign key (`company_id`, `branch_id`) references `branches` (`company_id`, `id`) on delete restrict;

create table `audit_logs` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `branch_id` bigint unsigned null, `user_id` bigint unsigned null, `user_name` varchar(255) not null, `action` varchar(40) not null, `module` varchar(30) not null, `reference_type` varchar(40) not null, `reference_id` varchar(255) not null, `old_values` json not null, `new_values` json not null, `ip` varchar(45) null, `user_agent` text null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `audit_logs` add constraint `audit_logs_company_id_foreign` foreign key (`company_id`) references `companies` (`id`) on delete restrict;

alter table `audit_logs` add index `audit_scope_idx`(`company_id`, `branch_id`, `created_at`);

alter table `audit_logs` add unique `audit_logs_tenant_uq`(`company_id`, `id`);

create table `notifications` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `branch_id` bigint unsigned not null, `type` varchar(40) not null, `title` varchar(255) not null, `message` text not null, `screen` varchar(30) not null, `read_by` json null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `notifications` add constraint `notifications_company_id_foreign` foreign key (`company_id`) references `companies` (`id`) on delete restrict;

alter table `notifications` add unique `notifications_tenant_uq`(`company_id`, `id`);

alter table `notifications` add index `notifications_scope_idx`(`company_id`, `branch_id`, `created_at`);

alter table `notifications` add constraint `notifications_branch_fk` foreign key (`company_id`, `branch_id`) references `branches` (`company_id`, `id`) on delete restrict;

create table `document_sequences` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `type` varchar(8) not null, `value` bigint unsigned not null default '0') default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `document_sequences` add constraint `document_sequences_company_id_foreign` foreign key (`company_id`) references `companies` (`id`);

alter table `document_sequences` add unique `document_sequences_company_id_type_unique`(`company_id`, `type`);

create table `idempotency_keys` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `user_id` bigint unsigned not null, `key` varchar(80) not null, `action` varchar(60) not null, `payload_hash` varchar(64) not null, `response` json not null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB;

alter table `idempotency_keys` add constraint `idempotency_keys_company_id_foreign` foreign key (`company_id`) references `companies` (`id`);

alter table `idempotency_keys` add constraint `idempotency_keys_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete restrict;

alter table `idempotency_keys` add unique `idempotency_keys_company_id_key_unique`(`company_id`, `key`);

ALTER TABLE stocks ADD CONSTRAINT stock_quantity_nonnegative CHECK (quantity >= 0);

ALTER TABLE product_units ADD CONSTRAINT conversion_positive CHECK (conversion_quantity > 0 AND selling_price >= 0);

ALTER TABLE orders ADD CONSTRAINT order_amounts_valid CHECK (balance >= 0 AND amount_paid >= 0 AND total >= 0);

CREATE TRIGGER audit_logs_immutable_update BEFORE UPDATE ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable transaction history';

CREATE TRIGGER audit_logs_immutable_delete BEFORE DELETE ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable transaction history';

CREATE TRIGGER waiter_ledger_entries_immutable_update BEFORE UPDATE ON waiter_ledger_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable transaction history';

CREATE TRIGGER waiter_ledger_entries_immutable_delete BEFORE DELETE ON waiter_ledger_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable transaction history';

CREATE TRIGGER stock_movements_immutable_update BEFORE UPDATE ON stock_movements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable transaction history';

CREATE TRIGGER stock_movements_immutable_delete BEFORE DELETE ON stock_movements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable transaction history';

CREATE TRIGGER payments_immutable_update BEFORE UPDATE ON payments FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable transaction history';

CREATE TRIGGER payments_immutable_delete BEFORE DELETE ON payments FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable transaction history';

CREATE TRIGGER payment_allocations_immutable_update BEFORE UPDATE ON payment_allocations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable transaction history';

CREATE TRIGGER payment_allocations_immutable_delete BEFORE DELETE ON payment_allocations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable transaction history';

CREATE TRIGGER return_items_immutable_update BEFORE UPDATE ON return_items FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable transaction history';

CREATE TRIGGER return_items_immutable_delete BEFORE DELETE ON return_items FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable transaction history';

CREATE TRIGGER goods_receipt_items_immutable_update BEFORE UPDATE ON goods_receipt_items FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable transaction history';

CREATE TRIGGER goods_receipt_items_immutable_delete BEFORE DELETE ON goods_receipt_items FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable transaction history';

alter table `shifts` add constraint `integrity_57907dccac36` foreign key (`company_id`, `authorized_by`) references `users` (`company_id`, `id`) on delete restrict;

alter table `void_requests` add constraint `integrity_3757b83418e9` foreign key (`company_id`, `approved_by`) references `users` (`company_id`, `id`) on delete restrict;

alter table `stock_adjustments` add constraint `integrity_7c42bc88760c` foreign key (`company_id`, `approved_by`) references `users` (`company_id`, `id`) on delete restrict;

alter table `stock_transfers` add constraint `integrity_051f8e0dc59a` foreign key (`company_id`, `received_by`) references `users` (`company_id`, `id`) on delete restrict;

alter table `stock_movements` add constraint `integrity_f00af9d55eb0` foreign key (`company_id`, `reversal_of`) references `stock_movements` (`company_id`, `id`) on delete restrict;

alter table `audit_logs` add constraint `integrity_822cb854fad5` foreign key (`company_id`, `branch_id`) references `branches` (`company_id`, `id`) on delete restrict;

alter table `audit_logs` add constraint `integrity_752b0e2bc4a9` foreign key (`company_id`, `user_id`) references `users` (`company_id`, `id`) on delete restrict;

alter table `payments` add `order_id` bigint unsigned null;

alter table `payments` add constraint `payment_source_order_fk` foreign key (`company_id`, `order_id`) references `orders` (`company_id`, `id`) on delete restrict;
