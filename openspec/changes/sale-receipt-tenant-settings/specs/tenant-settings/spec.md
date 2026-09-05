# Tenant Settings Specification

## Purpose

Business profile (name, RUT, logo) shown on receipts. Resolved via the authenticated user's `tenant_id` (no tenant id in path), admin-only. All fields are nullable; absence never yields 404.

## Requirements

### Requirement: Resolve tenant from authenticated user

All tenant settings endpoints MUST resolve the target tenant from the authenticated user's `tenant_id` and MUST require the `role:admin` middleware. Non-admin users MUST receive 403; unauthenticated requests MUST receive 401.

#### Scenario: Non-admin forbidden
- GIVEN an authenticated non-admin token
- WHEN any tenant settings endpoint is called
- THEN it returns HTTP 403

#### Scenario: Unauthenticated
- WHEN any tenant settings endpoint is called without a token
- THEN it returns HTTP 401

### Requirement: Get settings

`GET /api/v1/tenant/settings` MUST return `business_name`, `business_rut`, `business_logo_url` (all nullable). When no Tenant record exists, it MUST return HTTP 200 with null defaults, never 404.

#### Scenario: Existing profile
- GIVEN an admin whose tenant has a business profile
- WHEN `GET /tenant/settings` is called
- THEN it returns HTTP 200 with the stored values

#### Scenario: No profile yet
- GIVEN an admin with no tenant record
- WHEN `GET /tenant/settings` is called
- THEN it returns HTTP 200 with all fields null

### Requirement: Partial update settings

`PATCH /api/v1/tenant/settings` MUST partially update `business_name` and `business_rut`. `business_rut` MUST validate the Chilean check digit (reuse `ChileanRutRule`); `business_name` MUST be a string of at most 255 characters. It MUST return HTTP 200 with the updated profile. It MUST create the profile on first write if none exists.

#### Scenario: Partial update
- GIVEN an admin tenant
- WHEN `PATCH /tenant/settings` sets only `business_name`
- THEN it returns HTTP 200, updates `business_name`, and leaves `business_rut` unchanged

#### Scenario: Valid RUT
- GIVEN a valid Chilean RUT with correct check digit
- WHEN `PATCH /tenant/settings` sets `business_rut`
- THEN it returns HTTP 200 and stores the RUT

#### Scenario: Invalid RUT
- GIVEN a RUT with an incorrect check digit
- WHEN `PATCH /tenant/settings` sets `business_rut`
- THEN it returns HTTP 422

#### Scenario: Over-length string
- GIVEN a `business_name` longer than 255 characters
- WHEN `PATCH /tenant/settings` is called
- THEN it returns HTTP 422

### Requirement: Upload logo

`POST /api/v1/tenant/settings/logo` MUST accept a multipart field `logo`, generate an optimized thumbnail at most 200px on the longest side while preserving aspect ratio, encode as webp or jpeg using native GD, store it on the `public` disk, and return HTTP 200 with `{"business_logo_url": "..."}`. Uploading MUST replace any previous logo file and update `business_logo_url`. It MUST reject unsupported MIME types and oversized files with 422.

#### Scenario: Logo uploaded and optimized
- GIVEN an admin uploading a valid image
- WHEN `POST /tenant/settings/logo` is called
- THEN it returns HTTP 200 with `business_logo_url`
- AND the stored file is ≤200px, aspect preserved, webp/jpeg, on the public disk

#### Scenario: Invalid MIME
- GIVEN a non-image file
- WHEN the logo is uploaded
- THEN it returns HTTP 422

#### Scenario: File too large
- GIVEN an image exceeding the configured max size (default 2 MB)
- WHEN the logo is uploaded
- THEN it returns HTTP 422

#### Scenario: Replaces previous logo
- GIVEN an existing logo file
- WHEN a new logo is uploaded
- THEN the previous file is removed and `business_logo_url` points to the new file

## Constraints

- `PATCH` and `logo` upload are naturally idempotent (last-write-wins); no Idempotency-Key is required.
- `business_logo_url` is managed via the logo endpoint, not `PATCH`.
