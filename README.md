# NiceYou SoftOne ERP Connector (`niceyous1erp`)

Port of the NiceYou PrestaShop `erpintegrationsoft` business model onto the
NagaCommerce addon architecture. **Direction is reversed compared to the
generic `addons/softone` addon**: here the **eshop owns the catalog** —
products are pushed eshop → ERP, and only WEB-FIFO purchase (cost) prices
flow back ERP → eshop.

## Sync flows

| Flow | Direction | Trigger | Mechanism |
|------|-----------|---------|-----------|
| Product push | eshop → ERP | cron (10 min) / "Push Products" button | Changed products (from `lastProductPush` watermark) queued into `addon_niceyous1erp_transactions` (TODO/DONE/ERROR), drained as raw `s1services` `ITEM` setData calls. `CODE = <CodePrefix><sku>`, EAN dedup against ERP `ITEM.CODE1`, cover image attached via `ITEDOCDATA` (SOSOURCE 51). |
| Order push | eshop → ERP | order events (see below) | Customer upserted (local `customer_map` + ERP email→phone dedup), then a `SALDOC` is created/updated. Receipt series 6003 vs invoice series 6004 depending on the order's invoice request. Shipping (`104`) and COD fee (`105`) as EXPANAL expense rows, net of `ExpenseVatPercent`. `FINCODE = orderid`. |
| WEB-FIFO | ERP → eshop | cron / "WEB-FIFO Sync" button | ERP's `WEB-FIFO` browser list staged into `addon_niceyous1erp_webfifo`, then applied to `products.prodcostprice` via the product map. |
| Bootstrap | one-time | orange buttons on addon home | Product map by EAN/barcode against `WSItems`; category map by exact name against `ITECATEGORY`. |

## First-time setup (in this order)

1. **Connection tab** — fill `BaseUrl`, `username`, `password`, `appId`.
   Note: a non-empty `BaseUrl` enables the addon cron (every 10 min), but
   nothing syncs until the checkboxes in step 5 are on.

2. **Open the addon page once** (Products menu → NiceYou SoftOne ERP).
   First visit runs the installation and creates the 9
   `addon_niceyous1erp_*` tables.

3. **Finish the Connection tab — association grids** (order push depends
   on them):
   - *Payment associations*: checkout module → SoftOne payment code
     (unmapped → `DefaultPaymentCode`, default 1000).
   - *Shipping associations*: shipping module → carrier code
     (unmapped → `DefaultCarrierCode`, default 1).
   - *VAT class associations*: eshop tax class → VAT code in
     `code|label` form (default `1410|ΦΠΑ 24%`).

   Also here: **`lastProductPush`** is the product-push watermark
   (unix timestamp). **Leave empty for a full first push** (all visible,
   priced products queue, 500 per run). Set it to "now" if the existing
   catalog must NOT be pushed and only future edits should queue.

4. **ERP Codes tab** — defaults come from NiceYou's install (series
   6003/6004, expense codes 104/105, COD fee 2.90, prefix `WEB`, default
   ERP category 999...). Verify against the live SoftOne setup before the
   first run. Same for the browser-list column indexes: constants in
   `library/mappings.class.php` (WSItems: code=2 / title=3 / ean=4;
   ITECATEGORY: id=2 / title=4) and `library/webfifo.class.php`
   (WEB-FIFO: mtrl=2 / name=4 / price=5) are specific to NiceYou's list
   definitions.

5. **Run the bootstraps** (orange buttons, BEFORE enabling any sync, so
   existing ERP items are adopted instead of duplicated):
   - *Bootstrap Products* — pairs ERP items to products/variations by
     EAN/barcode into `addon_niceyous1erp_product_map`. Rows without EAN
     on either side are skipped (push-time EAN lookup is the safety net).
   - *Bootstrap Categories* — pairs by exact name into
     `addon_niceyous1erp_category_map`; unmatched rows are stored with
     NULL categoryid and can be fixed manually; unmapped products fall to
     `DefaultErpCategory`.

6. **Enable the sync flags** (Settings tab):
   - *Push products* — eshop → ERP product flow.
   - *WEB-FIFO* — cost-price pull (only for mapped products — another
     reason bootstrap comes first).
   - *Sync on new order* — push at `NewOrderCompleted`.
   - *Sync order statuses* — comma-separated NC order-status IDs; the
     order is pushed when it ENTERS one of these statuses (NiceYou model:
     e.g. only "ready to ship"). **Empty = every status change pushes.**
   - *Cleanup days* — how long DONE transactions are kept.

7. **First run** — "Push Products" button starts the background worker
   immediately (detached via `xml.php` climode; the page shows a warning
   with an abort link while it runs). Large catalogs need several cycles:
   500 products queue per run, the watermark advances per batch and
   resumes cleanly.

## Monitoring

- **Transactions tab** — per-product push status (TODO/DONE/ERROR + message).
- **Order Reports tab** — per-order sync results (filterable by orderId).
- **Order Documents tab / order quick-view** — SALDOC receipt IDs.
- **System log** — errors under `addon/addon_niceyous1erp` (auth errors,
  push failures, WEB-FIFO failures).

## Order event behavior

| Event | Condition to push |
|-------|-------------------|
| `NewOrderCompleted` | `SyncOnNewOrder` enabled; order not DECLINED/INCOMPLETE |
| `OrderStatusChanged` | new status is in `SyncOrderStatuses` (empty = any) |
| `OrderUpdated` | only if the ERP already has a receipt for the order (a plain edit must not create a document) |
| `ShipmentCreated` | only if the ERP already has a receipt for the order |
| Manual (order button) | always, by order token |

## Code layout

- `addon.niceyous1erp.php` — addon shell: settings tabs, routes, events,
  cron, association tables, admin views.
- `library/api.class.php` — `s1services` client (login/setData/browser lists).
- `library/payloads.class.php` — **pure static business rules** (ITEM /
  CUSTOMER / SALDOC payload building, config merge). Tested in
  `tests/library/Niceyous1erp/Niceyous1erpPayloadsTest.php` — keep them pure.
- `library/products.class.php` — queue + drain product pushes.
- `library/orders.class.php` — customer upsert + SALDOC handling.
- `library/webfifo.class.php` — cost-price staging/apply.
- `library/mappings.class.php` — one-time bootstraps.

Library classes follow the `ADDON_NICEYOUS1ERP_*` autoloader convention
(`addons/<dir>/library/<lastsegment>.class.php`).

## Why this exists (vs `addons/softone`)

NiceYou's SoftOne install exposes only stock `s1services` (no custom
`js/api.v1` endpoints the generic softone addon expects), and their
business flows run the opposite direction (eshop is the catalog master).
