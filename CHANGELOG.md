# Changelog

## 2026-11-10

Sign-in now takes the same time whether or not an email has an account, so response timing no longer reveals who has an account.

Pull request: https://github.com/SamFIbes/fibes-crm/pull/39

When a Super Admin is viewing the CRM as another person, the Stop viewing button now works instead of showing an error. Signing out during view-as is now written to the audit log too.

Pull request: https://github.com/SamFIbes/fibes-crm/pull/38

The sales TV feed, the automations ping, export download links and the Meta/WhatsApp verify endpoints now have rate limits, so tokens and keys can't be guessed by brute force. A real TV screen keeps refreshing normally.

Pull request: https://github.com/SamFIbes/fibes-crm/pull/37

Links like fibes.io/build and fibes.io/storage/ no longer redirect to a broken http://www.fibes.io:8080 address. The server now keeps these redirects on https. This also adds the Railway build files to updates (same as the railway branch, plus this fix).

Pull request: https://github.com/SamFIbes/fibes-crm/pull/36

There is now a post-deploy checklist (docs/post-deploy-checklist.md) and a read-only check script (scripts/smoke-check.sh) that confirms fibes.io redirects to https, sends the security header, loads the sign-in page, reports healthy and turns away bad webhook requests. There is also a written plan for moving the live site from the railway branch to updates (docs/deploy-branch.md); nothing in Railway was changed.

Pull request: https://github.com/SamFIbes/fibes-crm/pull/35

The app no longer believes forwarded IP and https headers from just anyone. It only trusts Railway's proxy and local or private networks, so someone can't fake their address to get around sign-in limits. TRUSTED_PROXIES can change the list.

Pull request: https://github.com/SamFIbes/fibes-crm/pull/34

The production Docker image (docker-compose prod and staging) no longer re-runs the database seeder on every start. It seeds only when FIBES_RUN_SEED=true, the same rule as the Render and Railway start scripts.

Pull request: https://github.com/SamFIbes/fibes-crm/pull/33

The updates branch no longer re-runs the database seeder on every start, which could reset role permissions and the admin account. It now seeds only when FIBES_RUN_SEED=true, the same rule main already has. The automatic checks on GitHub now allow the sandbox these boot-script tests need.

Pull request: https://github.com/SamFIbes/fibes-crm/pull/31

Typing wrong passwords for someone's email now pauses sign-in only from the device or network doing it, so a stranger can't lock a staff member out. A large attack from many places still pauses the account, and the owner gets one warning email.

Pull request: https://github.com/SamFIbes/fibes-crm/pull/30

Website form posts that are retried or sent twice no longer create duplicate leads or fail the captcha check, and a copied request can't be replayed to add leads. The website can also sign a timestamp for stronger protection.

Pull request: https://github.com/SamFIbes/fibes-crm/pull/29

The background worker now reads jobs from the same queue the app sends them to, instead of always the database queue, so jobs can't get stuck unprocessed if the queue setting changes. Deploy config: check the Railway QUEUE_CONNECTION before merging.

Pull request: https://github.com/SamFIbes/fibes-crm/pull/28

People who can manage users but are not Super Admins can no longer give anyone Super Admin rights or change a Super Admin's account (email, password, roles).

Pull request: https://github.com/SamFIbes/fibes-crm/pull/27

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
