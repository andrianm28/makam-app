<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public routes
|--------------------------------------------------------------------------
| The canonical route contract is docs/product/information-architecture.md §1.
| Routes are added as their feature specs are implemented; see
| docs/planning/sprint-plan.md §2.4 for which sprint owns which spec.
|
| The four MVP entry points (mvp-scope.md §1) are NOT yet implemented:
|   /pemesanan-makam   Sprint 4  public-booking-wizard
|   /marketplace       Sprint 4  funeral-marketplace-and-vendor-portal
|   /perpanjangan      Sprint 4  renewal-and-grave-registry
|   /faq               Sprint 4  public-faq
|
| Laravel's default welcome page was deliberately removed: it hardcodes colours
| and Tailwind arbitrary values, which the ci/verify-docs.sh token gates reject.
| Nothing should be served from `/` until public-home-and-navigation is built —
| AGENTS.md requires the homepage to present exactly four services in a fixed
| order, so a placeholder here would be a false product claim.
*/
