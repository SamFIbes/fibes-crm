# Integrations

Settings → Integrations is limited to the super admin. Everyone else is denied. The page stores addresses, the company, the Material Request purpose, and warehouse names. API keys, secrets, and tokens stay in the environment. The page only shows whether each variable is set.

Nothing calls a remote system until that system’s environment flag, its secret, and the desk switch are all on.

## Hub ERP

The live site is ERPNext at `https://erp.thehubholding.com`. The company on that desk is **Smart Network** (LBP, Lebanon). A read-only look on 5 Nov 2026 found Stock Entry, Purchase Receipt, Delivery Note, Material Request, Pick List, Item, Item Group, Warehouse, UOM, Brand, Serial and Batch, the stock reports, and Assets. About 598 items are there: Mikrotik routers, SFPs, UPS units, Cudy and TP-Link gear, and fiber materials.

The Material Request list shows Title, Status, Purpose, Transaction Date, Required By, and ID. Statuses seen on that list: Pending, Ordered, Received, Cancelled. Sample titles: Pending Mikrotik CRS520, Pending RG45 Connectors, Ordered Fiber Stripper, Received SFP 10 GB 20KM.

FIBES sends a **draft** Material Request (`docstatus` 0). It does not submit it, so it does not become Pending until someone does that in ERP. Purpose defaults to Purchase, which is the path those statuses belong to. The company sent with the draft is Smart Network unless an admin saves a different name.

| ERPNext | FIBES |
| --- | --- |
| Item `item_code` | `stock_items.erp_item_code`, or the SKU |
| Warehouse `name` | A stock location, through the warehouse mapping |
| Material Request `name` | `stock_requests.erp_name` |
| Title, Purpose, Transaction Date, Required By | The stock request’s item name, purpose, queue date, and required-by date |
| Asset | Read only. FIBES has no asset register yet |
| Expense Claim | Not on the stock desk. A FIBES expense is stored locally and named in the remarks |

Customer, base station (network node), field job, and expense are written in the item description. These custom fields are planned and are **not** sent until the DocType has them:

- `custom_fibes_customer_id`
- `custom_fibes_network_node_id`
- `custom_fibes_job_id`
- `custom_fibes_expense_id`
- `custom_fibes_request`

Outbound calls run on the queue. Retries use the webhook attempt count and pause from Settings → Figures still to confirm. Every attempt is written to `integration_attempts` and the audit log, without the API secret.

`POST /api/v1/hooks/erpnext` checks `X-Fibes-Timestamp` and `X-Fibes-Signature` (HMAC-SHA256 of `{timestamp}.{body}` with `FIBES_ERPNEXT_WEBHOOK_SECRET`). A valid call is stored and does not change stock. An empty secret answers 503.

On Stock, **Request from ERP** is disabled, with the reason, until the flag and the key exist. Issuing stock can record a customer, a base station, a field job, or an expense on the movement even while ERP writes stay off.



## CRM customer sync

`php artisan erpnext:crm-sync` pulls Hub ERP (ERPNext) customers into fibes. The default is a dry run that only prints counts (would create, would update, skip, conflicts, errors). It never writes customers, phones, or mapping rows. A run log is stored in `erp_sync_runs` and shown on Settings → Integrations, without names or emails.

Live writes need both `--apply` and `FIBES_ERPNEXT_CRM_APPLY=true`. Apply upserts by the ERPNext Customer name into `customers.erp_name` and `erp_links`. Customer group and territory are stored on the mapping row. Territory may set `area_id` only when that field is empty and exactly one existing area matches the territory name. Linked contacts and addresses are mapped on apply: extra phones are added when they are free, and building, floor, and landmark are filled only when empty. Existing fibes rows are never deleted. The command never writes back to ERPNext.

Credentials prefer `ERPNEXT_URL` / `ERPNEXT_API_KEY` / `ERPNEXT_API_SECRET`, and fall back to `FIBES_ERPNEXT_*`. `--live-smoke` reports Hub counts only. `--fixture=` maps synthetic fixtures for customers, leads, quotations, and sales invoices as a preview; it does not pull those other types live.

The additive tables are `erp_links` and `erp_sync_runs`. `customers.erp_name` is a nullable unique column used as the upsert key.

## The others

Each of these stays on the behaviour below until its environment flag, its secret, its base URL where one is required, and the desk switch are all on. Test connection makes a real safe ping only in that case. Otherwise it says the connection is not configured and does not call out.

| System | While it is off | When it is on |
| --- | --- | --- |
| Krypton Radius | CSV import when `krypton_import` is on. Suspend and reactivate only log and leave a NOC task | Create or update the Radius user from Customer 360, suspend and reactivate on the queue, and a read-only session check |
| Whish | A payment reference typed by hand | A checkout link stored on the invoice and a pending payment. The signed webhook marks that invoice paid |
| Grandstream | Signed screen-pop when `FIBES_TELEPHONY_SECRET` is set. Click-to-dial stays off. Abandoned calls stay an empty list | Click-to-dial through the UCM HTTP API. Abandoned calls appear only when the PBX pushes a signed event |
| WhatsApp Business | `wa.me` links when the customer has agreed | Approved template sends for a renewal, a ticket update, and a payment reminder, still only with consent |

Outbound writes are queued. Retries use the same webhook attempt count and pause as Hub ERP. Every attempt is written to `integration_attempts` and the audit log. API keys, tokens, the PBX password, and the Radius password are not stored in those rows.

### Krypton Radius

There is no public REST guide for the Smart Networks desk in this repo. The client assumes the paths below on `FIBES_RADIUS_URL`. A likely base address is `https://rad.smartnetworkslb.net`. Leave the URL empty until Sam has the vendor guide, then change the path variables if the real routes differ.

| Purpose | Method and path | Body |
| --- | --- | --- |
| Safe ping | `GET /api/v1/health` | Response `{ "ok": true }` |
| Create or update | `PUT /api/v1/users/{username}` | `username`, `password` (only when one is stored), `profile`, `static_ip`, `mac`, `nas`, `vlan`, `customer_id` |
| Suspend | `POST /api/v1/users/{username}/suspend` | Empty JSON |
| Reactivate | `POST /api/v1/users/{username}/reactivate` | Empty JSON |
| Session (read, not queued) | `GET /api/v1/users/{username}/session` | `{ "online", "status", "framed_ip", "session_started_at" }` |

Auth is `Authorization: Bearer` and the key in `FIBES_RADIUS_API_KEY`. `FIBES_RADIUS` must be true.

A failed suspend or reactivate, after the queue gives up, opens the same NOC task as today. Set `FIBES_RADIUS_ALSO_TASK=true` to open that task even when the live call succeeds. CSV import is unchanged.

### Whish

Public merchant docs are thin. The client assumes:

| Purpose | Method and path | Body |
| --- | --- | --- |
| Safe ping | `GET /api/v1/health` | Response `{ "ok": true }` |
| Checkout | `POST /api/v1/checkouts` | `amount` (decimal string), `currency` (`USD`), `reference`, `invoice_number`, `description`, `callback_url` |

The response must include `id` and an `https` `url`. Those are stored on the invoice and on a pending Whish payment. The hand-typed reference field is still on the payment form when live checkout is off.

`POST /api/v1/hooks/whish` uses `X-Fibes-Timestamp` and `X-Fibes-Signature` (HMAC-SHA256 of `{timestamp}.{body}` with `FIBES_WHISH_WEBHOOK_SECRET`). An empty secret answers 503. A `payment.succeeded` event with `provider_id`, `checkout_id`, `amount`, and `currency` confirms the matching payment and posts one ledger credit. The same `provider_id` does not post again. The amount must match the payment. The API key is not in the response.

### Grandstream

Screen-pop is unchanged. Click-to-dial and the safe ping use the UCM HTTP API, not AMI and not a CDR poll. See `docs/TELEPHONY.md`.

Abandoned calls are not invented and not polled. `POST /api/telephony/abandoned` with the same signature as screen-pop stores one row per `call_id`. The Integrations card shows that count.

### WhatsApp Business

Sends use the Meta Graph API:

- Safe ping: `GET https://graph.facebook.com/{version}/{phone-number-id}?fields=id` with `Authorization: Bearer {FIBES_WHATSAPP_TOKEN}`
- Template: `POST .../messages` with `type: template`

Template names default to `fibes_renewal_reminder`, `fibes_ticket_update`, and `fibes_payment_reminder`. Change them to the names approved in Meta. A customer without `whatsapp_consent` is not sent a template. `wa.me` remains the link in collection tasks.

`GET /api/v1/hooks/whatsapp` answers Meta’s verify challenge when `hub.mode=subscribe` and the token matches `FIBES_WHATSAPP_VERIFY_TOKEN`, or `FIBES_META_VERIFY_TOKEN` when the WhatsApp one is empty. An empty token or a mismatch answers 403. `POST` stores delivery statuses and inbound message ids. It does not reply. The POST answers 503 until `FIBES_WHATSAPP_APP_SECRET` (or `FIBES_META_APP_SECRET`) is set, and 401 when the `X-Hub-Signature-256` signature does not match.
