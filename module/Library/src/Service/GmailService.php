<?php

declare(strict_types=1);

namespace Library\Service;

use Library\Model\Table\PaymentSessionTable;
use Library\Model\Table\UserTable;
use Library\Model\Table\SystemSettingsTable;
use Library\Session\AuthSessionContainer;
use Library\Service\MailService;
use Laminas\Db\Adapter\AdapterInterface;
use Google\Client;
use Google\Service\Gmail;

class GmailService
{
    public function __construct(
        private PaymentSessionTable $paymentSessionTable,
        private UserTable $userTable,
        private SystemSettingsTable $systemSettingsTable,
        private AdapterInterface $db,
        private AuthSessionContainer $authSessionContainer,
        private ?MailService $mailService = null
    ) {
    }

    public function getClient(): ?Client
    {
        $clientId = getenv('GMAIL_CLIENT_ID') ?: $this->systemSettingsTable->getSetting('gmail_client_id');
        $clientSecret = getenv('GMAIL_CLIENT_SECRET') ?: $this->systemSettingsTable->getSetting('gmail_client_secret');
        $redirectUri = getenv('GMAIL_REDIRECT_URI') ?: $this->systemSettingsTable->getSetting('gmail_redirect_uri', 'urn:ietf:wg:oauth:2.0:oob');
        $refreshToken = getenv('GMAIL_REFRESH_TOKEN') ?: $this->systemSettingsTable->getSetting('gmail_refresh_token');

        if (!$clientId || !$clientSecret) {
            return null;
        }

        $client = new Client();
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri($redirectUri);
        $client->setAccessType('offline');
        $client->setPrompt('select_account consent');
        $client->setScopes([
            Gmail::GMAIL_MODIFY,
            Gmail::GMAIL_READONLY
        ]);

        // Try getting access token from setting
        $accessTokenJson = $this->systemSettingsTable->getSetting('gmail_access_token');
        if ($accessTokenJson) {
            try {
                $accessToken = json_decode($accessTokenJson, true);
                $client->setAccessToken($accessToken);
            } catch (\Throwable) {
                // Ignore corrupt token
            }
        }

        if ($client->isAccessTokenExpired()) {
            if ($refreshToken) {
                try {
                    $newToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);
                    if (isset($newToken['access_token'])) {
                        $this->systemSettingsTable->saveSetting('gmail_access_token', json_encode($client->getAccessToken()));
                    }
                } catch (\Throwable $e) {
                    return null;
                }
            } else {
                return null;
            }
        }

        return $client;
    }

    public function getAuthUrl(): string
    {
        $clientId = getenv('GMAIL_CLIENT_ID') ?: $this->systemSettingsTable->getSetting('gmail_client_id');
        $clientSecret = getenv('GMAIL_CLIENT_SECRET') ?: $this->systemSettingsTable->getSetting('gmail_client_secret');
        $redirectUri = getenv('GMAIL_REDIRECT_URI') ?: $this->systemSettingsTable->getSetting('gmail_redirect_uri', 'urn:ietf:wg:oauth:2.0:oob');

        $client = new Client();
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri($redirectUri);
        $client->setAccessType('offline');
        $client->setPrompt('select_account consent');
        $client->setScopes([
            Gmail::GMAIL_MODIFY,
            Gmail::GMAIL_READONLY
        ]);

        return $client->createAuthUrl();
    }

    public function authenticateCode(string $code): array
    {
        $clientId = getenv('GMAIL_CLIENT_ID') ?: $this->systemSettingsTable->getSetting('gmail_client_id');
        $clientSecret = getenv('GMAIL_CLIENT_SECRET') ?: $this->systemSettingsTable->getSetting('gmail_client_secret');
        $redirectUri = getenv('GMAIL_REDIRECT_URI') ?: $this->systemSettingsTable->getSetting('gmail_redirect_uri', 'urn:ietf:wg:oauth:2.0:oob');

        $client = new Client();
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri($redirectUri);

        $token = $client->fetchAccessTokenWithAuthCode($code);
        if (isset($token['refresh_token'])) {
            $this->systemSettingsTable->saveSetting('gmail_refresh_token', $token['refresh_token']);
        }
        if (isset($token['access_token'])) {
            $this->systemSettingsTable->saveSetting('gmail_access_token', json_encode($token));
        }

        return $token;
    }

    public function registerWatch(): ?array
    {
        $client = $this->getClient();
        if (!$client) {
            return null;
        }

        $gmail = new Gmail($client);
        $topicName = getenv('GMAIL_PUBSUB_TOPIC') ?: $this->systemSettingsTable->getSetting('gmail_pubsub_topic');
        if (!$topicName) {
            return null;
        }

        $watchRequest = new \Google\Service\Gmail\WatchRequest();
        $watchRequest->setTopicName($topicName);
        $watchRequest->setLabelIds(['UNREAD']);

        try {
            $watchResponse = $gmail->users->watch('me', $watchRequest);
            $historyId = $watchResponse->getHistoryId();
            $expiration = $watchResponse->getExpiration(); // Timestamp in ms
            
            // Save last watch run time and expiration
            $this->systemSettingsTable->saveSetting('gmail_watch_last_run', (string)time());
            $this->systemSettingsTable->saveSetting('gmail_watch_expiration', (string)$expiration);
            
            return [
                'historyId' => $historyId,
                'expiration' => $expiration
            ];
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    /**
     * Fetch unread emails, parse them, match with payment sessions, and process success.
     * Returns an array of processed orderCodes.
     */
    public function processEmails(): array
    {
        $client = $this->getClient();
        if (!$client) {
            return [];
        }

        $gmail = new Gmail($client);
        $processedCodes = [];

        try {
            // Find unread emails
            $response = $gmail->users_messages->listUsersMessages('me', [
                'q' => 'is:unread'
            ]);

            $messages = $response->getMessages();
            if (empty($messages)) {
                return [];
            }

            foreach ($messages as $msgSummary) {
                $msgId = $msgSummary->getId();
                $msg = $gmail->users_messages->get('me', $msgId, ['format' => 'full']);
                
                // Parse email body
                $body = $this->getEmailBody($msg);
                if (empty($body)) {
                    continue;
                }

                // Match orderCode and process
                $orderCode = $this->parseOrderCode($body);
                if ($orderCode) {
                    $session = $this->paymentSessionTable->getSession($orderCode);
                    if ($session && $session->status === 'pending') {
                        // Check if amount matches
                        if ($this->verifyAmount($body, (float)$session->amount)) {
                            // Verify expiration
                            $expiredAt = strtotime($session->expiredAt);
                            if ($expiredAt > time()) {
                                // Update status to paid
                                $session->status = 'paid';
                                $session->transactionId = $msgId;
                                $this->paymentSessionTable->saveSession($session);

                                // Perform unlock and business logic
                                $this->handlePaymentSuccess((int)$session->targetId, $msgId);
                                $processedCodes[] = $orderCode;
                            }
                        }
                    }
                }

                // Mark as read by removing UNREAD label
                $mods = new \Google\Service\Gmail\ModifyMessageRequest();
                $mods->setRemoveLabelIds(['UNREAD']);
                $gmail->users_messages->modify('me', $msgId, $mods);
            }
        } catch (\Throwable $e) {
            // Log error or ignore
        }

        return $processedCodes;
    }

    /**
     * Directly simulate webhook parsing with text content (for the local Webhook Simulator Panel)
     */
    public function simulateWebhook(string $emailContent): array
    {
        $processedCodes = [];
        $orderCode = $this->parseOrderCode($emailContent);
        if ($orderCode) {
            $session = $this->paymentSessionTable->getSession($orderCode);
            if ($session && $session->status === 'pending') {
                if ($this->verifyAmount($emailContent, (float)$session->amount)) {
                    $expiredAt = strtotime($session->expiredAt);
                    if ($expiredAt > time()) {
                        $msgId = 'MOCK_TXT_' . uniqid();
                        $session->status = 'paid';
                        $session->transactionId = $msgId;
                        $this->paymentSessionTable->saveSession($session);

                        $this->handlePaymentSuccess((int)$session->targetId, $msgId);
                        $processedCodes[] = $orderCode;
                    }
                }
            }
        }
        return $processedCodes;
    }

    private function getEmailBody(\Google\Service\Gmail\Message $message): string
    {
        $payload = $message->getPayload();
        if (!$payload) {
            return '';
        }

        $body = '';
        $parts = $payload->getParts();

        if (empty($parts)) {
            $bodyData = $payload->getBody()->getData();
            if ($bodyData) {
                $body = $this->base64urlDecode($bodyData);
            }
        } else {
            $body = $this->parseParts($parts);
        }

        return strip_tags($body);
    }

    private function parseParts($parts): string
    {
        $body = '';
        foreach ($parts as $part) {
            if ($part->getMimeType() === 'text/plain') {
                $bodyData = $part->getBody()->getData();
                if ($bodyData) {
                    $body .= $this->base64urlDecode($bodyData);
                }
            } elseif ($part->getMimeType() === 'text/html') {
                $bodyData = $part->getBody()->getData();
                if ($bodyData) {
                    $body .= $this->base64urlDecode($bodyData);
                }
            }
            
            $subParts = $part->getParts();
            if (!empty($subParts)) {
                $body .= $this->parseParts($subParts);
            }
        }
        return $body;
    }

    private function base64urlDecode(string $data): string
    {
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
    }

    private function parseOrderCode(string $body): ?string
    {
        if (preg_match('/HDPE(?:_FINE_)?\d+[A-Za-z0-9_]*/i', $body, $matches)) {
            return $matches[0];
        }
        return null;
    }

    private function verifyAmount(string $body, float $amount): bool
    {
        $intAmount = (int) $amount;
        $variations = [
            (string) $intAmount,
            number_format($amount, 0, '.', '.'), // 150.000
            number_format($amount, 0, ',', ','), // 150,000
        ];

        foreach ($variations as $var) {
            if (strpos($body, $var) !== false) {
                return true;
            }
        }

        return false;
    }

    public function handlePaymentSuccess(int $fineId, string $transactionId): void
    {
        $connection = $this->db->getDriver()->getConnection();
        $connection->beginTransaction();

        try {
            // 1. Fetch fine details to get user_id
            $fineSql = "SELECT * FROM fines WHERE fine_id = ? LIMIT 1";
            $fine = $this->db->query($fineSql)->execute([$fineId])->current();
            if (!$fine) {
                throw new \RuntimeException(sprintf('Fine not found: %d', $fineId));
            }

            $userId = (int)$fine['user_id'];

            // 2. Update fine status to paid
            $updateSql = "UPDATE fines SET status = 'paid', paid_at = NOW() WHERE fine_id = ?";
            $this->db->query($updateSql)->execute([$fineId]);

            // 3. Check if student still has any other unpaid fines
            $checkSql = "SELECT COUNT(*) AS cnt FROM fines WHERE user_id = ? AND status = 'unpaid'";
            $checkStmt = $this->db->createStatement($checkSql);
            $checkRes = $checkStmt->execute([$userId])->current();
            $unpaidCount = (int)($checkRes['cnt'] ?? 0);

            // 7. Notify about fine payment (always, even if still locked due to other fines)
            $paymentNotiMsg = "Khoản phạt #" . $fineId . " (" . number_format((float)($fine['amount'] ?? 0), 0, ',', '.') . " VNĐ) của bạn đã được thanh toán thành công qua hệ thống VietQR.";
            $paymentNotiSql = "INSERT INTO notifications (user_id, sender_id, title, message, type, related_id, created_at)
                               VALUES (?, 0, 'Thanh toán khoản phạt thành công', ?, 'system', ?, NOW())";
            try {
                $this->db->query($paymentNotiSql)->execute([$userId, $paymentNotiMsg, $fineId]);
            } catch (\Throwable) {}

            if ($unpaidCount === 0) {
                // 4. Unlock student account in database
                $this->userTable->unlockUser($userId);

                // 5. Restore standard borrow limit to 5
                $userObj = $this->userTable->getUser($userId);
                if ($userObj) {
                    $userObj->borrowLimit = 5;
                    $this->userTable->saveUser($userObj);
                }

                // 6. Update logged-in session user status to reflect active immediately
                $session = $this->authSessionContainer;
                if (isset($session->user) && is_array($session->user) && (int)($session->user['id'] ?? 0) === $userId) {
                    $session->user['account_status'] = 'active';
                    $session->user['locked_until'] = '';
                }

                // 8. Notification: account unlocked
                $notifySql = "INSERT INTO notifications (user_id, sender_id, title, message, type, related_id, created_at)
                              VALUES (?, 0, 'Tài khoản đã mở khóa', ?, 'system', ?, NOW())";
                $notifyMessage = "Tài khoản của bạn đã được mở khóa và khôi phục hạn mức mượn 5 cuốn sau khi hoàn tất nộp phạt.";
                try {
                    $this->db->query($notifySql)->execute([$userId, $notifyMessage, $fineId]);
                } catch (\Throwable) {}

                // 9. Send email: account unlocked
                if ($this->mailService !== null) {
                    $userObj = $userObj ?? $this->userTable->getUser($userId);
                    if ($userObj) {
                        try {
                            $emailSubject = "[Thư viện HDPE] Thanh toán phạt & Mở khóa tài khoản thành công";
                            $emailBody = "Chào " . $userObj->fullName . ",\n\n"
                                       . "Chúng tôi xác nhận tài khoản của bạn đã hoàn tất thanh toán khoản phạt #" . $fineId . ".\n"
                                       . "Số tiền: " . number_format((float)($fine['amount'] ?? 0), 0, ',', '.') . " VNĐ.\n\n"
                                       . "Tài khoản thư viện của bạn đã được mở khóa và hạn mức mượn được khôi phục về 5 cuốn.\n"
                                       . "Bạn có thể tiếp tục đăng nhập và sử dụng các dịch vụ của thư viện bình thường.\n\n"
                                       . "Trân trọng,\n"
                                       . "Thư viện HDPE";
                            $this->mailService->sendEmail($userObj->email, $userObj->fullName, $emailSubject, $emailBody);
                        } catch (\Throwable) {
                            // Email failure is non-critical, do not rollback
                        }
                    }
                }
            } else {
                // Still has unpaid fines — send email about this payment only
                if ($this->mailService !== null) {
                    $userObjForMail = $this->userTable->getUser($userId);
                    if ($userObjForMail) {
                        try {
                            $emailSubject = "[Thư viện HDPE] Xác nhận thanh toán khoản phạt #" . $fineId;
                            $emailBody = "Chào " . $userObjForMail->fullName . ",\n\n"
                                       . "Chúng tôi xác nhận đã nhận được thanh toán khoản phạt #" . $fineId . ".\n"
                                       . "Số tiền: " . number_format((float)($fine['amount'] ?? 0), 0, ',', '.') . " VNĐ.\n\n"
                                       . "Tuy nhiên, tài khoản của bạn vẫn đang bị tạm khóa vì còn " . $unpaidCount . " khoản phạt chưa được thanh toán.\n"
                                       . "Vui lòng đăng nhập để xem và thanh toán các khoản phạt còn lại.\n\n"
                                       . "Trân trọng,\n"
                                       . "Thư viện HDPE";
                            $this->mailService->sendEmail($userObjForMail->email, $userObjForMail->fullName, $emailSubject, $emailBody);
                        } catch (\Throwable) {
                            // Email failure is non-critical
                        }
                    }
                }
            }

            $connection->commit();
        } catch (\Throwable $e) {
            try {
                $connection->rollback();
            } catch (\Throwable) {}
            throw $e;
        }
    }
}
