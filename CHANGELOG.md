# Changelog

## 2026-11-12

fibes can now pull customers from Hub ERP (ERPNext). The default run is a dry run that only prints counts. Writing customers into fibes stays off until `FIBES_ERPNEXT_CRM_APPLY=true` is set and the command is run with `--apply`. Customers are matched by their ERPNext name, and existing fibes records are never deleted. Customer group, territory, contacts, and addresses are mapped. Admins can see recent run counts on Settings → Integrations. This pull request supersedes the earlier customer-sync work in pull requests 14 and 15, and it does not include the invoice pull.

Pull request: https://github.com/SamFIbes/fibes-crm/pull/44

## 2026-11-08

The sign-in page has a calmer animated background and a clearer form card. Email, password, remember-me, forgot-password, and sign-in itself work the same way as before.

Pull request: https://github.com/SamFIbes/fibes-crm/pull/11
