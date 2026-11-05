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

## The others

| System | What works now | What stays off |
| --- | --- | --- |
| Krypton Radius | CSV import when `krypton_import` is on. `KryptonRadiusClient` is the same mock that only logs suspend and reactivate | `FIBES_RADIUS` and `FIBES_RADIUS_API_KEY` |
| Whish | A payment reference typed by hand. `WhishClient` only logs a charge | `FIBES_WHISH` and `FIBES_WHISH_API_KEY` |
| Grandstream | Signed screen-pop when `FIBES_TELEPHONY_SECRET` is set. See `docs/TELEPHONY.md` | The secret, until the PBX is connected |
| WhatsApp Business | `wa.me` links when the customer has agreed | `FIBES_WHATSAPP` and `FIBES_WHATSAPP_TOKEN` |

Test connection on those four does not make a live call.
