# Payments

A payment is numbered `RC-2026-000001`. The amount that hits the ledger is always in dollars. Lebanese pounds are converted at the day’s rate, and that rate is stored on the payment. The balance is still only the last ledger row.

Cash, card, OMT, and a cheque from Finance are confirmed at once. Whish and a bank transfer stay pending until Finance confirms the reference. There is no live Whish connection. Call-center staff can ask for a payment. They cannot confirm it.

The payment is spread over open invoices, oldest first, unless a person picks an invoice. Money left over stays as a credit and is applied when the next invoice is issued. Reversing a payment needs the approvals inbox. The reversal is a new ledger row. The payment row stays.

Office cash belongs to a desk session. Field cash belongs to the technician’s session, and only when Settings allows it. Cash asks for the password again. That unlock lasts for the number of minutes in Settings. Closing the session compares the count with what was expected. A difference above the limit waits for someone else.

A receipt is a branded page, with a WhatsApp link when the customer has agreed. A statement for a date range is built only from the ledger. The new figures are listed in [to-confirm.md](to-confirm.md).

The technician screen reuses the existing offline queue. The server keeps an idempotency key, so a queued collection is stored once.
