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

`stockQuantity` resmi API'de zorunlu **değildir**, ancak gönderilmezse ürün stoğu 0
kabul edilip **"tükendi"** olarak görünebilir. Satışa açık bir ürün için pozitif bir
stok değeri vermeniz önerilir.

```php
use Shopier\DTO\ProductCreateRequest;

$request = new ProductCreateRequest(
    title: 'E-book',
    media: [
        ['type' => 'image', 'url' => 'https://example.com/cover.jpg', 'placement' => 1],
    ],
    currency: 'TRY',
    price: 99.90,
    shippingPayer: 'sellerPays',
    stockQuantity: 100
);

$product = $client->products()->create($request);
```

## Sipariş işlemleri

Siparişleri listeleme, tekil sipariş getirme, sipariş güncelleme (kapatma / kargo
adresi değiştirme) ve sipariş işlem/finans bilgisini çekme desteklenir.

```php
// Listeleme — desteklenen filtreler:
// dateStart, dateEnd (yyyy-MM-ddTHH:mm:ssZ), fulfillmentStatus (unfulfilled|fulfilled),
// refundType (none|partial|full), customerEmail, customerPhone, productId,
// limit (1-50, vars. 10), page (>=1), sort (dateAsc|dateDesc, vars. dateDesc)
$orders = $client->orders()->list([
    'fulfillmentStatus' => 'unfulfilled',
    'limit' => 50,
    'sort' => 'dateDesc',
]);

foreach ($orders as $order) {
    if ($order->isPaid()) {
        // $order->id, $order->totals, $order->lineItems, $order->shippingInfo ...
    }
}

// Tekil sipariş
$order = $client->orders()->get('ORDER_ID');

// Siparişi güncelle (kapatma için fulfillments, adres için shippingInfo)
$order = $client->orders()->update('ORDER_ID', [
    'fulfillments' => [/* teslimat detayları */],
]);

// Ek finans bilgisi (taksit vb.)
$transaction = $client->orders()->transaction('ORDER_ID');
```

`Order` modelindeki alanlar: `id`, `status` (fulfilled|unfulfilled), `paymentStatus`
(paid|unpaid), `installments`, `paymentMethod`, `currency`, `dateCreated`, `totals`,
`discounts`, `shippingInfo`, `billingInfo`, `note`, `lineItems`, `fulfillments`,
`returns`, `refunds`. Ham yanıta `$order->toArray()` ile erişebilirsiniz.

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

// İsteğe bağlı sayfalama (limit: 1-50, varsayılan 10; page: >=1; sort: asc|desc)
$subscriptions = $client->webhooks()->list(limit: 50, page: 1, sort: 'desc');

$subscription = $client->webhooks()->create(
    url: 'https://example.com/shopier/webhook',
    event: 'order.created'
);

// token yalnızca create yanıtında döner ve webhook payload imzalarını doğrulamak
// için kullanılır. Güvenli bir yerde saklayın; sonraki list çağrılarında dönmez.
$webhookToken = $subscription->token;

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

### Storefront / 403 ve bot koruması hakkında

Ödeme URL üretimi resmi API değildir; Shopier mağaza (storefront) sayfalarını bir
tarayıcı gibi çağırır. Bu istekler artık gerçekçi bir tarayıcı UA'sı, tam tarayıcı
header seti (Accept-Language, Sec-Fetch-*, Upgrade-Insecure-Requests, Referer),
redirect takibi ve adımlar arası ortak cookie jar ile gönderilir.

Buna rağmen `CheckoutFlowException` ile **HTTP 403** alıyorsanız ve hata mesajı bir
**bot/JS challenge** (Cloudflare "Just a moment..." / "Attention Required") içeriyorsa,
bu yalnızca header ekleyerek aşılamaz. Bu durumda engel datacenter/sunucu IP'sine
ve JavaScript challenge'ına dayanır; çözüm için gerçek bir tarayıcı oturumundan
alınmış cookie'leri kullanmanız veya resmi bir ödeme-linki ucu beklemeniz gerekir.
Header/UA özelleştirmesi için `HttpClientInterface` kendi implementasyonunuzla
değiştirilebilir ve `Config(userAgent: ...)` ile UA ayarlanabilir.

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
