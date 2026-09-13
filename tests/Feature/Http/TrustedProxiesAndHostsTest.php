<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Finding SEC-N1 — the application trusted `X-Forwarded-Host` and
 * `X-Forwarded-For` from any client.
 *
 * `bootstrap/app.php` carried `trustProxies(at: '*')` with Laravel's default
 * trusted-header bitmask, and no `trustHosts(...)` anywhere. `at: '*'` makes
 * EVERY remote address a trusted proxy, and the default bitmask includes
 * `HEADER_X_FORWARDED_HOST`, so an attacker's `X-Forwarded-Host` became the
 * host the application generated absolute URLs from — including the
 * password-reset link, which `ForgotPasswordPage::sendLink()` sends
 * synchronously inside the web request via a NOT-queued
 * `Illuminate\Auth\Notifications\ResetPassword`.
 *
 * Why this file asserts on `ResetPassword::toMail()->actionUrl` rather than
 * on an abstract `$request->getHost()`: the host is only interesting because
 * of what is built from it. Constructing the real framework notification
 * INSIDE the request under test exercises the exact expression the real
 * email uses (`url(route('password.reset', [...], false))`, see
 * `routes/web.php`'s own note on why the `password.reset` route NAME is
 * load-bearing) at the exact moment the spoofed headers are bound. Asserting
 * after the response would re-generate the URL against whatever request
 * happened to still be bound in the container, which proves nothing.
 *
 * The probe route is registered bare rather than inside the `web` group on
 * purpose: `TrustProxies` and `TrustHosts` are GLOBAL middleware (see
 * `Illuminate\Foundation\Configuration\Middleware::getGlobalMiddleware()`),
 * so they run for this route anyway, while the `web` group would add
 * session, CSRF and the `public-guest` throttle — none of which this
 * behaviour depends on, all of which could make the test fail for unrelated
 * reasons.
 *
 * Requests are issued against an ABSOLUTE `http://` URL rather than a path.
 * `MakesHttpRequests::prepareUrlForRequest()` passes a path through `url()`,
 * which would stamp `localhost` into `HTTP_HOST` and silently discard a
 * `Host:` header — and `Symfony\Component\HttpFoundation\Request::create()`
 * lets the URI's host win over any `HTTP_HOST` server var. Plain `http://`
 * plus `X-Forwarded-Proto: https` is also the real wire shape: the host
 * nginx vhost terminates TLS and proxies cleartext to the container.
 */
final class TrustedProxiesAndHostsTest extends TestCase
{
    /**
     * The address the application container's nginx actually sees for
     * external traffic: the host's own gateway address on the
     * `makam-nonprod_egress` docker bridge. Verified on `yiemvm` from the
     * live container's nginx access log — see this task's report for the
     * raw evidence. Covered by the `172.16.0.0/12` entry in
     * `bootstrap/app.php`, not by an exact-address match, because docker
     * assigns this subnet from its default address pool and it renumbers if
     * the networks are recreated.
     */
    private const PROXY_IP = '172.19.0.1';

    /** A public address, standing in for a real visitor (RFC 5737 TEST-NET-2). */
    private const CLIENT_IP = '198.51.100.7';

    /** A public address an attacker would forge into `X-Forwarded-For` (RFC 5737 TEST-NET-3). */
    private const SPOOFED_IP = '203.0.113.9';

    private const EVIL_HOST = 'evil-attacker.example';

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/__sec_n1__/probe', function () {
            // Unsaved on purpose — `CanResetPassword::getEmailForPasswordReset()`
            // reads the attribute, nothing here touches the database, so this
            // test needs neither `RefreshDatabase` nor a live connection.
            $user = new User;
            $user->email = 'victim@example.com';

            return response()->json([
                'action_url' => (new ResetPassword('SAMPLE'))->toMail($user)->actionUrl,
                'ip' => request()->ip(),
                'host' => request()->getHost(),
                'secure' => request()->isSecure(),
            ]);
        });
    }

    public function test_a_spoofed_x_forwarded_host_does_not_reach_the_password_reset_link(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => self::PROXY_IP])
            ->get('http://makam.co.id/__sec_n1__/probe', [
                'X-Forwarded-Host' => self::EVIL_HOST,
                'X-Forwarded-For' => self::CLIENT_IP,
                'X-Forwarded-Proto' => 'https',
            ]);

        $response->assertOk();

        $actionUrl = (string) $response->json('action_url');

        $this->assertStringNotContainsString(
            self::EVIL_HOST,
            $actionUrl,
            'The password-reset link was built from the attacker-supplied X-Forwarded-Host.',
        );
        $this->assertStringStartsWith('https://makam.co.id/reset-password/SAMPLE', $actionUrl);
        $this->assertSame('makam.co.id', $response->json('host'));
    }

    public function test_a_spoofed_x_forwarded_for_from_an_untrusted_source_does_not_change_the_client_ip(): void
    {
        // REMOTE_ADDR is a public address here: a client talking to the
        // application from somewhere that is NOT the reverse proxy. Its
        // `X-Forwarded-For` must carry no weight at all.
        $response = $this->withServerVariables(['REMOTE_ADDR' => self::CLIENT_IP])
            ->get('http://makam.co.id/__sec_n1__/probe', [
                'X-Forwarded-For' => self::SPOOFED_IP,
            ]);

        $response->assertOk();

        $this->assertSame(
            self::CLIENT_IP,
            $response->json('ip'),
            'A client-supplied X-Forwarded-For changed Request::ip(), which dissolves every '
            .'IP-keyed throttle including the 5-per-minute limiter on password re-authentication.',
        );
    }

    public function test_a_legitimate_request_through_the_real_proxy_shape_still_resolves_correctly(): void
    {
        // The exact shape the live vhosts produce: TLS terminated at the host
        // nginx, cleartext to the container, `Host` preserved,
        // `X-Forwarded-For` APPENDED to (`$proxy_add_x_forwarded_for`), and
        // `X-Forwarded-Proto` set to the outer scheme.
        $response = $this->withServerVariables(['REMOTE_ADDR' => self::PROXY_IP])
            ->get('http://makam.co.id/__sec_n1__/probe', [
                'X-Forwarded-For' => self::CLIENT_IP,
                'X-Forwarded-Proto' => 'https',
            ]);

        $response->assertOk();

        $this->assertSame(self::CLIENT_IP, $response->json('ip'), 'The real visitor IP was lost.');
        $this->assertSame('makam.co.id', $response->json('host'));
        $this->assertTrue($response->json('secure'), 'HTTPS detection behind the proxy broke.');
        $this->assertStringStartsWith('https://makam.co.id/reset-password/SAMPLE', (string) $response->json('action_url'));
    }

    public function test_the_second_environment_still_resolves_its_own_host(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => self::PROXY_IP])
            ->get('http://dev.makam.co.id/__sec_n1__/probe', [
                'X-Forwarded-For' => self::CLIENT_IP,
                'X-Forwarded-Proto' => 'https',
            ]);

        $response->assertOk();
        $this->assertSame('dev.makam.co.id', $response->json('host'));
        $this->assertStringStartsWith('https://dev.makam.co.id/reset-password/SAMPLE', (string) $response->json('action_url'));
    }

    /**
     * `TrustHosts::shouldSpecifyTrustedHosts()` returns false whenever
     * `$app->runningUnitTests()` is true, so the allowlist is NEVER enforced
     * from inside this suite — an HTTP-level test of it would pass whether or
     * not `trustHosts(...)` is registered at all, which is worse than no test.
     *
     * So assert the patterns themselves, the same way
     * `Symfony\Component\HttpFoundation\Request::getHost()` consumes them:
     * as case-insensitive regular expressions, unanchored unless the pattern
     * anchors itself. That unanchored-ness is the trap this test exists for —
     * a bare `'makam.co.id'` would be a regex matching
     * `makamXcoZid.attacker.example`.
     */
    public function test_the_trusted_host_allowlist_is_anchored_and_covers_every_live_host(): void
    {
        $patterns = (new TrustHosts($this->app))->hosts();

        $matches = static fn (string $host): bool => (bool) array_filter(
            $patterns,
            static fn (string $pattern): bool => (bool) preg_match('{'.$pattern.'}i', $host),
        );

        // Every hostname an nginx vhost on the deployment host routes to the
        // application, plus the loopback host the container's own
        // `HEALTHCHECK` sends (`http://127.0.0.1:8080/up` — `getHost()`
        // strips the port before matching). Omitting the loopback entry is
        // how a trusted-hosts change takes the site down: the health probe
        // would get a 400 SuspiciousOperationException and the container
        // would be marked unhealthy.
        foreach (['makam.co.id', 'www.makam.co.id', 'dev.makam.co.id', 'stg.makam.co.id', '127.0.0.1', 'localhost'] as $host) {
            $this->assertTrue($matches($host), "Trusted-host allowlist does not cover {$host}.");
        }

        foreach ([
            self::EVIL_HOST,
            // Not a wildcard: `trustHosts(..., subdomains: false)` is
            // deliberate, so a subdomain takeover cannot be escalated into
            // reset-link poisoning.
            'evil.makam.co.id',
            // The unanchored-regex trap.
            'makamXcoZid.attacker.example',
            'makam.co.id.attacker.example',
            'attacker.example',
        ] as $host) {
            $this->assertFalse($matches($host), "Trusted-host allowlist wrongly covers {$host}.");
        }
    }
}
