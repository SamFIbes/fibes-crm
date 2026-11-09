# Changelog

## 2026-11-09

Added a read-only ERPNext Sales Invoice and Payment Entry pull into additive mirror tables (`erp_sales_invoices`, `erp_payment_entries`), linked to customers by `erp_name`. Dry-run stays the default; writes need `FIBES_ERPNEXT_CRM_APPLY=true`. Stacks on the live Customer pull.

Pull request: https://github.com/SamFIbes/fibes-crm/pull/PR_NUMBER_PLACEHOLDER

## 2026-11-09

Added a live, read-only ERPNext Customer pull (with linked Contact and Address) on `erpnext:crm-sync`. Dry-run stays the default and prints counts only. Database writes stay blocked unless `FIBES_ERPNEXT_CRM_APPLY=true`. A `--live-smoke` check reports Hub counts only. Stacks on the dry-run mapping from pull request 14.

Pull request: https://github.com/SamFIbes/fibes-crm/pull/15

Added an ERPNext CRM mapping table (`erp_links`) and a nullable `customers.erp_name` column, plus a dry-run sync command that only reads synthetic fixtures for customers, leads, quotations, and sales invoices. No live Hub data is pulled and no fibes customer, lead, quote, or invoice rows are written. Hub credentials can use `ERPNEXT_*` for a dedicated API user (falling back to `FIBES_ERPNEXT_*`).

Pull request: https://github.com/SamFIbes/fibes-crm/pull/14

## 2026-11-08

The sign-in page has a calmer animated background and a clearer form card. Email, password, remember-me, forgot-password, and sign-in itself work the same way as before.

Pull request: https://github.com/SamFIbes/fibes-crm/pull/11
