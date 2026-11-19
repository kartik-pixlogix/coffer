<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Plan;
use RuntimeException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * Stands in for Stripe's HTTP API in tests (D80): no network, no account. Cashier and
 * stripe-php run for real; each request is matched by method and path to a canned JSON
 * body, and recorded so a test can assert what was sent. Install with FakeStripe::install().
 */
final class FakeStripe implements ClientInterface
{
    /** @var list<array{method: string, path: string, params: array<string, mixed>}> */
    public array $requests = [];

    /** @var array<string, array{status: int, body: array<string, mixed>}> "post /v1/customers" → response */
    private array $routes = [];

    public static function install(): self
    {
        config(['cashier.key' => 'pk_test_fake', 'cashier.secret' => 'sk_test_fake']);
        $fake = new self;
        ApiRequestor::setHttpClient($fake);

        return $fake;
    }

    /**
     * Every test starts here (tests/Pest.php): no keys, no price ids, and an HTTP client
     * that answers nothing, whatever the developer's .env holds, so a test can never
     * reach Stripe or create anything in a real account.
     */
    public static function block(): void
    {
        ApiRequestor::setHttpClient(new self);
        $prices = (array) config('billing.stripe.prices', []);
        array_walk_recursive($prices, function (mixed &$id): void {
            $id = null;
        });
        config(['cashier.key' => null, 'cashier.secret' => null, 'cashier.webhook.secret' => null, 'billing.stripe.prices' => $prices]);
    }

    /** Back to the blocking client (never the real one). */
    public static function uninstall(): void
    {
        ApiRequestor::setHttpClient(new self);
    }

    /** @param array<string, mixed> $body */
    public function on(string $method, string $path, array $body, int $status = 200): self
    {
        $this->routes[strtolower($method).' '.$path] = ['status' => $status, 'body' => $body];

        return $this;
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $path = (string) parse_url($absUrl, PHP_URL_PATH);
        $this->requests[] = ['method' => strtolower($method), 'path' => $path, 'params' => is_array($params) ? $params : []];
        $route = $this->routes[strtolower($method).' '.$path] ?? null;

        if ($route === null) {
            throw new RuntimeException("FakeStripe has no answer for {$method} {$path}.");
        }

        return [json_encode($route['body'], JSON_THROW_ON_ERROR), $route['status'], ['request-id' => 'req_fake']];
    }

    /** @return list<array{method: string, path: string, params: array<string, mixed>}> */
    public function sent(string $method, string $path): array
    {
        return array_values(array_filter($this->requests, fn (array $r) => $r['method'] === strtolower($method) && $r['path'] === $path));
    }

    /** A Stripe Price that charges exactly what the plan shows (the B1 safeguard reads it). */
    public function price(string $id, string $plan, string $cycle, string $currency, ?int $amount = null): self
    {
        $amount ??= Plan::query()->where('key', $plan)->sole()->price($cycle, $currency);

        return $this->on('get', '/v1/prices/'.$id, ['id' => $id, 'object' => 'price', 'currency' => $currency, 'unit_amount' => $amount, 'product' => 'prod_'.$plan]);
    }

    /** A customer for `customers.create`. */
    public function customer(string $id = 'cus_test123'): self
    {
        return $this->on('post', '/v1/customers', ['id' => $id, 'object' => 'customer', 'email' => 'billing@example.test', 'invoice_settings' => ['default_payment_method' => null]]);
    }
}
