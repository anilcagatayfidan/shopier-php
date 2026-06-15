<?php

declare(strict_types=1);

namespace Shopier\Checkout;

use Shopier\Config;
use Shopier\DTO\Customer;
use Shopier\DTO\PaymentUrlRequest;
use Shopier\DTO\PaymentUrlResult;
use Shopier\DTO\ProductCreateRequest;
use Shopier\Exception\CheckoutFlowException;
use Shopier\Exception\ValidationException;
use Shopier\Http\Response;
use Shopier\Resources\Products;

final class PaymentUrlGenerator
{
    public function __construct(
        private readonly Products $products,
        private readonly BrowserSession $browserSession,
        private readonly HtmlParser $htmlParser,
        private readonly Config $config
    ) {
    }

    public function createProductAndPaymentUrl(ProductCreateRequest $productRequest, Customer $customer, int $quantity = 1): PaymentUrlResult
    {
        return $this->createFromRequest(new PaymentUrlRequest($productRequest, $customer, $quantity));
    }

    public function createFromRequest(PaymentUrlRequest $request): PaymentUrlResult
    {
        $product = $this->products->create($request->productCreateRequest);

        if ($product->url === null || trim($product->url) === '') {
            throw new CheckoutFlowException('Product response does not include a product URL.');
        }

        if ($product->id === '') {
            throw new CheckoutFlowException('Product response does not include a product id.');
        }

        $productPage = $this->loadProductPage($product->url);

        $shopName = $this->htmlParser->extractShopName($productPage->body());
        $csrfToken = $this->htmlParser->extractCsrfToken($productPage->body());

        $storefrontProductId = $this->extractStorefrontProductId($product->url);
        $this->addToCart($shopName, $storefrontProductId, $request->quantity, $csrfToken);
        $this->checkPaymentProgress($shopName, $storefrontProductId, $request->quantity, $csrfToken);
        $shippingUrl = $this->frontendUrl('/s/shipping/' . rawurlencode($shopName));
        $shippingPage = $this->browserSession->get($shippingUrl);
        $this->assertFrontendSuccess($shippingPage, $shippingUrl, 'Shipping page could not be loaded.');
        $formToken = $this->generateToken($request->customer);
        $orderId = $this->processShipmentForm($shopName, $request->customer);
        $paymentUrl = $this->frontendUrl('/s/payment/' . rawurlencode($shopName) . '/' . rawurlencode($orderId));
        $paymentPage = $this->submitPaymentPage($paymentUrl, $request->customer, $formToken);
        $this->assertFrontendSuccess($paymentPage, $paymentUrl, 'Payment page could not be loaded.');

        return new PaymentUrlResult($product, $shopName, $orderId, $paymentUrl);
    }

    /**
     * Adds the product to the storefront cart (Shopier spells it "chart"). This is
     * the step that actually populates the cart; without it the later shipment form
     * returns {"status":"error","error":"Chart is empty"}. Returns nothing on success
     * ({"status":1, "basket": {...}}).
     */
    private function addToCart(string $shopName, string $productId, int $quantity, string $csrfToken): void
    {
        if ($quantity < 1) {
            throw new ValidationException('Quantity must be greater than zero.');
        }

        $url = $this->frontendUrl('/s/api/v1/add_chart_item/' . rawurlencode($shopName));
        $response = $this->browserSession->postForm(
            $url,
            [
                'product_id' => $productId,
                'quantity' => $quantity,
            ],
            ['x-csrf-token' => $csrfToken]
        );

        $this->assertFrontendSuccess($response, $url, 'Add to cart request failed.');
        $data = $response->json();

        if ((int) ($data['status'] ?? 0) !== 1) {
            throw new CheckoutFlowException(
                'Add to cart (add_chart_item) did not succeed.'
                . ' URL: ' . $url
                . '. Response body (first 300 chars): ' . substr(trim($response->body()), 0, 300)
            );
        }
    }

    private function checkPaymentProgress(string $shopName, string $productId, int $quantity, string $csrfToken): void
    {
        if ($quantity < 1) {
            throw new ValidationException('Quantity must be greater than zero.');
        }

        $url = $this->frontendUrl('/s/api/v1/check_payment_progress/' . rawurlencode($shopName));
        $response = $this->browserSession->postForm(
            $url,
            [
                'product_id' => $productId,
                'quantity' => $quantity,
            ],
            ['x-csrf-token' => $csrfToken]
        );

        $this->assertFrontendSuccess($response, $url, 'Payment progress request failed.');
        $data = $response->json();

        if (($data['status'] ?? null) !== 'ok') {
            throw new CheckoutFlowException('Payment progress did not return status ok.');
        }
    }

    private function generateToken(Customer $customer): string
    {
        $url = $this->frontendUrl('/s/api/v1/token_calculator');
        $response = $this->browserSession->postForm(
            $url,
            ['data' => (new TokenCalculatorPayload($customer))->toString()],
            ['x-csrf-token' => '']
        );

        $this->assertFrontendSuccess($response, $url, 'Token calculator request failed.');
        $data = $response->json();

        if (($data['status'] ?? null) !== 'token_generated') {
            throw new CheckoutFlowException('Token calculator did not return status token_generated.');
        }

        $token = $data['token'] ?? null;

        if (!is_string($token) || trim($token) === '') {
            throw new CheckoutFlowException('Token calculator did not return a token.');
        }

        return $token;
    }

    /**
     * Buyer fields shared by the shipment form and the payment page submission.
     *
     * @return array<string, string>
     */
    private function customerFormData(Customer $customer): array
    {
        return [
            'Email' => $customer->email,
            'phone-contact-select' => $customer->countryCode(),
            'formControlPhone' => $customer->nationalPhoneFormatted(),
            'Phone' => $customer->formattedPhone(),
            'FirstName' => $customer->firstName,
            'LastName' => $customer->lastName,
            'country' => $customer->countryDisplayName(),
            'TCIDNo' => '',
            'Comment' => '',
        ];
    }

    private function processShipmentForm(string $shopName, Customer $customer): string
    {
        $url = $this->frontendUrl('/s/api/v1/shipment_form_process/' . rawurlencode($shopName));
        $formData = $this->customerFormData($customer);
        $response = $this->browserSession->postForm(
            $url,
            $formData,
            ['x-csrf-token' => '']
        );

        $this->assertFrontendSuccess($response, $url, 'Shipment form request failed.');
        $data = $response->json();
        $orderId = $data['order_id']
            ?? $data['orderId']
            ?? $data['data']['order_id']
            ?? $data['data']['orderId']
            ?? null;

        if (!is_scalar($orderId) || trim((string) $orderId) === '') {
            throw new CheckoutFlowException(
                'Shipment form response does not include order_id.'
                . ' URL: ' . $url
                . '. HTTP status: ' . $response->statusCode()
                . '. Response body (first 500 chars): ' . substr(trim($response->body()), 0, 500)
            );
        }

        return (string) $orderId;
    }

    private function submitPaymentPage(string $paymentUrl, Customer $customer, string $formToken): Response
    {
        $formData = $this->customerFormData($customer);
        $formData['form_token'] = $formToken;

        return $this->browserSession->postFormNavigate($paymentUrl, $formData);
    }

    /**
     * Loads the freshly created product's storefront page. A just-created product
     * can be briefly unpublished (404) before it propagates, so 404s are retried
     * with a short backoff. A 403 (WAF/Cloudflare block) is NOT retried — it is a
     * hard block that headers alone will not clear, so it fails fast with guidance.
     */
    private function loadProductPage(string $url): Response
    {
        $attempts = 3;
        $delaysMs = [500, 1000];
        $response = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $response = $this->browserSession->get($url);

            if ($response->isSuccessful()) {
                return $response;
            }

            // 404 means "not published yet" — worth retrying. Anything else
            // (notably 403) is a block we should surface immediately.
            if ($response->statusCode() !== 404 || $attempt === $attempts) {
                break;
            }

            $this->sleepMs($delaysMs[$attempt - 1] ?? 1000);
        }

        /** @var Response $response */
        $this->assertFrontendSuccess($response, $url, 'Product page could not be loaded.');

        return $response;
    }

    private function assertFrontendSuccess(Response $response, string $url, string $message): void
    {
        if ($response->isSuccessful()) {
            return;
        }

        $detail = $message
            . ' URL: ' . $url
            . '. HTTP status: ' . $response->statusCode() . '.';

        if ($this->looksLikeChallenge($response)) {
            $detail .= ' The storefront returned a bot/JS challenge (e.g. Cloudflare "Just a moment..."/'
                . '"Attention Required"). This cannot be bypassed with HTTP headers alone — the checkout '
                . 'flow requires a real browser session cookie or an official payment-link endpoint. See README.';
        }

        $body = trim($response->body());

        if ($body !== '') {
            $detail .= ' Response body (first 500 chars): ' . substr($body, 0, 500);
        }

        throw new CheckoutFlowException($detail);
    }

    private function looksLikeChallenge(Response $response): bool
    {
        if (!in_array($response->statusCode(), [403, 429, 503], true)) {
            return false;
        }

        $body = strtolower($response->body());
        $server = strtolower($response->header('server') ?? '');

        foreach (['just a moment', 'attention required', 'cf-browser-verification', 'checking your browser', '/cdn-cgi/challenge-platform'] as $needle) {
            if (str_contains($body, $needle)) {
                return true;
            }
        }

        return str_contains($server, 'cloudflare') && $response->statusCode() === 403;
    }

    private function sleepMs(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }

    /**
     * The storefront identifies a product by the numeric id in its public URL
     * (e.g. https://www.shopier.com/48081835 -> "48081835"), which differs from
     * the REST API product id. The cart/check_payment_progress step needs this
     * storefront id, otherwise the item is never added and the cart stays empty.
     */
    private function extractStorefrontProductId(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (is_string($path) && preg_match('/(\d+)(?!.*\d)/', $path, $matches) === 1) {
            return $matches[1];
        }

        throw new CheckoutFlowException('Could not determine the storefront product id from URL: ' . $url . '.');
    }

    private function frontendUrl(string $path): string
    {
        return $this->config->frontendBaseUrl() . '/' . ltrim($path, '/');
    }
}
