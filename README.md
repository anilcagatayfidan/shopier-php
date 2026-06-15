# shopier-php

`shopier-php`, Shopier REST API üzerinde ürün ve webhook işlemlerini, ayrıca eğitim amaçlı ödeme URL üretim akışını gösteren modern bir PHP Composer paketidir.

Bu paket resmi Shopier SDK'sı değildir. Shopier tarafından onaylanmış, desteklenen veya garanti edilen bir entegrasyon olarak değerlendirilmemelidir. Kod eğitim amaçlıdır; canlı sistemlerde kullanmadan önce tüm akışı, güvenliği, hata davranışlarını ve Shopier kullanım şartlarını doğrulamak kullanan kişinin sorumluluğundadır. Bu paketi kullanan kişi tüm sonuçlardan, veri güvenliğinden ve operasyonel risklerden kendisi sorumludur.

## Kurulum

```bash
composer require shopier-php/shopier-php
```

Yerel geliştirme için:

```bash
composer dump-autoload
```

## Gereksinimler

- PHP 8.1+
- `ext-curl`
- `ext-json`

## Güvenlik uyarıları

Personal Access Token değerini asla kaynak koda yazmayın, herkese açık depolara göndermeyin veya istemci tarafında kullanmayın. Token değerini ortam değişkeni, güvenli secret manager veya sunucu tarafı yapılandırma üzerinden yönetin.

README örneklerinde token bilinçli olarak maskelenmiştir:

```php
$client = new Shopier\Client('shp_pat_****************');
```

Ödeme URL üretimi, ürün oluşturma için resmi REST API'yi kullanır; ancak sonraki adımlar değişebilir, Shopier arayüz değişikliklerinden etkilenebilir, kullanım şartları açısından risk oluşturabilir ve üretim ortamında beklenmeyen sonuçlar doğurabilir. Bu akışı kullanmadan önce hukuki, teknik ve operasyonel riskleri değerlendirin.

## Hızlı başlangıç

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Shopier\Client;
use Shopier\DTO\ProductCreateRequest;

$client = new Client(getenv('SHOPIER_PAT'));

$product = $client->products()->create(new ProductCreateRequest(
    title: 'Digital Guide',
    media: [
        [
            'type' => 'image',
            'url' => 'https://example.com/product.jpg',
            'placement' => 1,
        ],
    ],
    currency: 'TRY',
    price: '149.90',
    shippingPayer: 'sellerPays'
));

echo $product->url;
```

## Yapılandırma

```php
use Shopier\Client;
use Shopier\Config;

$config = new Config(
    personalAccessToken: getenv('SHOPIER_PAT'),
    apiBaseUrl: 'https://api.shopier.com/v1/',
    frontendBaseUrl: 'https://www.shopier.com',
    timeout: 30,
    userAgent: 'my-app/1.0'
);

$client = new Client($config);
```

## Ürün oluşturma

İlk kapsamda yalnızca `digital` ürün tipi desteklenir ve varsayılandır. `media` alanı en fazla 5 öğe alır. Her medya öğesinde `type=image`, `url` ve `placement` bulunmalıdır.

```php
use Shopier\DTO\ProductCreateRequest;

$request = new ProductCreateRequest(
    title: 'E-book',
    media: [
        ['type' => 'image', 'url' => 'https://example.com/cover.jpg', 'placement' => 1],
    ],
    currency: 'TRY',
    price: 99.90,
    shippingPayer: 'sellerPays'
);

$product = $client->products()->create($request);
```

## Webhook işlemleri

Desteklenen event değerleri:

- `order.addressUpdated`
- `order.created`
- `order.fulfilled`
- `product.created`
- `product.updated`
- `refund.requested`
- `refund.updated`

```php
$subscriptions = $client->webhooks()->list();

$subscription = $client->webhooks()->create(
    url: 'https://example.com/shopier/webhook',
    event: 'order.created'
);

$client->webhooks()->delete($subscription->id);
```

## Webhook imza doğrulama

```php
use Shopier\Webhook\WebhookVerifier;

$rawPayload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_SHOPIER_SIGNATURE'] ?? '';
$token = getenv('SHOPIER_WEBHOOK_TOKEN');

$isValid = WebhookVerifier::verify($rawPayload, $signature, $token);
```

## Ödeme URL üretimi

```php
use Shopier\DTO\Customer;
use Shopier\DTO\ProductCreateRequest;

$productRequest = new ProductCreateRequest(
    title: 'Digital Course',
    media: [
        ['type' => 'image', 'url' => 'https://example.com/course.jpg', 'placement' => 1],
    ],
    currency: 'TRY',
    price: '499.00',
    shippingPayer: 'sellerPays'
);

$customer = new Customer(
    email: 'customer@example.com',
    phoneCountryCode: '+90',
    phoneWithoutCountryNumber: '5551234567',
    firstName: 'Ada',
    lastName: 'Lovelace',
    country: 'TR'
);

$result = $client->checkout()->createProductAndPaymentUrl(
    productRequest: $productRequest,
    customer: $customer,
    quantity: 1
);

echo $result->paymentUrl;
```

## Hata yönetimi

```php
use Shopier\Exception\ApiException;
use Shopier\Exception\AuthenticationException;
use Shopier\Exception\RateLimitException;
use Shopier\Exception\ValidationException;

try {
    $product = $client->products()->create($request);
} catch (AuthenticationException $exception) {
    echo 'Token geçersiz veya yetki yetersiz.';
} catch (RateLimitException $exception) {
    echo 'API limitine ulaşıldı.';
} catch (ValidationException $exception) {
    echo $exception->getMessage();
} catch (ApiException $exception) {
    echo $exception->getMessage();
}
```

## Lisans

MIT License.
