=== WooCommerce HubSpot Sync ===
Contributors: Masum Billah
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Syncs WooCommerce orders to HubSpot deals

== Description ==

**Architecture overview**

The plugin separates concerns into three layers:

1. **Order_Extractor** – reads WooCommerce/HPOS order data into a plain PHP array (DTO). Zero CRM knowledge.
2. **CRM_Adapter_Interface** – defines the contract any CRM must implement.
3. **HubSpot_Adapter** – the concrete HubSpot implementation (swappable).
4. **Sync_Manager** – orchestrates the workflow using only the interface.

To swap to a different CRM, implement `CRM_Adapter_Interface` and inject your adapter in `Plugin::__construct()`.

**Contact matching**
Uses `_shipping_email` order meta (HPOS-compatible) as the primary lookup key.
Contacts are *never* created — only looked up.

**Billing email**
- Empty → ignored
- Equals shipping email → ignored
- Different → stored as `billing_email` on the company record only. Never creates a second contact.

**Company logic**
- No companies are created via API.
- The contact's existing primary company is looked up and associated to the deal.
- Company properties updated: `box_size`, `permanent_box`, `internal_note`, `billing_email`.
- If the company has an owner, that owner is set on the deal.

**Deal name format**
Priority: shipping company → shipping name → billing name
`Müller AG - #1234` or `Dominic Müller - #1234`

**Deal stages**
Configurable map in Settings → WooCommerce → HubSpot Sync.
Cancelled = separate stage (not deletion).
Deleted WooCommerce orders → deal is permanently deleted in HubSpot.

**Order info field**
A single `order_items` text property on the deal includes:
Product Name, SKU, Quantity, Price, Order Total, Shipping Name, Language, Batch Number.

**Custom HubSpot properties required**
Create these in HubSpot → Properties → Deal:
- `wc_order_id` (single-line text) — used for idempotent upserts
- `order_items`  (multi-line text)

Create these on Contact/Company as needed:
- `billing_email`, `box_size`, `permanent_box`, `internal_note`

== Installation ==

1. Upload the `wc-hubspot-sync` folder to `/wp-content/plugins/`.
2. Activate through the Plugins menu.
3. Go to WooCommerce → HubSpot Sync and enter your HubSpot Private App token and pipeline ID.
4. Create the required custom properties in HubSpot (see Description).

== Changelog ==

= 1.0.0 =
* Initial release.
