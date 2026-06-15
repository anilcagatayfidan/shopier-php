<?php

declare(strict_types=1);

namespace Shopier;

use Shopier\Checkout\BrowserSession;
use Shopier\Checkout\HtmlParser;
use Shopier\Checkout\PaymentUrlGenerator;
use Shopier\Http\CurlHttpClient;
use Shopier\Http\HttpClientInterface;
use Shopier\Resources\Orders;
use Shopier\Resources\Products;
use Shopier\Resources\Webhooks;

final class Client
{
    private Products $products;
    private Webhooks $webhooks;
    private Orders $orders;
    private PaymentUrlGenerator $checkout;

    /**
     * @param HttpClientInterface|null $httpClient           Client for the official REST API
     *                                                       (api.shopier.com). Defaults to plain cURL.
     * @param HttpClientInterface|null $storefrontHttpClient Client for the storefront/checkout flow
     *                                                       (www.shopier.com). Use a TLS-impersonation
     *                                                       client here to pass Cloudflare. Defaults to
     *                                                       $httpClient. The REST API works fine with
     *                                                       plain cURL, so impersonation is best scoped
     *                                                       to the storefront only.
     */
    public function __construct(
        Config|string $config,
        ?HttpClientInterface $httpClient = null,
        ?HttpClientInterface $storefrontHttpClient = null
    ) {
        $config = is_string($config) ? new Config($config) : $config;
        $httpClient ??= new CurlHttpClient();
        $storefrontHttpClient ??= $httpClient;

        $this->products = new Products($config, $httpClient);
        $this->webhooks = new Webhooks($config, $httpClient);
        $this->orders = new Orders($config, $httpClient);
        $this->checkout = new PaymentUrlGenerator(
            $this->products,
            new BrowserSession($config, $storefrontHttpClient),
            new HtmlParser(),
            $config
        );
    }

    public function products(): Products
    {
        return $this->products;
    }

    public function webhooks(): Webhooks
    {
        return $this->webhooks;
    }

    public function orders(): Orders
    {
        return $this->orders;
    }

    public function checkout(): PaymentUrlGenerator
    {
        return $this->checkout;
    }
}
