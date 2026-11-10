# Changelog

## 2026-11-10

Customer, report, commission and purchasing CSV exports now make sure a name or value starting with = + - or @ shows as plain text in Excel instead of running as a formula.

Pull request: https://github.com/SamFIbes/fibes-crm/pull/26

Sending a stock request to ERPNext can no longer create the same Material Request twice. If a first try reached ERPNext but the answer got lost, the retry now finds and reuses that document instead of making a new one.

Pull request: https://github.com/SamFIbes/fibes-crm/pull/25

Every pull request into updates or main now gets automatic checks: the backend tests, a code-style check, the TypeScript check, the frontend tests and a production build.

Pull request: https://github.com/SamFIbes/fibes-crm/pull/24

The Files page now only shows files on customers, tickets, jobs, quotes and contracts you are allowed to see. Downloading or deleting someone else's file is refused. Files that aren't attached to a record are unchanged.

Pull request: https://github.com/SamFIbes/fibes-crm/pull/21

Guessing sign-in codes is now much harder. After too many wrong codes, from any device or network, the account is paused for 15 minutes and the owner is emailed. A correct password now waits at most 5 minutes for its code.

Pull request: https://github.com/SamFIbes/fibes-crm/pull/23

A code from your authenticator app now works only once. Using the same code a second time, for example by someone who saw it, is refused.

Pull request: https://github.com/SamFIbes/fibes-crm/pull/22

Changing or resetting a password now signs you out on every other device and cancels old "remember me" sign-ins. The device you changed it on stays signed in.

Pull request: https://github.com/SamFIbes/fibes-crm/pull/20

Switching a user off now signs them out straight away, including any "remember me" sign-in. Before, a switched-off user could keep using an open session.

Pull request: https://github.com/SamFIbes/fibes-crm/pull/19

The Meta and WhatsApp webhook check pages no longer crash when someone sends a malformed address. They now answer with a normal refusal instead of a server error.

Pull request: https://github.com/SamFIbes/fibes-crm/pull/18

## 2026-11-08

The sign-in page has a calmer animated background and a clearer form card. Email, password, remember-me, forgot-password, and sign-in itself work the same way as before.

Pull request: https://github.com/SamFIbes/fibes-crm/pull/11
