# How field staff reach the CRM

Sam chooses one of these. The repository does not open either of them.

## Public name, TLS, port 443 only

- A hostname such as the company domain, with a certificate.
- The firewall allows 443 and, if you still need it, 80 only to redirect to HTTPS.
- SSH stays on the office or the VPN, not on the public internet.
- fail2ban or the host firewall watches repeated sign-in failures. The app already pauses a password after 10 failures for 15 minutes.
- `APP_URL` is `https://…` and `SESSION_SECURE_COOKIE=true`.

## WireGuard

- Field phones and laptops join a VPN.
- The CRM listens only on the VPN address.
- No public web port is required.
- Staff who are not on the VPN cannot open the site, including the sales-floor screen.

Write the choice, the hostname, and who holds the VPN keys in the go-live notes. Do not put keys in git.
