-- Language of system-generated texts (notifications, assignment reasons, audit reasons, exports): 'fa' or 'en'.
ALTER TABLE system_setting ADD COLUMN language VARCHAR(5) DEFAULT 'en' NOT NULL;
