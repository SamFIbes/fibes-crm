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

## Click-to-dial

FIBES uses the Grandstream UCM HTTP API (JSON on port 8089), not Asterisk AMI and not a polled CDR list. AMI would hold a TCP session open. A CDR poll would guess at dispositions. The HTTP API can challenge, log in, originate, and log out in one queued job.

Set all of these, and turn the Grandstream desk switch on, before click-to-dial or its test will call the PBX:

| Name | Purpose |
| --- | --- |
| `FIBES_TELEPHONY_URL` | UCM address, for example `https://pbx.example:8089`. The client posts to `{url}/api` unless the URL already ends in `/api` |
| `FIBES_TELEPHONY_USER` | API user on the UCM |
| `FIBES_TELEPHONY_PASSWORD` | That user's password. It is never shown on the desk or written to the attempt log |
| `FIBES_TELEPHONY_API_PATH` | Default `/api` |
| `FIBES_TELEPHONY_DIAL_ACTION` | Default `dialOutbound`. Change this if the firmware uses another action name |

The assumed dialogue:

1. `challenge` with the API user. Test connection stops here. It does not place a call and it does not send the password.
2. `login` with `token = md5(challenge + password)`.
3. `dialOutbound` with `extension` (the agent's saved extension) and `outbound` (digits from the customer number).
4. `logout`.

Screen-pop does not need the URL, the API user, or the desk switch. An empty `FIBES_TELEPHONY_SECRET` still refuses every unsigned call.

## Abandoned calls

FIBES does not poll the UCM and does not invent a count. The PBX may push:

- Method: `POST`
- Address: `https://your-fibes-host/api/telephony/abandoned`
- The same `X-Fibes-Timestamp` and `X-Fibes-Signature` headers as screen-pop
- Body: `caller`, optional `extension`, optional `call_id`, optional `abandoned_at`

The same `call_id` is stored once. The Integrations card and the call screen list those rows. An empty list means the PBX has not pushed an event.
