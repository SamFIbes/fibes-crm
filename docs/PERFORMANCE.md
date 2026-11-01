# Targets, commissions, and the sales floor

A sales manager, a call-center supervisor, or management sets a target for a person, a team, or a role. The figures are new sales, monthly price added, leads converted, calls, first-contact resolution, and money collected.

Winning a deal pays the percent of the first month’s price. Installing a line pays the fixed amount. A confirmed payment pays the percent of what was collected, which starts at zero until Sam turns it on. Crossing the target uses the higher percent. All three amounts are edited in Settings.

The commission rows are append-only. A clawback or a correction does not change an old row. It waits in the approvals inbox, and someone else posts a new row. A customer who leaves within 90 days of install opens that clawback. The payroll file is a CSV for people who can see every commission.

The floor screen is `/tv/sales?token=…`. It is read-only. The token cannot open any other page. It refreshes every 2 seconds. A normal win plays a short sound, a win at or above the big-deal price plays the jackpot, and passing a target plays the fanfare. Drop Sam’s own files on top of `public/tv/sounds/deal.wav`, `jackpot.wav`, and `fanfare.wav`.

Laravel Reverb does not install on this app. `laravel/reverb` 1.12 needs `guzzlehttp/psr7` ^2.6, and Laravel 13’s Guzzle 8 needs `guzzlehttp/psr7` ^3.2. Those cannot be installed together. The bell keeps its 30 second refresh, which is the fallback until a Reverb release accepts the newer Guzzle library. The floor screen does not wait for that.

The figures are listed in [to-confirm.md](to-confirm.md).
