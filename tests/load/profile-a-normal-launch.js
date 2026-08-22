import http from 'k6/http';
import { check } from 'k6';

/**
 * Profile A (normal launch) at CI-safe reduced scale — see
 * tests/load/README.md and this plan's Task 5 for why 10 VUs/30s rather
 * than the documented 50 VUs, and why this covers only cacheable,
 * unauthenticated GET routes (homepage, cemetery directory, FAQ index),
 * not "concurrent wizard saves" (Profile B) or authenticated admin
 * traffic.
 *
 * Route note: the cemetery directory's real route is `/cemeteries`
 * (routes/web.php, CemeteryDirectoryIndex) — NOT `/pemakaman`, which does
 * not exist in this codebase. Verified against routes/web.php before
 * writing this script; see tests/load/README.md and Task 5's report for
 * the correction.
 *
 * Gating vs. reporting, deliberately different: `http_req_duration` is
 * NOT declared as a threshold below, even though
 * docs/operations/performance-and-capacity.md §3's target table gives a
 * real p95<500ms figure for cached/public pages. This CI job serves the
 * app via `php artisan serve` — an explicitly non-production PHP CLI dev
 * server (4 workers), not php-fpm+nginx or Octane — so a duration result
 * from it is not evidence of production capacity either way (matches
 * performance-and-capacity.md §9's repeated point that this class of
 * host/setup isn't accepted as production-capacity evidence). Confirmed
 * live 22 Aug 2026: with connectivity fixed (plain-binary k6, not
 * grafana/k6-action), this scenario's real numbers were homepage
 * p95=537.18ms, cemetery_directory p95=1.68s, faq_index p95=1.55s — all
 * over 500ms against the dev server, which is a genuine, expected result
 * of the serving stack, not a tooling bug to chase away or a threshold to
 * quietly loosen until it happens to pass. k6 still measures and reports
 * every route's real p50/p90/p95/p99 in its summary output unconditionally
 * (see "TOTAL RESULTS") regardless of what's declared as a threshold here
 * — only what gates this CI job's exit code changes. What DOES gate CI is
 * BOTH `http_req_failed` and `checks`: `http_req_failed` alone treats any
 * 2xx/3xx as success (via k6's `expected_response` default), so a route
 * silently regressing to a redirect would not fail it even though the
 * `check()` calls below already assert `status === 200` — `checks` is
 * declared as a threshold too so that assertion is actually gating, not
 * just informational. This script's actual job (this plan's Task 5) is
 * proving the k6 tooling, dataset, and CI wiring genuinely connect and
 * complete end-to-end at a real (if reduced) scale — which 100% successful
 * connections against real 200 responses on all three routes IS proof of.
 * A real production-capacity certification against Profile A's full 50 VUs
 * on a production-representative stack is deferred to Phase 3, same as
 * Profiles B–D (see tests/load/README.md).
 */
export const options = {
    scenarios: {
        normal_launch_reduced: {
            executor: 'constant-vus',
            vus: 10,
            duration: '30s',
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'],
        checks: ['rate>0.99'],
    },
};

const BASE_URL = __ENV.K6_BASE_URL || 'http://127.0.0.1:8080';

export default function () {
    const homepage = http.get(`${BASE_URL}/`, { tags: { route: 'homepage' } });
    check(homepage, { 'homepage is 200': (r) => r.status === 200 });

    const directory = http.get(`${BASE_URL}/cemeteries`, { tags: { route: 'cemetery_directory' } });
    check(directory, { 'cemetery directory is 200': (r) => r.status === 200 });

    const faq = http.get(`${BASE_URL}/faq`, { tags: { route: 'faq_index' } });
    check(faq, { 'faq index is 200': (r) => r.status === 200 });
}
