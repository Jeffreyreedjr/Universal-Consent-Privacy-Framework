# Privacy preference enforcement (GPC + Do Not Sell + optional central API)

This document describes how UCPF enforces sale/share and tracking opt-outs **on the server**, without building a cross-site tracking network.

**Not legal advice.** Technical enforcement only.

## What “global” means

| Scope | Mechanism |
|-------|-----------|
| Anonymous cross-site | Browser **Global Privacy Control** (`Sec-GPC: 1`) — each site detects independently |
| This website | First-party `ucpf_dns` cookie + consent withdraw after Do Not Sell form |
| Same business / controller | Optional **Privacy Preference API** + `privacy_controller_id` (agency-hosted; off by default) |
| Unrelated client businesses | Separate controller IDs — never merge into one consumer graph |

UCPF does **not** use fingerprinting, shared third-party cookies, or cookie sync.

## Request resolution order

1. Is `Sec-GPC: 1` (or Nginx `UCPF_GPC=1`) present?
2. Is there a valid local `ucpf_dns` opt-out cookie?
3. Is the visitor logged in with a central preference (optional API)?
4. What does consent cookie allow?
5. Fail-closed marketing if API configured and unreachable (`privacy_fail_closed`)

Authoritative state: `Privacy_State::get_state()` / `GET /ucpf/v1/privacy-state` / `ucpfConfig.privacy` in JS.

```php
$privacy = ucpf_get_privacy_state();
// necessary/security stay true
// marketing/analytics/functional may be false under GPC or DNS
```

`Consent_Manager::has_consent()` and Google Consent Mode updates **deny** optional categories when privacy state blocks them — even after Accept All.

## GPC (automatic)

Enable **Respect Do Not Track / GPC** in the wizard (default on).

Advanced → **When GPC is present**:

- `nonessential` (default): block analytics, marketing, embeds, preferences  
- `sale_share`: block marketing / sale / share / targeted advertising only  

### Nginx (optional early signal)

```nginx
map $http_sec_gpc $ucpf_gpc {
    default 0;
    "1"     1;
}
# inside PHP location:
fastcgi_param UCPF_GPC $ucpf_gpc;
```

PHP also reads `HTTP_SEC_GPC` directly.

## Do Not Sell form

`[ucpf_do_not_sell_form]` now:

- Scope: this site / controller-wide / selected businesses  
- Optional **Global Privacy Mode** (block all nonessential on this site)  
- Sets first-party `ucpf_dns`, withdraws optional consent, runs `Vendor_Suppression::dispatch()`  
- Stores audit meta: HMAC identity, policy version, vendor status  

Email is stored as **HMAC** (`Privacy_Identity::hmac_email`), not plain text in the lookup key. Override key with `UCPF_PRIVACY_HMAC_KEY` in `wp-config.php`.

## Optional central Privacy Preference API

Leave **Privacy Preference API URL** blank for OSS local-only.

When set (agency):

- Logged-in users: fetch signed JSON deny-list, cache 15–60 minutes  
- `POST /ucpf/v1/privacy-revoke-cache` with `{ "subject": "<hmac>" }` to purge  
- Signature filter: `ucpf_verify_privacy_policy_signature`  
- Never accepts remote executable code  

Example policy response:

```json
{
  "controller_id": "acme-media",
  "subject": "hmac…",
  "deny": ["sale", "sharing", "targeted_advertising"],
  "policy_version": 4,
  "issued_at": 1784820000,
  "expires_at": 1784823600,
  "signature": "…"
}
```

## Vendor suppression hooks

```php
add_filter( 'ucpf_vendor_suppress', function( $result, $vendor, $record ) {
    // $vendor: google_ads|meta_ads|email_crm|server_gtm|data_export
    // return 'completed' | 'pending' | 'awaiting_review' | 'skipped'
    return $result;
}, 10, 3 );

add_action( 'ucpf_privacy_opt_out', function( $record, $status ) {
    // Fan-out complete
}, 10, 2 );
```

Wire these to CRM / ads / ESP connectors. Website script blocking alone does not stop server-side audience uploads.

**Vendor suppress queue:** REST `GET/DELETE /ucpf/v1/vendor-suppress-queue`, `POST /ucpf/v1/vendor-suppress-queue/{index}` for connector jobs. Mark complete only after confirming suppression in the vendor console/API.

**Scanner (1.4.1+):** `dns_opt_out` session + `still_loaded_after_dns` / `still_loaded_after_gpc` fail findings. Cookie Scanner → unknown host suggestions → site-local catalog (feeds network gate extras).

## Helpers

- `ucpf_get_privacy_state()`
- `ucpf_gpc_signal_present()`
- `ucpf_privacy_hmac_email( $email )`

## Non-goals

- Guaranteed legal compliance  
- Silent phone-home  
- Cross-domain visitor IDs  
- Auto-merging unrelated client controllers  
