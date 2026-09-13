<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
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

    /**
     * `X-Forwarded-Port` is the same defect shape as `X-Forwarded-Host`, one
     * severity down: the domain stays ours, so a forged port breaks the link
     * rather than redirecting the victim to an attacker. It is dropped from
     * the trusted bitmask because NO nginx config in this stack sets it —
     * the live vhosts set `Host`, `X-Real-IP`, `X-Forwarded-For` and
     * `X-Forwarded-Proto`, and nothing else — so trusting it was attack
     * surface with no consumer.
     *
     * Dropping it is provably correct, not merely safer. With both PORT and
     * HOST untrusted, `Request::getPort()` falls past both trusted-header
     * branches to the `Host` header, which behind these vhosts carries no
     * port, and returns 443 from the scheme. `getHttpHost()` then omits the
     * port entirely because it is the default for https. That is why this
     * test asserts the ABSENCE of any `:port`, not the presence of `:443`.
     */
    public function test_a_spoofed_x_forwarded_port_does_not_reach_the_generated_url(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => self::PROXY_IP])
            ->get('http://makam.co.id/__sec_n1__/probe', [
                'X-Forwarded-Port' => '1337',
                'X-Forwarded-For' => self::CLIENT_IP,
                'X-Forwarded-Proto' => 'https',
            ]);

        $response->assertOk();

        $actionUrl = (string) $response->json('action_url');

        $this->assertStringNotContainsString(
            ':1337',
            $actionUrl,
            'A client-supplied X-Forwarded-Port reached the password-reset link.',
        );
        $this->assertStringStartsWith('https://makam.co.id/reset-password/SAMPLE', $actionUrl);
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
     * So take the configured patterns and hand them to the real consumer:
     * `Request::setTrustedHosts()` followed by an actual `getHost()` call.
     * An earlier version of this test ran `preg_match()` over the patterns
     * itself, which tested our BELIEF about how Symfony consumes them rather
     * than Symfony — a future release that auto-anchored patterns would have
     * left it green while the behaviour underneath changed.
     *
     * Two properties fall out of driving the real consumer that a regex
     * re-implementation could not have shown: that `getHost()` strips the
     * port before matching (the `127.0.0.1:8080` case below, which is the
     * container HEALTHCHECK's literal `Host` header), and that a rejection
     * is a thrown `SuspiciousOperationException` rather than a falsy return.
     */
    public function test_the_trusted_host_allowlist_is_anchored_and_covers_every_live_host(): void
    {
        // FIRST, and before any pattern is read: is the middleware that
        // consumes these patterns actually in the stack?
        //
        // This assertion exists because its absence already caused a wrong
        // conclusion once. Constructing `new TrustHosts($this->app)` below
        // reports what the patterns WOULD be; it says nothing about whether
        // anything consumes them. Reading only that, a previous version of
        // this work concluded the pre-fix application had a
        // wildcard-subdomain allowlist inherited from `app.url`. It did not:
        // `Middleware::$trustHosts` defaults to false
        // (`Illuminate\Foundation\Configuration\Middleware.php:105`) and
        // `getGlobalMiddleware()` includes `TrustHosts::class` only when that
        // flag is true (`:456`). `bootstrap/app.php` never called
        // `trustHosts()`, so the middleware was absent, `setTrustedHosts()`
        // was never reached, and `getHost()` accepted ANY syntactically valid
        // host — which is why the original exploit reached
        // `evil-attacker.example`, a domain no wildcard over `makam.co.id`
        // could ever have matched.
        //
        // It also closes a real divergence, not a theoretical one:
        // `$middleware->trustHosts()` with NO arguments sets the flag but
        // leaves `TrustHosts::$alwaysTrust` null, silently falling back to
        // the `app.url` wildcard. The two halves of this test cover opposite
        // failures — this assertion catches "patterns configured, middleware
        // not registered", the pattern assertions below catch "middleware
        // registered, patterns wrong".
        $this->assertContains(
            TrustHosts::class,
            $this->app->make(HttpKernel::class)->getGlobalMiddleware(),
            'TrustHosts is not in the global middleware stack, so the trusted-host '
            .'allowlist below is never applied to a real request no matter what it contains.',
        );

        // `app.url` is read ONLY by `TrustHosts::allSubdomainsOfApplicationUrl()`,
        // which `subdomains: false` never reaches — which is precisely why this
        // test has to pin it. Under PHPUnit `app.url` falls back to
        // `http://localhost` (`config/app.php`; `phpunit.xml` sets no
        // APP_URL), so flipping the flag to `subdomains: true` would append
        // `^(.+\.)?localhost$` — harmless against every hostname asserted
        // below, and the mutant would survive unnoticed. The deployed values
        // are what make the flag observable: on beta (APP_ENV=production)
        // `APP_URL=https://makam.co.id`, so the same flip appends
        // `^(.+\.)?makam\.co\.id$` and silently trusts every subdomain.
        // `true` is also Laravel's default if the argument is ever dropped.
        foreach (['https://makam.co.id', 'https://dev.makam.co.id'] as $appUrl) {
            config(['app.url' => $appUrl]);

            $patterns = (new TrustHosts($this->app))->hosts();

            // Every hostname an nginx vhost on the deployment host routes to
            // the application, plus the loopback authority the container's own
            // HEALTHCHECK sends verbatim (`Dockerfile`:
            // `file_get_contents("http://127.0.0.1:8080/up")`).
            foreach (['makam.co.id', 'www.makam.co.id', 'dev.makam.co.id', 'stg.makam.co.id', '127.0.0.1', '127.0.0.1:8080', 'localhost'] as $authority) {
                $expected = explode(':', $authority)[0];

                $this->assertSame(
                    $expected,
                    $this->hostSymfonyResolves($patterns, $authority),
                    "Trusted-host allowlist does not cover {$authority} (app.url {$appUrl}).",
                );
            }

            foreach ([
                self::EVIL_HOST,
                // Not a wildcard: `trustHosts(..., subdomains: false)` is
                // deliberate, so a subdomain takeover cannot be escalated
                // into reset-link poisoning. One entry per app.url value
                // above, so that BOTH deployed values pin the flag.
                'evil.makam.co.id',
                'evil.dev.makam.co.id',
                // The unanchored-regex trap: `Request::setTrustedHosts()`
                // wraps each pattern as `{...}i` and anchors nothing, so a
                // bare `'makam.co.id'` would match all three of these.
                'makamXcoZid.attacker.example',
                'makam.co.id.attacker.example',
                'attacker.example',
            ] as $authority) {
                $this->assertTrue(
                    $this->symfonyRejects($patterns, $authority),
                    "Trusted-host allowlist wrongly covers {$authority} (app.url {$appUrl}).",
                );
            }
        }
    }

    /**
     * `Request::$trustedHostPatterns` is a static on
     * `Symfony\Component\HttpFoundation\Request`, and NOTHING in
     * `Illuminate\Foundation\Testing\` or `Illuminate\Foundation\Bootstrap\`
     * resets it — unlike `TrustProxies`/`TrustHosts`, which Laravel's own
     * `TestCase::tearDown()` flushes. So whatever this test sets survives for
     * the rest of the PHP process, across `refreshApplication()` and into
     * every later test.
     *
     * Be exact about the consequence, because the obvious version of this
     * sentence overstates it and is how someone later concludes the reset is
     * unnecessary and deletes it: the leaked list is the six configured
     * patterns, and `^localhost$` is one of them, so it would NOT break the
     * default test host. It would reject any OTHER host a later test uses —
     * anything building a request for `example.com`, say — and it would do so
     * as a `SuspiciousOperationException` from vendor code, a long way from
     * this file.
     */
    protected function tearDown(): void
    {
        Request::setTrustedHosts([]);

        parent::tearDown();
    }

    /**
     * A rejection is reported as this sentinel rather than allowed to
     * propagate, so a missing allowlist entry fails with THIS test's message
     * ("Trusted-host allowlist does not cover X") instead of surfacing as an
     * uncaught `SuspiciousOperationException` and a vendor stack trace. The
     * mutant dies either way; only the diagnostic differs, and the diagnostic
     * is what the next reader gets.
     *
     * @param  array<int, string>  $patterns
     */
    private function hostSymfonyResolves(array $patterns, string $authority): string
    {
        Request::setTrustedHosts($patterns);

        try {
            return Request::create('http://'.$authority.'/')->getHost();
        } catch (SuspiciousOperationException) {
            return '<rejected as untrusted>';
        }
    }

    /**
     * @param  array<int, string>  $patterns
     */
    private function symfonyRejects(array $patterns, string $authority): bool
    {
        Request::setTrustedHosts($patterns);

        try {
            Request::create('http://'.$authority.'/')->getHost();

            return false;
        } catch (SuspiciousOperationException) {
            return true;
        }
    }
}
