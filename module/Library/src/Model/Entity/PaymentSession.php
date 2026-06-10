<?php

declare(strict_types=1);

namespace Library\Model\Entity;

class PaymentSession
{
    public int $id = 0;
    public string $orderCode = '';
    public string $channel = 'momo';
    public float $amount = 0.0;
    public string $status = 'pending';
    public int $targetId = 0;
    public string $targetType = 'fine';
    public ?string $transactionId = null;
    public string $expiredAt = '';
    public string $createdAt = '';
    public string $updatedAt = '';

    public function exchangeArray(array $data): void
    {
        $this->id = (int) ($data['session_id'] ?? $data['id'] ?? 0);
        $this->orderCode = (string) ($data['order_code'] ?? $data['orderCode'] ?? '');
        $this->channel = (string) ($data['channel'] ?? 'momo');
        $this->amount = (float) ($data['amount'] ?? 0.0);
        $this->status = (string) ($data['status'] ?? 'pending');
        $this->targetId = (int) ($data['target_id'] ?? $data['targetId'] ?? 0);
        $this->targetType = (string) ($data['target_type'] ?? $data['targetType'] ?? 'fine');
        $this->transactionId = $data['transaction_id'] ?? $data['transactionId'] ?? null;
        $this->expiredAt = (string) ($data['expired_at'] ?? $data['expiredAt'] ?? '');
        $this->createdAt = (string) ($data['created_at'] ?? $data['createdAt'] ?? '');
        $this->updatedAt = (string) ($data['updated_at'] ?? $data['updatedAt'] ?? '');
    }

    public function getArrayCopy(): array
    {
        return [
            'session_id' => $this->id,
            'order_code' => $this->orderCode,
            'channel' => $this->channel,
            'amount' => $this->amount,
            'status' => $this->status,
            'target_id' => $this->targetId,
            'target_type' => $this->targetType,
            'transaction_id' => $this->transactionId,
            'expired_at' => $this->expiredAt,
        ];
    }
}
