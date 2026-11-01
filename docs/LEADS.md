# Leads

New leads from a call, WhatsApp, a walk-in, a field agent, or the website land in the shared call-center queue. Sales people who type a lead themselves keep it. A qualified lead moves to the sales queue. The customer record itself is created in the next step.

The list refreshes every 30 seconds. Press N to add a lead. Press C to claim the first free one. Someone who already owns it cannot be claimed again.

Islamic public holidays are listed in Settings under Holidays, marked “to confirm” until an admin accepts the official date. The lead clock skips every holiday on that list. Support stays 24/7 apart from those days.

## Website form

The form on the public site does not call FIBES directly. Your site receives the form, then signs the JSON body and posts it.

`POST /api/v1/hooks/web-form`

Header `X-Fibes-Signature` is HMAC-SHA256 of the raw body, using `FIBES_WEB_FORM_SECRET`. A filled `company_website` field is ignored (it is a honeypot). If `FIBES_TURNSTILE_SECRET` is set, send `turnstile_token` as well. Repeat a successful post with the same `Idempotency-Key` to get the same lead back.

```html
<form method="post" action="https://example.com/lead">
  <input name="name" required>
  <input name="phone" required placeholder="03 123 456">
  <input name="email" type="email">
  <input name="service" value="ftth">
  <input name="company_website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
  <button type="submit">Send</button>
</form>
```

```bash
BODY='{"name":"Rami","phone":"03 123 456","service":"ftth","utm":{"utm_source":"google","utm_medium":"cpc","utm_campaign":"fiber"}}'
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$FIBES_WEB_FORM_SECRET" | awk '{print $2}')
curl -X POST https://crm.example/api/v1/hooks/web-form \
  -H "Content-Type: application/json" \
  -H "X-Fibes-Signature: $SIG" \
  -H "Idempotency-Key: form-1" \
  --data "$BODY"
```

## Meta Lead Ads

Set `FIBES_META_VERIFY_TOKEN` and `FIBES_META_APP_SECRET`. The callback URL is `/api/v1/hooks/meta`. A campaign whose external id matches the ad campaign receives the lead. You can also upload a CSV with columns `name,phone,email` from the lead page once a campaign exists.
