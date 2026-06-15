<?php

declare(strict_types=1);

namespace Shopier\DTO;

final class WebhookSubscription
{
    public function __construct(
        public readonly string $id,
        public readonly string $url,
        public readonly string $event,
        public readonly ?string $token = null,
        public readonly array $data = []
    ) {
    }

    public static function fromArray(array $data): self
    {
        if (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }

        $id = $data['id'] ?? $data['webhookId'] ?? $data['webhook_id'] ?? '';
        $url = $data['url'] ?? $data['endpoint'] ?? '';
        $event = $data['event'] ?? $data['eventName'] ?? $data['event_name'] ?? '';
        $token = $data['token'] ?? $data['webhook_token'] ?? $data['webhookToken'] ?? null;

        return new self((string) $id, (string) $url, (string) $event, $token !== null ? (string) $token : null, $data);
    }

    public function toArray(): array
    {
        if ($this->data !== []) {
            return $this->data;
        }

        $array = [
            'id' => $this->id,
            'url' => $this->url,
            'event' => $this->event,
        ];

        if ($this->token !== null) {
            $array['token'] = $this->token;
        }

        return $array;
    }
}
