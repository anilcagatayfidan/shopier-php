<?php

declare(strict_types=1);

namespace Shopier\Checkout;

use Shopier\Config;
use Shopier\DTO\Customer;
use Shopier\DTO\PaymentUrlRequest;
use Shopier\DTO\PaymentUrlResult;
use Shopier\DTO\Product;
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

        $productPage = $this->browserSession->get($product->url);
        $this->assertFrontendSuccess($productPage, 'Product page could not be loaded.');

        $shopName = $this->htmlParser->extractShopName($productPage->body());
        $csrfToken = $this->htmlParser->extractCsrfToken($productPage->body());

        $this->checkPaymentProgress($shopName, $product, $request->quantity);
        $shippingPage = $this->browserSession->get($this->frontendUrl('/s/shipping/' . rawurlencode($shopName)));
        $this->assertFrontendSuccess($shippingPage, 'Shipping page could not be loaded.');
        $this->generateToken($request->customer);
        $orderId = $this->processShipmentForm($shopName, $request->customer, $csrfToken);
        $paymentUrl = $this->frontendUrl('/s/payment/' . rawurlencode($shopName) . '/' . rawurlencode($orderId));
        $paymentPage = $this->browserSession->get($paymentUrl);
        $this->assertFrontendSuccess($paymentPage, 'Payment page could not be loaded.');
        $paymentCsrfToken = $this->htmlParser->extractCsrfToken($paymentPage->body());
        $this->validateCouponState($shopName, $orderId, $paymentCsrfToken);

        return new PaymentUrlResult($product, $shopName, $orderId, $paymentUrl);
    }

    private function checkPaymentProgress(string $shopName, Product $product, int $quantity): void
    {
        if ($quantity < 1) {
            throw new ValidationException('Quantity must be greater than zero.');
        }

        $response = $this->browserSession->postForm(
            $this->frontendUrl('/s/api/v1/check_payment_progress/' . rawurlencode($shopName)),
            [
                'product_id' => $product->id,
                'quantity' => $quantity,
            ]
        );

        $this->assertFrontendSuccess($response, 'Payment progress request failed.');
        $data = $response->json();

        if (($data['status'] ?? null) !== 'ok') {
            throw new CheckoutFlowException('Payment progress did not return status ok.');
        }
    }

    private function generateToken(Customer $customer): void
    {
        $response = $this->browserSession->postForm(
            $this->frontendUrl('/s/api/v1/token_calculator'),
            ['data' => (new TokenCalculatorPayload($customer))->toString()],
            ['x-csrf-token' => '']
        );

        $this->assertFrontendSuccess($response, 'Token calculator request failed.');
        $data = $response->json();

        if (($data['status'] ?? null) !== 'token_generated') {
            throw new CheckoutFlowException('Token calculator did not return status token_generated.');
        }
    }

    private function processShipmentForm(string $shopName, Customer $customer, string $csrfToken): string
    {
        $response = $this->browserSession->postForm(
            $this->frontendUrl('/s/api/v1/shipment_form_process/' . rawurlencode($shopName)),
            [
                'Email' => $customer->email,
                'phone-contact-select' => $customer->normalizedCountryCode(),
                'formControlPhone' => $customer->phoneDigits(),
                'Phone' => $customer->formattedPhone(),
                'FirstName' => $customer->firstName,
                'LastName' => $customer->lastName,
                'country' => $customer->country,
                'TCIDNo' => '',
                'Comment' => '',
            ],
            ['x-csrf-token' => $csrfToken]
        );

        $this->assertFrontendSuccess($response, 'Shipment form request failed.');
        $data = $response->json();
        $orderId = $data['order_id'] ?? $data['orderId'] ?? null;

        if (!is_scalar($orderId) || trim((string) $orderId) === '') {
            throw new CheckoutFlowException('Shipment form response does not include order_id.');
        }

        return (string) $orderId;
    }

    private function validateCouponState(string $shopName, string $orderId, string $csrfToken): void
    {
        $response = $this->browserSession->postForm(
            $this->frontendUrl('/s/api/v1/coupon_code/' . rawurlencode($shopName)),
            [
                'request_type' => 'check_coupon_code_validity',
                'order_id' => $orderId,
            ],
            ['x-csrf-token' => $csrfToken]
        );

        if ($response->statusCode() !== 200 || trim($response->body()) !== '1') {
            throw new CheckoutFlowException('Coupon code validation step did not return success.');
        }
    }

    private function assertFrontendSuccess(Response $response, string $message): void
    {
        if ($response->isSuccessful()) {
            return;
        }

        throw new CheckoutFlowException($message . ' HTTP status: ' . $response->statusCode() . '.');
    }

    private function frontendUrl(string $path): string
    {
        return $this->config->frontendBaseUrl() . '/' . ltrim($path, '/');
    }
}
