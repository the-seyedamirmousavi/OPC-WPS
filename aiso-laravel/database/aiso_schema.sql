SET FOREIGN_KEY_CHECKS=0;
SET NAMES utf8mb4;
DROP TABLE IF EXISTS `agent_tasks`;
CREATE TABLE `agent_tasks` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `agent_id` VARCHAR(191) NOT NULL,
  `created_by` VARCHAR(191) NOT NULL,
  `instruction` LONGTEXT NOT NULL,
  `status` VARCHAR(191) NOT NULL DEFAULT 'PENDING',
  `result` LONGTEXT NULL,
  `error` VARCHAR(191) NULL,
  `run_id` BIGINT NULL,
  `created_at` DATETIME NOT NULL,
  `started_at` DATETIME NULL,
  `finished_at` DATETIME NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `ai_action_policies`;
CREATE TABLE `ai_action_policies` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `kind` VARCHAR(191) NOT NULL,
  `action` VARCHAR(191) NOT NULL,
  `mode` VARCHAR(191) NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `ai_pending_actions`;
CREATE TABLE `ai_pending_actions` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `run_id` BIGINT NOT NULL,
  `user_id` VARCHAR(191) NOT NULL,
  `department` VARCHAR(191) NOT NULL,
  `operation` VARCHAR(191) NOT NULL,
  `params` LONGTEXT NOT NULL,
  `reason` VARCHAR(191) NULL,
  `risk` VARCHAR(191) NOT NULL,
  `status` VARCHAR(191) NOT NULL DEFAULT 'PENDING',
  `result` LONGTEXT NULL,
  `error` VARCHAR(191) NULL,
  `expires_at` DATETIME NOT NULL,
  `decided_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  `approval` VARCHAR(191) NOT NULL DEFAULT 'SELF',
  `approved_by` VARCHAR(191) NULL,
  `decision_note` VARCHAR(191) NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `ai_run_steps`;
CREATE TABLE `ai_run_steps` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `run_id` BIGINT NOT NULL,
  `stage` VARCHAR(191) NOT NULL,
  `department` VARCHAR(191) NULL,
  `input` LONGTEXT NULL,
  `output` LONGTEXT NULL,
  `tokens_in` BIGINT NOT NULL DEFAULT '0',
  `tokens_out` BIGINT NOT NULL DEFAULT '0',
  `error` VARCHAR(191) NULL,
  `duration_ms` BIGINT NOT NULL DEFAULT '0',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `ai_runs`;
CREATE TABLE `ai_runs` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `user_id` VARCHAR(191) NOT NULL,
  `question` LONGTEXT NOT NULL,
  `status` VARCHAR(191) NOT NULL DEFAULT 'RUNNING',
  `rounds` BIGINT NOT NULL DEFAULT '0',
  `tokens_in` BIGINT NOT NULL DEFAULT '0',
  `tokens_out` BIGINT NOT NULL DEFAULT '0',
  `error` VARCHAR(191) NULL,
  `duration_ms` BIGINT NOT NULL DEFAULT '0',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `app_role`;
CREATE TABLE `app_role` (
  `key` VARCHAR(191) NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `description` VARCHAR(191) NULL,
  `receives_tasks` TINYINT(1) NOT NULL DEFAULT '0',
  `capabilities` LONGTEXT NOT NULL,
  `builtin` TINYINT(1) NOT NULL DEFAULT '0',
  `locked` TINYINT(1) NOT NULL DEFAULT '0',
  `sort_order` BIGINT NOT NULL DEFAULT '0',
  `created_at` DATETIME NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `app_user`;
CREATE TABLE `app_user` (
  `id` VARCHAR(191) NOT NULL,
  `full_name` VARCHAR(191) NOT NULL,
  `user_role` VARCHAR(191) NOT NULL,
  `messenger_id` VARCHAR(191) NULL,
  `active` TINYINT(1) NOT NULL DEFAULT '1',
  `contact_info` VARCHAR(191) NULL,
  `password_hash` VARCHAR(191) NOT NULL,
  `must_change_password` TINYINT(1) NOT NULL DEFAULT '0',
  `created_at` DATETIME NOT NULL,
  `agent_instructions` LONGTEXT NULL,
  `agent_created_by` VARCHAR(191) NULL,
  `agent_scope` LONGTEXT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `assignment_proposal`;
CREATE TABLE `assignment_proposal` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `run_id` VARCHAR(191) NOT NULL,
  `operation_id` VARCHAR(191) NOT NULL,
  `user_id` VARCHAR(191) NOT NULL,
  `source` VARCHAR(191) NOT NULL,
  `reason` VARCHAR(191) NULL,
  `planned_start` DATETIME NULL,
  `planned_end` DATETIME NULL,
  `status` VARCHAR(191) NOT NULL DEFAULT 'PENDING',
  `decision_note` VARCHAR(191) NULL,
  `created_at` DATETIME NOT NULL,
  `decided_at` DATETIME NULL,
  `decided_by` VARCHAR(191) NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `assignment_run`;
CREATE TABLE `assignment_run` (
  `id` VARCHAR(191) NOT NULL,
  `requested_mode` VARCHAR(191) NOT NULL,
  `effective_mode` VARCHAR(191) NOT NULL,
  `fallback` TINYINT(1) NOT NULL DEFAULT '0',
  `model` VARCHAR(191) NULL,
  `summary` VARCHAR(191) NULL,
  `warnings` VARCHAR(191) NULL,
  `unassigned` VARCHAR(191) NULL,
  `requested_by` VARCHAR(191) NOT NULL,
  `created_at` DATETIME NOT NULL,
  `duration_ms` BIGINT NOT NULL DEFAULT '0',
  `input_tokens` BIGINT NULL,
  `output_tokens` BIGINT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `audit_event`;
CREATE TABLE `audit_event` (
  `event_id` VARCHAR(191) NOT NULL,
  `occurred_at` DATETIME NOT NULL,
  `actor_id` VARCHAR(191) NULL,
  `action_name` VARCHAR(191) NOT NULL,
  `entity_type` VARCHAR(191) NOT NULL,
  `entity_id` VARCHAR(191) NULL,
  `previous_value` VARCHAR(191) NULL,
  `new_value` VARCHAR(191) NULL,
  `reason` VARCHAR(191) NULL,
  `project_id` VARCHAR(191) NULL,
  PRIMARY KEY (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `chat_conversation`;
CREATE TABLE `chat_conversation` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `user_id` VARCHAR(191) NOT NULL,
  `title` VARCHAR(191) NOT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  `dialogue_state` LONGTEXT NULL,
  `state_version` BIGINT NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `chat_message`;
CREATE TABLE `chat_message` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `conversation_id` BIGINT NOT NULL,
  `role` VARCHAR(191) NOT NULL,
  `content` LONGTEXT NOT NULL,
  `created_at` DATETIME NOT NULL,
  `choices` LONGTEXT NULL,
  `request_id` VARCHAR(191) NULL,
  `run_id` BIGINT NULL,
  `attachment` LONGTEXT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `chat_upload`;
CREATE TABLE `chat_upload` (
  `id` VARCHAR(191) NOT NULL,
  `user_id` VARCHAR(191) NOT NULL,
  `conversation_id` BIGINT NULL,
  `file_name` VARCHAR(191) NOT NULL,
  `path` VARCHAR(191) NOT NULL,
  `size` BIGINT NOT NULL,
  `created_at` DATETIME NULL,
  `expires_at` DATETIME NULL,
  `imported_at` DATETIME NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `company`;
CREATE TABLE `company` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `installation_id` VARCHAR(191) NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `legal_name` VARCHAR(191) NULL,
  `contact_name` VARCHAR(191) NULL,
  `contact_phone` VARCHAR(191) NULL,
  `contact_email` VARCHAR(191) NULL,
  `notes` VARCHAR(191) NULL,
  `plan` VARCHAR(191) NOT NULL DEFAULT 'UNLIMITED',
  `status` VARCHAR(191) NOT NULL DEFAULT 'ACTIVE',
  `suspended_reason` VARCHAR(191) NULL,
  `starts_at` DATETIME NULL,
  `expires_at` DATETIME NULL,
  `grace_days` BIGINT NOT NULL DEFAULT '0',
  `max_users` BIGINT NULL,
  `max_active_projects` BIGINT NULL,
  `allow_llm` TINYINT(1) NOT NULL DEFAULT '1',
  `allow_bale` TINYINT(1) NOT NULL DEFAULT '1',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `department`;
CREATE TABLE `department` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(191) NOT NULL,
  `description` VARCHAR(191) NULL,
  `status` VARCHAR(191) NOT NULL DEFAULT 'ACTIVE',
  `sort_order` BIGINT NOT NULL DEFAULT '0',
  `created_at` DATETIME NOT NULL,
  `code` VARCHAR(191) NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `department_member`;
CREATE TABLE `department_member` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `department_id` BIGINT NOT NULL,
  `user_id` VARCHAR(191) NOT NULL,
  `role` VARCHAR(191) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `department_role_permission`;
CREATE TABLE `department_role_permission` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `department_id` BIGINT NOT NULL,
  `role` VARCHAR(191) NOT NULL,
  `permission` VARCHAR(191) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `department_suggestion`;
CREATE TABLE `department_suggestion` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `department_id` BIGINT NOT NULL,
  `author_id` VARCHAR(191) NOT NULL,
  `resource_id` VARCHAR(191) NULL,
  `title` VARCHAR(191) NOT NULL,
  `body` VARCHAR(191) NOT NULL,
  `status` VARCHAR(191) NOT NULL DEFAULT 'PENDING',
  `decided_by` VARCHAR(191) NULL,
  `decided_at` DATETIME NULL,
  `manager_note` VARCHAR(191) NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `import_log`;
CREATE TABLE `import_log` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `imported_at` DATETIME NOT NULL,
  `actor_id` VARCHAR(191) NOT NULL,
  `file_name` VARCHAR(191) NULL,
  `file_format` VARCHAR(191) NULL,
  `status` VARCHAR(191) NOT NULL,
  `error_count` BIGINT NOT NULL DEFAULT '0',
  `summary` VARCHAR(191) NULL,
  `details` LONGTEXT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `item`;
CREATE TABLE `item` (
  `id` VARCHAR(191) NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `quantity` DOUBLE NOT NULL DEFAULT '1',
  `unit` VARCHAR(191) NULL,
  `supply_type` VARCHAR(191) NULL,
  `related_operation_id` VARCHAR(191) NULL,
  `description` VARCHAR(191) NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `license_event`;
CREATE TABLE `license_event` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `occurred_at` DATETIME NOT NULL,
  `action` VARCHAR(191) NOT NULL,
  `detail` VARCHAR(191) NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `migrations`;
CREATE TABLE `migrations` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `migration` VARCHAR(191) NOT NULL,
  `batch` BIGINT NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `notification`;
CREATE TABLE `notification` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `recipient_id` VARCHAR(191) NOT NULL,
  `kind` VARCHAR(191) NOT NULL,
  `message` VARCHAR(191) NOT NULL,
  `related_operation_id` VARCHAR(191) NULL,
  `created_at` DATETIME NOT NULL,
  `read_at` DATETIME NULL,
  `delivery_status` VARCHAR(191) NOT NULL DEFAULT 'SENT',
  `delivery_error` VARCHAR(191) NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `operation`;
CREATE TABLE `operation` (
  `id` VARCHAR(191) NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `project_id` VARCHAR(191) NOT NULL,
  `item_id` VARCHAR(191) NULL,
  `resource_id` VARCHAR(191) NOT NULL,
  `responsible_user_id` VARCHAR(191) NULL,
  `preparation_time` DOUBLE NOT NULL DEFAULT '0',
  `transport_time` DOUBLE NOT NULL DEFAULT '0',
  `setup_time` DOUBLE NOT NULL DEFAULT '0',
  `direct_time` DOUBLE NOT NULL DEFAULT '0',
  `status` VARCHAR(191) NOT NULL DEFAULT 'NOT_READY',
  `assigned_user_id` VARCHAR(191) NULL,
  `block_reason` VARCHAR(191) NULL,
  `status_before_block` VARCHAR(191) NULL,
  `progress_percent` BIGINT NOT NULL DEFAULT '0',
  `planned_start` DATETIME NULL,
  `planned_end` DATETIME NULL,
  `assigned_at` DATETIME NULL,
  `started_at` DATETIME NULL,
  `completed_at` DATETIME NULL,
  `completion_approved` TINYINT(1) NOT NULL DEFAULT '0',
  `completion_approved_by` VARCHAR(191) NULL,
  `cancel_reason` VARCHAR(191) NULL,
  `description` VARCHAR(191) NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  `version` BIGINT NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `operation_report`;
CREATE TABLE `operation_report` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `operation_id` VARCHAR(191) NOT NULL,
  `user_id` VARCHAR(191) NOT NULL,
  `report_type` VARCHAR(191) NOT NULL,
  `progress_percent` BIGINT NULL,
  `note` VARCHAR(191) NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `plan_baseline`;
CREATE TABLE `plan_baseline` (
  `operation_id` VARCHAR(191) NOT NULL,
  `planned_start` DATETIME NOT NULL,
  `planned_end` DATETIME NOT NULL,
  `saved_at` DATETIME NOT NULL,
  PRIMARY KEY (`operation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `predecessor`;
CREATE TABLE `predecessor` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `operation_id` VARCHAR(191) NOT NULL,
  `predecessor_id` VARCHAR(191) NOT NULL,
  `dependency_type` VARCHAR(191) NOT NULL DEFAULT 'FINISH_TO_START',
  `start_condition` VARCHAR(191) NULL,
  `mandatory` TINYINT(1) NOT NULL DEFAULT '1',
  `description` VARCHAR(191) NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `project`;
CREATE TABLE `project` (
  `id` VARCHAR(191) NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `priority` BIGINT NOT NULL DEFAULT '1',
  `due_date` DATETIME NULL,
  `status` VARCHAR(191) NOT NULL DEFAULT 'ACTIVE',
  `description` VARCHAR(191) NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `schedule_force`;
CREATE TABLE `schedule_force` (
  `operation_id` VARCHAR(191) NOT NULL,
  `forced_start` DATETIME NULL,
  `forced_user_id` VARCHAR(191) NULL,
  `reason` VARCHAR(191) NULL,
  `created_by` VARCHAR(191) NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`operation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `sessions`;
CREATE TABLE `sessions` (
  `id` VARCHAR(191) NOT NULL,
  `user_id` BIGINT NULL,
  `ip_address` VARCHAR(191) NULL,
  `user_agent` LONGTEXT NULL,
  `payload` LONGTEXT NOT NULL,
  `last_activity` BIGINT NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `system_setting`;
CREATE TABLE `system_setting` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `project_id` VARCHAR(191) NULL,
  `project_name` VARCHAR(191) NULL,
  `owner_id` VARCHAR(191) NULL,
  `manager_id` VARCHAR(191) NULL,
  `messenger_platform` VARCHAR(191) NOT NULL DEFAULT 'IN_APP',
  `system_status` VARCHAR(191) NOT NULL DEFAULT 'ACTIVE',
  `data_version` VARCHAR(191) NULL,
  `configuration_version` BIGINT NOT NULL DEFAULT '1',
  `assignment_mode` VARCHAR(191) NOT NULL DEFAULT 'ALGORITHM',
  `max_active_tasks_per_user` BIGINT NOT NULL DEFAULT '3',
  `language` VARCHAR(191) NOT NULL DEFAULT 'en',
  `require_completion_approval` TINYINT(1) NOT NULL DEFAULT '0',
  `updated_at` DATETIME NOT NULL,
  `planning_goal` VARCHAR(191) NOT NULL DEFAULT 'PRIORITY',
  `planning_focus_project` VARCHAR(191) NULL,
  `llm_api_key` LONGTEXT NULL,
  `llm_model` VARCHAR(191) NULL,
  `ai_writes_enabled` TINYINT(1) NOT NULL DEFAULT '1',
  `bale_bot_token` LONGTEXT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `work_resource`;
CREATE TABLE `work_resource` (
  `id` VARCHAR(191) NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `resource_type` VARCHAR(191) NULL,
  `responsible_user_id` VARCHAR(191) NULL,
  `capacity` BIGINT NOT NULL DEFAULT '1',
  `status` VARCHAR(191) NOT NULL DEFAULT 'ACTIVE',
  `description` VARCHAR(191) NULL,
  `sort_order` BIGINT NOT NULL DEFAULT '0',
  `department_id` BIGINT NULL,
  `reviewed_at` DATETIME NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `agent_tasks` ADD CONSTRAINT `fk_agent_tasks_created_by` FOREIGN KEY (`created_by`) REFERENCES `app_user` (`id`);
ALTER TABLE `agent_tasks` ADD CONSTRAINT `fk_agent_tasks_agent_id` FOREIGN KEY (`agent_id`) REFERENCES `app_user` (`id`);
ALTER TABLE `ai_pending_actions` ADD CONSTRAINT `fk_ai_pending_actions_user_id` FOREIGN KEY (`user_id`) REFERENCES `app_user` (`id`);
ALTER TABLE `ai_pending_actions` ADD CONSTRAINT `fk_ai_pending_actions_run_id` FOREIGN KEY (`run_id`) REFERENCES `ai_runs` (`id`);
ALTER TABLE `ai_run_steps` ADD CONSTRAINT `fk_ai_run_steps_run_id` FOREIGN KEY (`run_id`) REFERENCES `ai_runs` (`id`);
ALTER TABLE `ai_runs` ADD CONSTRAINT `fk_ai_runs_user_id` FOREIGN KEY (`user_id`) REFERENCES `app_user` (`id`);
ALTER TABLE `assignment_proposal` ADD CONSTRAINT `fk_assignment_proposal_user_id` FOREIGN KEY (`user_id`) REFERENCES `app_user` (`id`);
ALTER TABLE `assignment_proposal` ADD CONSTRAINT `fk_assignment_proposal_operation_id` FOREIGN KEY (`operation_id`) REFERENCES `operation` (`id`);
ALTER TABLE `assignment_proposal` ADD CONSTRAINT `fk_assignment_proposal_run_id` FOREIGN KEY (`run_id`) REFERENCES `assignment_run` (`id`);
ALTER TABLE `chat_conversation` ADD CONSTRAINT `fk_chat_conversation_user_id` FOREIGN KEY (`user_id`) REFERENCES `app_user` (`id`);
ALTER TABLE `chat_message` ADD CONSTRAINT `fk_chat_message_conversation_id` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversation` (`id`);
ALTER TABLE `department_member` ADD CONSTRAINT `fk_department_member_user_id` FOREIGN KEY (`user_id`) REFERENCES `app_user` (`id`);
ALTER TABLE `department_member` ADD CONSTRAINT `fk_department_member_department_id` FOREIGN KEY (`department_id`) REFERENCES `department` (`id`);
ALTER TABLE `department_role_permission` ADD CONSTRAINT `fk_department_role_permission_department_id` FOREIGN KEY (`department_id`) REFERENCES `department` (`id`);
ALTER TABLE `department_suggestion` ADD CONSTRAINT `fk_department_suggestion_decided_by` FOREIGN KEY (`decided_by`) REFERENCES `app_user` (`id`);
ALTER TABLE `department_suggestion` ADD CONSTRAINT `fk_department_suggestion_resource_id` FOREIGN KEY (`resource_id`) REFERENCES `work_resource` (`id`);
ALTER TABLE `department_suggestion` ADD CONSTRAINT `fk_department_suggestion_author_id` FOREIGN KEY (`author_id`) REFERENCES `app_user` (`id`);
ALTER TABLE `department_suggestion` ADD CONSTRAINT `fk_department_suggestion_department_id` FOREIGN KEY (`department_id`) REFERENCES `department` (`id`);
ALTER TABLE `notification` ADD CONSTRAINT `fk_notification_recipient_id` FOREIGN KEY (`recipient_id`) REFERENCES `app_user` (`id`);
ALTER TABLE `operation` ADD CONSTRAINT `fk_operation_assigned_user_id` FOREIGN KEY (`assigned_user_id`) REFERENCES `app_user` (`id`);
ALTER TABLE `operation` ADD CONSTRAINT `fk_operation_responsible_user_id` FOREIGN KEY (`responsible_user_id`) REFERENCES `app_user` (`id`);
ALTER TABLE `operation` ADD CONSTRAINT `fk_operation_resource_id` FOREIGN KEY (`resource_id`) REFERENCES `work_resource` (`id`);
ALTER TABLE `operation` ADD CONSTRAINT `fk_operation_item_id` FOREIGN KEY (`item_id`) REFERENCES `item` (`id`);
ALTER TABLE `operation` ADD CONSTRAINT `fk_operation_project_id` FOREIGN KEY (`project_id`) REFERENCES `project` (`id`);
ALTER TABLE `operation_report` ADD CONSTRAINT `fk_operation_report_operation_id` FOREIGN KEY (`operation_id`) REFERENCES `operation` (`id`);
ALTER TABLE `plan_baseline` ADD CONSTRAINT `fk_plan_baseline_operation_id` FOREIGN KEY (`operation_id`) REFERENCES `operation` (`id`);
ALTER TABLE `predecessor` ADD CONSTRAINT `fk_predecessor_predecessor_id` FOREIGN KEY (`predecessor_id`) REFERENCES `operation` (`id`);
ALTER TABLE `predecessor` ADD CONSTRAINT `fk_predecessor_operation_id` FOREIGN KEY (`operation_id`) REFERENCES `operation` (`id`);
ALTER TABLE `schedule_force` ADD CONSTRAINT `fk_schedule_force_forced_user_id` FOREIGN KEY (`forced_user_id`) REFERENCES `app_user` (`id`);
ALTER TABLE `schedule_force` ADD CONSTRAINT `fk_schedule_force_operation_id` FOREIGN KEY (`operation_id`) REFERENCES `operation` (`id`);
ALTER TABLE `work_resource` ADD CONSTRAINT `fk_work_resource_department_id` FOREIGN KEY (`department_id`) REFERENCES `department` (`id`);
ALTER TABLE `work_resource` ADD CONSTRAINT `fk_work_resource_responsible_user_id` FOREIGN KEY (`responsible_user_id`) REFERENCES `app_user` (`id`);
CREATE INDEX `sessions_user_id_index` ON `sessions` (`user_id`);
CREATE INDEX `sessions_last_activity_index` ON `sessions` (`last_activity`);
CREATE INDEX `operation_status_index` ON `operation` (`status`);
CREATE INDEX `operation_assigned_user_id_index` ON `operation` (`assigned_user_id`);
CREATE INDEX `operation_project_id_index` ON `operation` (`project_id`);
CREATE UNIQUE INDEX `predecessor_operation_id_predecessor_id_unique` ON `predecessor` (`operation_id`, `predecessor_id`);
CREATE INDEX `audit_event_entity_type_entity_id_index` ON `audit_event` (`entity_type`, `entity_id`);
CREATE INDEX `audit_event_occurred_at_index` ON `audit_event` (`occurred_at`);
CREATE INDEX `notification_recipient_id_created_at_index` ON `notification` (`recipient_id`, `created_at`);
CREATE INDEX `operation_report_operation_id_index` ON `operation_report` (`operation_id`);
CREATE INDEX `assignment_proposal_status_index` ON `assignment_proposal` (`status`);
CREATE INDEX `license_event_occurred_at_index` ON `license_event` (`occurred_at`);
CREATE UNIQUE INDEX `department_member_department_id_user_id_unique` ON `department_member` (`department_id`, `user_id`);
CREATE UNIQUE INDEX `department_role_permission_department_id_role_permission_unique` ON `department_role_permission` (`department_id`, `role`, `permission`);
CREATE INDEX `department_suggestion_status_created_at_index` ON `department_suggestion` (`status`, `created_at`);
CREATE UNIQUE INDEX `department_code_unique` ON `department` (`code`);
CREATE INDEX `chat_conversation_user_id_updated_at_index` ON `chat_conversation` (`user_id`, `updated_at`);
CREATE INDEX `ai_runs_user_id_created_at_index` ON `ai_runs` (`user_id`, `created_at`);
CREATE INDEX `ai_pending_actions_user_id_status_index` ON `ai_pending_actions` (`user_id`, `status`);
CREATE INDEX `agent_tasks_agent_id_created_at_index` ON `agent_tasks` (`agent_id`, `created_at`);
CREATE UNIQUE INDEX `ai_action_policies_kind_action_unique` ON `ai_action_policies` (`kind`, `action`);
CREATE INDEX `chat_message_request_id_index` ON `chat_message` (`request_id`);
CREATE INDEX `chat_upload_user_id_index` ON `chat_upload` (`user_id`);
DELETE FROM `migrations`;
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1, '2026_01_01_000001_create_aiso_schema', 1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2, '2026_10_12_000001_create_company_license_tables', 2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3, '2026_10_13_000001_add_planning_goal_to_system_setting', 3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4, '2026_10_14_000001_add_sort_order_to_work_resource', 4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5, '2026_10_15_000001_add_llm_settings_to_system_setting', 5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6, '2026_10_16_000001_create_departments', 6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7, '2026_10_17_000001_add_hr_code_and_panel_view', 7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8, '2026_10_18_000001_add_production_code', 8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9, '2026_10_19_000001_create_chat', 9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10, '2026_10_20_000001_create_ai_agent_tables', 10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11, '2026_10_21_000001_create_agents', 11);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12, '2026_10_22_000001_add_assistant_approvals', 12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13, '2026_10_23_000001_add_choices_to_chat_message', 13);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14, '2026_10_24_000001_add_bale_token_to_system_setting', 14);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15, '2026_10_25_000001_create_app_roles', 15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17, '2026_10_24_000001_add_dialogue_state', 16);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18, '2026_10_25_000001_create_chat_upload', 17);
SET FOREIGN_KEY_CHECKS=1;
