<?php

declare(strict_types=1);

namespace Shopier;

use Shopier\Checkout\BrowserSession;
use Shopier\Checkout\HtmlParser;
use Shopier\Checkout\PaymentUrlGenerator;
use Shopier\Http\CurlHttpClient;
use Shopier\Http\HttpClientInterface;
use Shopier\Resources\Products;
use Shopier\Resources\Webhooks;

final class Client
{
    private Products $products;
    private Webhooks $webhooks;
    private PaymentUrlGenerator $checkout;

    public function __construct(
        Config|string $config,
        ?HttpClientInterface $httpClient = null
    ) {
        $config = is_string($config) ? new Config($config) : $config;
        $httpClient ??= new CurlHttpClient();

        $this->products = new Products($config, $httpClient);
        $this->webhooks = new Webhooks($config, $httpClient);
        $this->checkout = new PaymentUrlGenerator(
            $this->products,
            new BrowserSession($config, $httpClient),
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

    public function checkout(): PaymentUrlGenerator
    {
        return $this->checkout;
    }
}
