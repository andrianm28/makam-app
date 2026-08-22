import http from 'k6/http';
import { check } from 'k6';

/**
 * Profile A (normal launch) at CI-safe reduced scale — see
 * tests/load/README.md and this plan's Task 5 for why 10 VUs/30s rather
 * than the documented 50 VUs, and why this covers only cacheable,
 * unauthenticated GET routes (homepage, cemetery directory, FAQ index),
 * not "concurrent wizard saves" (Profile B) or authenticated admin
 * traffic. Thresholds mirror docs/operations/performance-and-capacity.md
 * §3's real target table for the operations this script actually
 * exercises.
 *
 * Route note: the cemetery directory's real route is `/cemeteries`
 * (routes/web.php, CemeteryDirectoryIndex) — NOT `/pemakaman`, which does
 * not exist in this codebase. Verified against routes/web.php before
 * writing this script; see tests/load/README.md and Task 5's report for
 * the correction.
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
        'http_req_duration{route:homepage}': ['p(95)<500'],
        'http_req_duration{route:cemetery_directory}': ['p(95)<500'],
        'http_req_duration{route:faq_index}': ['p(95)<500'],
        http_req_failed: ['rate<0.01'],
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
