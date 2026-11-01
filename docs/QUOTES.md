# Quotes and contracts

A sales person builds a quote from the catalog, in English or Arabic, in dollars or Lebanese pounds. The pound amount uses the day’s exchange rate and is rounded to the nearest 1,000. The rate is stored on the quote.

Discount limits already live with the catalog. A sales agent can give up to 5% or $5 on a line (and 5% or $10 on the quote). A sales manager can give up to 15% or $25 on a line (and 15% or $50 on the quote). Crossing either ceiling, a custom price, or a free installation or piece of equipment waits in the approval inbox. A super admin has no limit row and is not capped. Nobody can print until the quote is approved, or until it never needed approval.

An approved or sent quote is not edited in place. A change makes version 2 and leaves the previous version as it was. Sending makes a signed link for the customer, an email, or a WhatsApp `wa.me` link. A tampered or expired link is refused. A quote past its valid date cannot be accepted.

A contract is generated from the accepted quote. The customer types their name, draws a signature, ticks consent, and enters a code. The code is sent through the log SMS driver and kept in cache so a test can read it. It is not returned on the page. The signed document, the name, the time, the IP address, and a SHA-256 fingerprint are stored. Changing the template afterwards does not change that fingerprint. A scanned signature can be uploaded from the office instead.

Winning a deal now needs that accepted quote and signed contract. The older path is still there: a sales manager can ask to sign at install, and management approves it in the inbox.

Plan prices, 11% VAT, a 12 month term, a zero early-termination fee, and the fallback rate of 89,500 are placeholders until Sam confirms them. Invoice, receipt, statement, and the other document templates are seeded as stubs for later billing steps.

Printing uses the `html` driver unless `FIBES_PDF_DRIVER=chromium` and `FIBES_CHROMIUM_BIN` point at Chrome. There is no separate PDF package.
