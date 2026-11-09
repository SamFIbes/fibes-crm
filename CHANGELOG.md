# Changelog

## 2026-11-09

Added an ERPNext CRM mapping table (`erp_links`) and a nullable `customers.erp_name` column, plus a dry-run sync command that only reads synthetic fixtures for customers, leads, quotations, and sales invoices. No live Hub data is pulled and no fibes customer, lead, quote, or invoice rows are written. Hub credentials can use `ERPNEXT_*` for a dedicated API user (falling back to `FIBES_ERPNEXT_*`).

Pull request: https://github.com/SamFIbes/fibes-crm/pull/14

## 2026-11-08

The sign-in page has a calmer animated background and a clearer form card. Email, password, remember-me, forgot-password, and sign-in itself work the same way as before.

Pull request: https://github.com/SamFIbes/fibes-crm/pull/11
