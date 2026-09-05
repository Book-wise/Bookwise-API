# Sale Receipt Specification

## Purpose

Backend-owned PDF receipt generation and email delivery for sales. The PDF is the single source of truth for View and Send. It MUST render from sale data plus a nullable-safe business header and MUST NEVER fail because the business profile is incomplete.

## Requirements

### Requirement: Generate PDF receipt

`GET /api/v1/sales/{sale}/receipt` MUST require a Sanctum Bearer token (Authorization header) with the `sales:read` scope and MUST return a valid PDF with `Content-Type: application/pdf` and `Content-Disposition: attachment`. The document MUST include the sale ID, sale date, client, item/service detail, total, and normalized payment method. The header (`business_name`, `business_rut`, `business_logo_url`) MUST be nullable-safe: missing values are omitted, never an error.

#### Scenario: Receipt returned for existing sale
- GIVEN an authenticated token with `sales:read` and an existing sale
- WHEN the client requests `GET /sales/{sale}/receipt`
- THEN it returns HTTP 200 with `application/pdf` and attachment disposition
- AND the PDF contains sale ID, date, client, items, total, and payment method

#### Scenario: Auth via header (Angular blob fetch)
- GIVEN an Angular client fetching a blob with a Bearer token in the Authorization header
- WHEN it requests the receipt
- THEN it returns HTTP 200 PDF (token accepted from header, no `window.open`)

#### Scenario: Incomplete business profile never breaks PDF
- GIVEN tenant settings where `business_name`, `business_rut`, `business_logo_url` are all null
- WHEN the receipt is requested
- THEN it returns HTTP 200 PDF with the missing header lines omitted

#### Scenario: Sale not found
- GIVEN a token with `sales:read`
- WHEN requesting a receipt for a non-existent or soft-deleted sale
- THEN it returns HTTP 404

#### Scenario: Unauthenticated
- WHEN requesting a receipt without a token
- THEN it returns HTTP 401

### Requirement: Email receipt

`POST /api/v1/sales/{sale}/receipt/send` MUST email the same PDF document to the sale's client and return HTTP 202 `{"sent": true}`. If the client has no email, it MUST return a clear error and MUST NOT attempt to send.

#### Scenario: Receipt emailed
- GIVEN a sale whose client has an email
- WHEN `POST /sales/{sale}/receipt/send` is called
- THEN it returns HTTP 202 `{"sent": true}`
- AND an email with the PDF attachment is sent

#### Scenario: Client has no email
- GIVEN a sale whose client has a null or empty email
- WHEN send is requested
- THEN it returns HTTP 422 with a clear error
- AND no email is sent

#### Scenario: Sale not found
- GIVEN a valid token
- WHEN sending for a non-existent or soft-deleted sale
- THEN it returns HTTP 404

## Constraints

- Receipt PDF is generated on demand; it is NOT persisted or archived.
- `send` is the only non-idempotent side effect (a retry MAY resend the email); no Idempotency-Key is required.
- Receipt is sales-only; bookings are out of scope.
