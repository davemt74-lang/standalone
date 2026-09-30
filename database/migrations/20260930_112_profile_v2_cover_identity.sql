-- Phase 79.1 — Unified Public Profile Shell & Cover Identity
ALTER TABLE users
  ADD COLUMN profile_cover_image_url VARCHAR(500) NULL AFTER profile_image_url;
