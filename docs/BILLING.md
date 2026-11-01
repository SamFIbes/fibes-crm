# Billing

Activation of an order opens one subscription and the first invoice. That invoice closes the order’s “first invoice recorded” check. A daily job, `billing:run` at 06:00 Beirut time, invoices every active subscription whose next invoice date has arrived. Running it twice the same day does not create a second copy.

Invoices are numbered `INV-YYYY-000001` with no gaps in a year. Credit notes use `CN-` so they do not take an invoice number. Money is stored as decimals. The customer balance is the last row of the ledger. A void posts a reversal and keeps the number. An invoice that already has a payment cannot be voided.

VAT, the fallback exchange rate, prepaid billing, the 14:00 expiry, grace days, proration, the hold limit, discount caps, approval amounts, and the starter prices are edited by an admin under Settings → Figures still to confirm. Each save is written to the audit log. The list is [to-confirm.md](to-confirm.md). The day’s dollar rate stays on Exchange rates. A printed invoice shows dollars and Lebanese pounds at the rate stored on that invoice.

A credit note above the finance limit, a write-off, and a refund wait in the approvals inbox. The person who asks cannot approve it. Call-center and support staff see the balance and not the line amounts.
