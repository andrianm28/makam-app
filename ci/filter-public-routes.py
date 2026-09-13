#!/usr/bin/env python3
"""Filter `php artisan route:list --json` output down to the "public
application route tree" documented in
docs/product/information-architecture.md §1 — used by
ci/verify-route-manifest.sh (API-04 audit finding, 07 Sep 2026) to diff
against docs/product/route-manifest.json.

Excludes: the admin/vendor Filament panels (§5 territory, not §1), Livewire/
Horizon/Pulse/debugbar/Sanctum/Ignition framework internals, health checks,
the framework heartbeat ("up"), internal signed-download endpoints, the
payment webhook (covered by docs/contracts/payment-webhook.md, not the IA
route tree), and unnamed routes (bare Closures and the legacy
RedirectController aliases such as /cemeteries and /memorial/{profileId},
which intentionally keep their pre-rename URLs working but are not
first-class pages in the documented tree).

Usage: filter-public-routes.py <route-list.json> > <filtered.json>
"""
from __future__ import annotations

import json
import sys

EXCLUDED_FIRST_SEGMENTS = (
    'admin', 'filament', 'horizon', 'livewire', 'pulse',
    '_debugbar', 'sanctum', '_ignition', 'vendor',
    'health', 'up', 'internal', 'api',
)


def filter_public(routes: list[dict]) -> list[dict]:
    out = []
    for r in routes:
        uri = r['uri']
        name = r.get('name') or ''
        first_segment = uri.split('/')[0]

        if first_segment in EXCLUDED_FIRST_SEGMENTS:
            continue
        if name.startswith(('filament.', 'horizon.', 'pulse.')) or 'livewire' in name:
            continue
        if name == '' or r.get('action') == 'Illuminate\\Routing\\RedirectController':
            continue

        out.append({'method': r['method'], 'uri': uri, 'name': name})

    out.sort(key=lambda x: x['name'])
    return out


def main() -> int:
    if len(sys.argv) != 2:
        print('usage: filter-public-routes.py <route-list.json>', file=sys.stderr)
        return 2

    with open(sys.argv[1]) as f:
        routes = json.load(f)

    json.dump(filter_public(routes), sys.stdout, indent=2)
    sys.stdout.write('\n')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
