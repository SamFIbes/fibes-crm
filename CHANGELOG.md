# Changelog

## 2026-11-10

The dashboard now shows its "couldn't refresh" badge when the server has an error, instead of failing silently or flashing an error page. It also pauses refreshing while the tab is hidden or another page is loading, and screen readers no longer hear "updating" every few seconds.

Pull request: https://github.com/SamFIbes/fibes-crm/pull/32

## 2026-11-09

The home page has a new dashboard look. The first four figures are now large tiles (one dark, one white, one mint, one aqua) with an "Open report" link, and the rest sit below as small tiles. "Needs attention" now starts with a dark "Queue load" panel (total waiting plus how it splits across queues) and a bar chart of how many items wait in each queue; each bar opens that queue. The queue lists, the period picker and the section links were restyled. The page shows when it is refreshing, says so if a refresh fails (with a Retry button), and the "Choose tiles" save button shows "Saving…". It works in Arabic, dark mode, on phones and with reduced motion. The numbers and lists are the same ones the page already showed; nothing new is read from the database.

Pull request: https://github.com/SamFIbes/fibes-crm/pull/17

## 2026-11-08

The sign-in page has a calmer animated background and a clearer form card. Email, password, remember-me, forgot-password, and sign-in itself work the same way as before.

Pull request: https://github.com/SamFIbes/fibes-crm/pull/11
