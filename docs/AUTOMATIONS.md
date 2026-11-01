# Automations and outbound webhooks

An admin builds a rule from a trigger, optional conditions, and an action. Saving a change stores a new version. Older runs keep the version they used. Every change is in the audit log.

A practice run looks back the number of days in Settings, which starts at 7, and changes nothing. A rule may trigger itself only as many times as the loop limit, which starts at 3. Each rule may run only so many times an hour. A pause switch stops every action at once, including webhooks.

The seeded rules start on: a lead clock warning, a lead clock breach, an urgent ticket with nobody assigned, a follow-up call 24 hours after a ticket is resolved, an at-risk customer, a renewal that is coming up, and low stock. The clocks, the health score, and the stock alert that already run on their own schedules stay where they are. These rules are the editable copies an admin can turn off.

A webhook is signed with HMAC-SHA256 over the timestamp and the body. The secret is stored encrypted and shown once. It is not written in the audit log. A failed delivery waits, then tries again, and is given up after the number of tries in Settings. WhatsApp is prepared only when the customer has agreed.

API keys are shown once and stored as a hash. The connections panel shows webhooks, email, the text-message driver, the PDF driver, and whether map tiles are configured.

The figures are listed in [to-confirm.md](to-confirm.md).
