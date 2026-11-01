# Staging UAT

Run this for one week on staging, not on production. Staging uses the same images with `APP_ENV=staging` and anonymized data. `main` is what you build for staging. A production tag moves only after Sam says so.

Load-seed customers (`LD-`) are not production data. Do not run `LoadSeeder` when `APP_ENV=production`.

## Scenarios

| Role | What they do | Pass when |
| --- | --- | --- |
| Super admin | Sign in, change the temporary password, turn on an authenticator, open Settings | The authenticator is on and a figure save is in the audit log |
| Sales agent | Create a lead, open a deal, build a quote under the cap | The quote does not wait for approval |
| Sales manager | Open a quote over the agent’s cap | The quote waits in the inbox, and the manager cannot approve their own request |
| Call-center agent | Find a customer by phone and log a call | The call is on the customer |
| Call-center supervisor | Open the board and a quality score | They see the team, an agent sees only their own score |
| Finance | Record a payment and open the day’s rate | The ledger gains a row and the old rate stays |
| Operations manager | Put a job on the dispatch week | The job shows on the week |
| Technician | On a phone, open today, add a photo, sign, and take cash | The signature is stored and cash asked for the password again when the window has passed |
| NOC | Open an outage and an escalation | The ticket can move up a level |
| Warehouse | Receive stock | The count is a new ledger row |
| Auditor | Open customers and a report | Amounts and other people’s private fields stay hidden |
| Management | Open the home numbers | The links go to records they are allowed to see |

The sales floor is read-only and updates within a few seconds of a won deal. Reverb is not installed.

## Sign-off

| Department | Name | Date | Pass or hold | Note |
| --- | --- | --- | --- | --- |
| Sales | | | | |
| Call center | | | | |
| Finance | | | | |
| Operations | | | | |
| NOC | | | | |
| Warehouse | | | | |
| Management | | | | |

Hold means the department does not go live until the note is cleared.
