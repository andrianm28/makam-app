# Design — Funeral Marketplace and Vendor Portal

## MVP order flow

```text
MarketplaceOrder
  -> MarketplaceOrderItem
  -> VendorOrder
  -> Vendor processing status
  -> Fulfillment schedule/evidence
  -> Transaction/payout reference
```

MVP checkout is one vendor per checkout. The data model preserves `vendor_orders` and allocation so multi-vendor can be added later.

## Panels

Use a dedicated Filament vendor panel with shared identity foundation and strict vendor query scope.

## Data

```text
marketplace_categories
vendors
vendor_users
products
product_variants
service_areas
vendor_availability
carts
cart_items
marketplace_orders
vendor_orders
vendor_order_items
fulfilment_evidence
vendor_transactions
vendor_payouts
```

## Security

- Vendor scope enforced in every query, action, export, and file access.
- Evidence files private.
- Price and bank/payout changes audited.
- Payout actions limited to finance roles.

## MVP decisions

- Single vendor per checkout.
- Customer cart conflict produces separate-checkout UX.
- Online payment uses shared payment rail; manual fallback is supported.
- Vendor transaction history is read-only from platform financial references.

## Post-MVP decisions

- Single vs multiple invoice for multi-vendor checkout.
- Split-payment capability.
- Partial cancellation/refund allocation.
- Cross-vendor promotion allocation.
