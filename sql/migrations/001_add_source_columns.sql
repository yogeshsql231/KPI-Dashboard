-- Migration: add ETL source-tracking columns to order_shipments.
-- Safe to run once on an existing database created before the ETL feature.
-- (Fresh installs already get these via sql/schema.sql.)

ALTER TABLE order_shipments
    ADD COLUMN source_system VARCHAR(32)  NULL AFTER comments,
    ADD COLUMN source_key    VARCHAR(128) NULL AFTER source_system,
    ADD UNIQUE KEY uq_shipments_source (source_system, source_key);
