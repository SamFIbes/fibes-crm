# Grandstream screen-pop

FIBES can open the right customer when a call arrives. The phone system is optional. The call screen works by typing a number, with no PBX installed.

## What FIBES expects

A Grandstream UCM can send an HTTP request when a call rings an extension.

- Method: `POST`
- Address: `https://your-fibes-host/api/telephony/incoming`
- Header `Content-Type: application/json`
- Header `X-Fibes-Timestamp`: the current Unix time, in seconds
- Header `X-Fibes-Signature`: HMAC-SHA256 of `{timestamp}.{raw body}`, using the shared secret in `FIBES_TELEPHONY_SECRET`, as a hex string
- Body:

```json
{
  "caller": "03123456",
  "extension": "101",
  "direction": "inbound",
  "call_id": "optional-id-from-the-pbx"
}
```

`caller` is the number the customer is calling from. `extension` is the agent's Grandstream extension. That extension is saved on the user, on the call screen or on the user form.

FIBES answers with whether a customer matched, and a path such as `/calls?phone=+9613123456`. It also keeps that number for two minutes for the logged-in person on that extension. Opening the call screen (or pressing F2) shows that customer.

Requests older than five minutes, or signed with the wrong secret, are refused. If `FIBES_TELEPHONY_SECRET` is empty, the address answers that the phone system is not configured, so nothing accepts an unsigned call.

## On the UCM

1. Give each agent an extension and type the same extension on their FIBES user.
2. Set `FIBES_TELEPHONY_SECRET` to a long random value, and put the same value in the UCM's HTTP notification or CRM integration.
3. Point the UCM's call-event URL at `/api/telephony/incoming`.
4. Send the caller's number as `caller` and the ringing extension as `extension`.
5. Place a test call. The agent presses F2, or already has the call screen open, and the customer card appears.

Click-to-call through the Grandstream API is not turned on. WhatsApp is a `wa.me` link that opens the normal WhatsApp app, and the agent logs that conversation by hand.
