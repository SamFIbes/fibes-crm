# Client onboarding board

A Kanban board at **/onboarding** (sidebar: Sales → Onboarding) that follows a new internet client from the first sales handoff until the service is active.

Code: `OnboardingController`, `OnboardingDesk`, `resources/js/Pages/Onboarding`, migration `2026_11_15_120000_create_onboarding_board` (new tables only). Access: anyone who can view leads or opportunities. People who can edit leads or opportunities can move cards, assign them, add notes, and set follow-ups. Sales managers and settings managers can change the stages and the stale-days setting.

## Hard rules

- The server never messages a client. WhatsApp and email buttons on a card only open the staff member's own app with a ready message.
- Existing leads, customers, opportunities and pipelines are not changed. The board stores its own cards and history.

## Cards

- Created automatically on every board load, and every ten minutes by `onboarding:sync`, from:
  - leads handed to sales (`handed_to_sales_at`) or marked qualified or converted, from the last `FIBES_ONBOARDING_BACKFILL_DAYS` days (default 30);
  - customers created from those converted leads (the same card is updated, not duplicated).
- Staff can also add a card with one click from a lead or a customer page, or add a manual card on the board.
- A card shows the client, area, source salesperson, assignee, days in the current stage, and the next task for that stage.

## Stages (defaults; they can be renamed later in Board settings)

| Stage | Next task |
|---|---|
| New lead from sales | Contact the client |
| Contacted | Book a site survey |
| Site survey | Complete the site survey |
| Quote sent | Follow up on the quote |
| Contract signed | Schedule the installation |
| Installation scheduled | Confirm the installation |
| Active | Confirm the service is active |

Active is the last stage. Cards there are never marked stale and never count toward the follow-up badge. A stage that still has cards cannot be removed.

Every move (drag and drop, or **Move to** for keyboard and phones) is saved in the card history with who and when.

## How the board loads

The board loads column by column. First `GET /onboarding/board/meta` returns the stages, the counts, and the filter options. Then each column loads its own cards from `GET /onboarding/board/columns/{stage}` (20 at a time, newest first, at most 3 columns at once). Each column has its own loading state, empty message, Retry, and Load more. Moving a card reloads only the two columns involved. Search, area, assignee, stale and “assigned to me” filters run in the database.

## Stale cards and follow-ups

A card with no activity for **N days** (default 5) is marked stale. Activity is a move, a note, or an assignment. Setting or snoozing a follow-up date does not count as activity, so it never hides a stale card.

- **Next follow-up** sets a date to come back to the client, or **Clear** removes it. **+1 day** and **+3 days** snooze from today (the server's date).
- The **Onboarding** item in the side menu shows a red count of cards that need follow-up: stale, or with a follow-up date that is today or earlier. A second number shows how many of those are assigned to you. The header button on the board uses the same counts and can filter the board to those cards. `GET /onboarding/attention` is read-only and refreshes every five minutes while the page is visible.

## Settings

- `FIBES_ONBOARDING_STALE_DAYS` (default 5). Board settings win if an admin changes the number there.
- `FIBES_ONBOARDING_BACKFILL_DAYS` (default 30).
