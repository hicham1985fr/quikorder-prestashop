# Quick Order – Cash on Delivery (PrestaShop 1.7.x)

Module PrestaShop pour commandes rapides avec paiement à la livraison.

## Features

- Quick order form on product pages (Name, Phone, Address, Quantity)
- Automatic customer creation/identification by phone number
- Full product variant/combination support
- "Replace Add to Cart" mode (optional)
- Rich order confirmation page
- Customer change tracking (name/address changes logged)
- Dedicated customer group (ClientQO)

## Installation

1. Copy the `quickorder/` folder to `modules/` in your PrestaShop installation
2. Go to Back Office → Modules → Install "Quick Order – Cash on Delivery"
3. Configure: default order status, customer group, replace cart mode

## Requirements

- PrestaShop 1.7.x
- PHP 7.2+

## Configuration

| Setting | Description |
|---------|-------------|
| Default Order Status | Order state assigned to new quick orders |
| Customer Group | Group for quick order customers (auto-creates "ClientQO") |
| Replace Cart Mode | Hides Add to Cart, replaces catalog buttons with "Buy Now" links |

## Version

v1.4.1
