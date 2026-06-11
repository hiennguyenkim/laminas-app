<?php

declare(strict_types=1);

namespace Library\Model\Table;

use Laminas\Db\TableGateway\TableGateway;
use Library\Model\Entity\PaymentSession;
use RuntimeException;

class PaymentSessionTable
{
    public function __construct(private TableGateway $tableGateway)
    {
    }

    public function getSession(string $orderCode): ?PaymentSession
    {
        /** @var \Laminas\Db\ResultSet\ResultSet $rowset */
        $rowset = $this->tableGateway->select(['order_code' => $orderCode]);
        $row = $rowset->current();
        if (!$row) {
            return null;
        }
        /** @var PaymentSession $row */
        return $row;
    }

    public function getSessionById(int $sessionId): ?PaymentSession
    {
        /** @var \Laminas\Db\ResultSet\ResultSet $rowset */
        $rowset = $this->tableGateway->select(['session_id' => $sessionId]);
        $row = $rowset->current();
        if (!$row) {
            return null;
        }
        /** @var PaymentSession $row */
        return $row;
    }

    public function saveSession(PaymentSession $session): void
    {
        $data = [
            'order_code' => $session->orderCode,
            'channel' => $session->channel,
            'amount' => $session->amount,
            'status' => $session->status,
            'target_id' => $session->targetId,
            'target_type' => $session->targetType,
            'transaction_id' => $session->transactionId,
            'expired_at' => $session->expiredAt,
        ];

        $id = $session->id;

        if ($id === 0) {
            $this->tableGateway->insert($data);
            $session->id = (int) $this->tableGateway->getLastInsertValue();
        } else {
            if (!$this->getSessionById($id)) {
                throw new RuntimeException(sprintf(
                    'Cannot update payment session with identifier %d; does not exist',
                    $id
                ));
            }
            $this->tableGateway->update($data, ['session_id' => $id]);
        }
    }

    public function getPendingSessionsByTarget(int $targetId, string $targetType = 'fine'): array
    {
        $rowset = $this->tableGateway->select([
            'target_id' => $targetId,
            'target_type' => $targetType,
            'status' => 'pending'
        ]);
        
        $sessions = [];
        foreach ($rowset as $row) {
            $sessions[] = $row;
        }
        return $sessions;
    }

    /**
     * Remove stale pending sessions that have been expired for more than 24 hours.
     * Call this before creating a new session to keep the table clean.
     */
    public function cleanupExpiredSessions(int $targetId, string $targetType = 'fine'): void
    {
        // Mark old pending sessions as expired if they're past their expired_at time
        $cutoff = date('Y-m-d H:i:s', time() - 86400); // 24h ago
        try {
            $this->tableGateway->delete(function ($where) use ($targetId, $targetType, $cutoff) {
                $where->equalTo('target_id', $targetId);
                $where->equalTo('target_type', $targetType);
                $where->equalTo('status', 'pending');
                $where->lessThan('expired_at', $cutoff);
            });
        } catch (\Throwable) {
            // Cleanup failure is non-critical; do not propagate
        }
    }
}
