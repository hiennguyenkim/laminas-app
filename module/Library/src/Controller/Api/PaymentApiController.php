<?php

declare(strict_types=1);

namespace Library\Controller\Api;

use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Library\Model\Entity\PaymentSession;
use Library\Model\Table\PaymentSessionTable;
use Library\Service\GmailService;
use Library\Model\Table\SystemSettingsTable;
use Laminas\Db\Adapter\AdapterInterface;

class PaymentApiController extends AbstractActionController
{
    public function __construct(
        private PaymentSessionTable $paymentSessionTable,
        private GmailService $gmailService,
        private SystemSettingsTable $systemSettingsTable,
        private AdapterInterface $db
    ) {
    }

    /**
     * POST /api/payment/create-session
     */
    public function createSessionAction(): Response
    {
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return $this->jsonResponse(['error' => 'Method not allowed'], 405);
        }

        $data = $this->requestJsonBody();
        $fineId = (int) ($data['fine_id'] ?? 0);
        $channel = (string) ($data['channel'] ?? 'momo');

        if ($fineId <= 0) {
            return $this->jsonResponse(['error' => 'Invalid fine ID'], 400);
        }

        if (!in_array($channel, ['momo', 'vnpay', 'paypal'])) {
            $channel = 'momo';
        }

        // Fetch the fine
        $sql = "SELECT * FROM fines WHERE fine_id = ? LIMIT 1";
        $fine = $this->db->query($sql)->execute([$fineId])->current();

        if (!$fine) {
            return $this->jsonResponse(['error' => 'Fine not found'], 404);
        }

        if ($fine['status'] === 'paid') {
            return $this->jsonResponse(['error' => 'Fine has already been paid'], 400);
        }

        $amount = (float)$fine['amount'];
        
        // Clean up expired sessions older than 24h before creating/reusing
        $this->paymentSessionTable->cleanupExpiredSessions($fineId, 'fine');

        // Check if there is already a pending session for this fine
        $existing = $this->paymentSessionTable->getPendingSessionsByTarget($fineId, 'fine');
        if (!empty($existing)) {
            // Check if any is not expired
            foreach ($existing as $session) {
                $expiredAtTs = strtotime($session->expiredAt);
                if ($expiredAtTs > time() && $session->channel === $channel) {
                    return $this->jsonResponse([
                        'status' => 'success',
                        'orderCode' => $session->orderCode,
                        'amount' => $session->amount,
                        'expiredAt' => $session->expiredAt,
                        'expiredAtMs' => $expiredAtTs * 1000  // Unix ms for JS - timezone-safe
                    ]);
                }
            }
        }

        // Create new session
        $randomBytes = bin2hex(random_bytes(4));
        $orderCode = 'HDPE' . $fineId . 'X' . strtoupper($randomBytes);

        $session = new PaymentSession();
        $session->orderCode = $orderCode;
        $session->channel = $channel;
        $session->amount = $amount;
        $session->status = 'pending';
        $session->targetId = $fineId;
        $session->targetType = 'fine';
        $expireTimestamp = time() + 900; // 15 minutes from now
        $session->expiredAt = date('Y-m-d H:i:s', $expireTimestamp);

        $this->paymentSessionTable->saveSession($session);

        return $this->jsonResponse([
            'status' => 'success',
            'orderCode' => $orderCode,
            'amount' => $amount,
            'expiredAt' => $session->expiredAt,
            'expiredAtMs' => $expireTimestamp * 1000  // Unix ms for JS - timezone-safe
        ]);
    }

    /**
     * GET /api/payment/check-status
     */
    public function checkStatusAction(): Response
    {
        $request = $this->getRequest();
        $orderCode = (string) $request->getQuery('orderCode', '');

        if (empty($orderCode)) {
            return $this->jsonResponse(['error' => 'Missing orderCode parameter'], 400);
        }

        $session = $this->paymentSessionTable->getSession($orderCode);
        if (!$session) {
            return $this->jsonResponse(['error' => 'Payment session not found'], 404);
        }

        // If pending, check expiration
        if ($session->status === 'pending') {
            if (strtotime($session->expiredAt) <= time()) {
                $session->status = 'expired';
                $this->paymentSessionTable->saveSession($session);
            }
        }

        return $this->jsonResponse([
            'status' => 'success',
            'paymentStatus' => $session->status
        ]);
    }

    /**
     * POST /api/payment/gmail-webhook
     */
    public function gmailWebhookAction(): Response
    {
        $request = $this->getRequest();
        
        // Token verification to prevent abuse
        $token = (string) $request->getQuery('token', '');
        $expectedToken = getenv('GMAIL_WEBHOOK_TOKEN') ?: $this->systemSettingsTable->getSetting('gmail_webhook_token');

        // Accept request if token is correct or if it is a local simulator payload
        $isSimulator = false;
        $body = $this->requestJsonBody();
        if (isset($body['simulate_email_body'])) {
            $isSimulator = true;
        }

        if (!$isSimulator && !empty($expectedToken) && $token !== $expectedToken) {
            return $this->jsonResponse(['error' => 'Unauthorized'], 200); // Always return 200 per requirements
        }

        if ($isSimulator) {
            // Run local simulation
            $emailContent = (string) ($body['simulate_email_body'] ?? '');
            $processed = $this->gmailService->simulateWebhook($emailContent);
            return $this->jsonResponse([
                'status' => 'success',
                'source' => 'simulator',
                'processedCodes' => $processed
            ]);
        }

        // Real webhook triggered by Google Pub/Sub watch
        // We trigger fetching unread emails from Gmail
        $processed = $this->gmailService->processEmails();

        return $this->jsonResponse([
            'status' => 'success',
            'source' => 'pubsub',
            'processedCodes' => $processed
        ]);
    }

    private function requestJsonBody(): array
    {
        $content = $this->getRequest()->getContent();
        if (!is_string($content) || $content === '') {
            // Fallback to standard POST array if content is form-encoded
            $post = $this->getRequest()->getPost();
            if (is_object($post) && method_exists($post, 'toArray')) {
                return $post->toArray();
            }
            return [];
        }

        $payload = json_decode($content, true);
        if (!is_array($payload)) {
            return [];
        }

        return $payload;
    }

    private function jsonResponse(array $data, int $statusCode = 200): Response
    {
        $response = $this->getResponse();
        if (!$response instanceof Response) {
            throw new \RuntimeException('Unexpected response instance.');
        }

        $response->setStatusCode($statusCode);
        $response->setContent((string) json_encode($data, JSON_UNESCAPED_UNICODE));
        $response->getHeaders()->addHeaderLine('Content-Type', 'application/json');
        return $response;
    }
}
