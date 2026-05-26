<?php

declare(strict_types=1);

namespace Library\Service;

use Laminas\Db\Adapter\AdapterInterface;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

class MailService
{
    private AdapterInterface $adapter;

    public function __construct(AdapterInterface $adapter)
    {
        $this->adapter = $adapter;
    }

    private function getSetting(string $key): string
    {
        $sql = "SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1";
        $row = $this->adapter->query($sql)->execute([$key])->current();
        return (string)($row['setting_value'] ?? '');
    }

    public function sendEmail(string $toEmail, string $toName, string $subject, string $body): bool
    {
        $host = $this->getSetting('smtp_host');
        $port = (int)$this->getSetting('smtp_port');
        $user = $this->getSetting('smtp_user');
        $pass = $this->getSetting('smtp_pass');
        $fromEmail = $this->getSetting('smtp_from_email') ?: $user;
        $fromName = $this->getSetting('smtp_from_name') ?: 'Thư Viện HDPE';

        if (empty($user) || empty($pass)) {
            throw new \RuntimeException("Cấu hình SMTP chưa hoàn tất. Vui lòng vào Cài đặt hệ thống để cập nhật Email và Mật khẩu ứng dụng.");
        }

        $mail = new PHPMailer(true);

        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = $host;
            $mail->SMTPAuth   = true;
            $mail->Username   = $user;
            $mail->Password   = $pass;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $port ?: 587;
            $mail->CharSet    = 'UTF-8';

            // XAMPP local testing bypass for SSL
            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );

            // Recipients
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($toEmail, $toName);

            // Content
            $mail->isHTML(false); // Plain text for simplicity, can change to true later
            $mail->Subject = $subject;
            $mail->Body    = $body;

            $mail->send();
            return true;
        } catch (PHPMailerException $e) {
            throw new \RuntimeException("Không thể gửi thư. Lỗi Mailer: {$mail->ErrorInfo}");
        }
    }
}
