<?php

declare(strict_types=1);

namespace wise\notification\channel;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use wise\notification\contract\Notifiable;
use wise\notification\contract\Message;
use wise\notification\contract\SendResult;

/**
 * EmailChannel — 邮件通道驱动
 *
 * 通过 PHPMailer 发送 SMTP 邮件。
 * 配置来源：config('wise-notification.channels.email')。
 */
class EmailChannel implements ChannelDriver
{
    /**
     * 获取通道名称
     *
     * @return string
     */
    public function getName(): string
    {
        return 'email';
    }

    /**
     * 发送邮件通知
     *
     * @param Notifiable $notifiable 接收者
     * @param Message    $message    消息体
     * @return SendResult
     * @throws \RuntimeException 当 PHPMailer 未安装时
     */
    public function send(Notifiable $notifiable, Message $message): SendResult
    {
        // 检查 PHPMailer 是否已安装
        if (!class_exists(PHPMailer::class)) {
            throw new \RuntimeException(
                'PHPMailer 未安装。请执行: composer require phpmailer/phpmailer'
            );
        }

        $config = config('wise-notification.channels.email', []);
        $startTime = microtime(true);

        try {
            $mail = new PHPMailer(true);

            // 配置 SMTP
            $mail->isSMTP();
            $mail->Host       = $config['host'] ?? '';
            $mail->Port       = (int) ($config['port'] ?? 587);
            $mail->SMTPAuth   = true;
            $mail->Username   = $config['username'] ?? '';
            $mail->Password   = $config['password'] ?? '';
            $mail->SMTPSecure = $config['encryption'] ?? 'tls';

            // 字符集
            $mail->CharSet = PHPMailer::CHARSET_UTF8;

            // 发件人
            $fromAddress = $message->get('from.address', $config['from']['address'] ?? '');
            $fromName    = $message->get('from.name', $config['from']['name'] ?? '');
            if (!empty($fromAddress)) {
                $mail->setFrom($fromAddress, $fromName);
            }

            // 收件人
            $toAddress = $notifiable->routeNotificationFor('email');
            if (is_array($toAddress)) {
                foreach ($toAddress as $addr) {
                    $mail->addAddress($addr);
                }
            } else {
                $mail->addAddress($toAddress);
            }

            // 邮件内容
            $mail->isHTML(true);
            $mail->Subject = $message->title;
            $mail->Body    = $message->content;

            // 可选：纯文本备选
            if (!empty($message->get('alt_body'))) {
                $mail->AltBody = $message->get('alt_body');
            }

            // 发送
            $sent = $mail->send();
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            return new SendResult(
                success: true,
                channel: 'email',
                response: $sent ? 'Email sent successfully' : 'Email send returned false',
                durationMs: $durationMs,
            );
        } catch (PHPMailerException $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            return new SendResult(
                success: false,
                channel: 'email',
                error: $e->getMessage(),
                durationMs: $durationMs,
            );
        }
    }
}
