<?php
// helpers/Mailer.php

class Mailer
{

    /**
     * Send an HTML transactional email
     * 
     * @param string $toRecipient Recipient email address
     * @param string $subject Email subject line
     * @param string $htmlBody Rendered HTML content
     * @return bool True on success, false on failure
     */
    public static function send($toRecipient, $subject, $htmlBody)
    {
        $driver      = strtolower(getenv('MAIL_DRIVER') ?: 'log');
        $fromAddress = getenv('MAIL_FROM_ADDRESS') ?: 'noreply@travelagency.com';
        $fromName    = getenv('MAIL_FROM_NAME') ?: 'Travel Agency';

        // Development/Testing Fallback Mode (Log to disk without sending)
        if ($driver === 'log') {
            $logMessage = "[" . date('Y-m-d H:i:s') . "] TO: {$toRecipient} | SUBJECT: {$subject}\n{$htmlBody}\n---\n";
            error_log($logMessage, 3, __DIR__ . '/../logs/mail.log');
            return true;
        }

        try {
            if ($driver === 'sendgrid') {
                return self::sendViaSendGrid($toRecipient, $subject, $htmlBody, $fromAddress, $fromName);
            } elseif ($driver === 'mailgun') {
                return self::sendViaMailgun($toRecipient, $subject, $htmlBody, $fromAddress, $fromName);
            } else {
                error_log("Mailer Error: Unsupported MAIL_DRIVER '{$driver}'.");
                return false;
            }
        } catch (Exception $e) {
            error_log("Mailer Exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send email via SendGrid v3 Mail Send API
     */
    private static function sendViaSendGrid($to, $subject, $htmlContent, $fromEmail, $fromName)
    {
        $apiKey = getenv('SENDGRID_API_KEY');
        if (empty($apiKey)) {
            error_log("SendGrid Error: API Key missing in environment.");
            return false;
        }

        $apiUrl = 'https://api.sendgrid.com/v3/mail/send';

        $payload = [
            'personalizations' => [
                [
                    'to' => [['email' => $to]]
                ]
            ],
            'from' => [
                'email' => $fromEmail,
                'name'  => $fromName
            ],
            'subject' => $subject,
            'content' => [
                [
                    'type'  => 'text/html',
                    'value' => $htmlContent
                ]
            ]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // SendGrid returns HTTP 202 Accepted on success
        if ($httpCode === 202 || $httpCode === 200) {
            return true;
        }

        error_log("SendGrid API Error (HTTP {$httpCode}): " . $response);
        return false;
    }

    /**
     * Send email via Mailgun Messages API
     */
    private static function sendViaMailgun($to, $subject, $htmlContent, $fromEmail, $fromName)
    {
        $apiKey = getenv('MAILGUN_API_KEY');
        $domain = getenv('MAILGUN_DOMAIN');

        if (empty($apiKey) || empty($domain)) {
            error_log("Mailgun Error: Domain or API Key missing in environment.");
            return false;
        }

        $apiUrl = "https://api.mailgun.net/v3/{$domain}/messages";

        $postFields = [
            'from'    => "{$fromName} <{$fromEmail}>",
            'to'      => $to,
            'subject' => $subject,
            'html'    => $htmlContent
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        curl_setopt($ch, CURLOPT_USERPWD, 'api:' . $apiKey);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            return true;
        }

        error_log("Mailgun API Error (HTTP {$httpCode}): " . $response);
        return false;
    }

    /**
     * Render Template: Email Verification Link
     */
    public static function getVerificationTemplate($fullName, $verificationUrl)
    {
        $name = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
        $url  = htmlspecialchars($verificationUrl, ENT_QUOTES, 'UTF-8');

        return "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; background-color: #f4f6f8; color: #333; margin: 0; padding: 20px; }
                .container { max-width: 600px; margin: 0 auto; background: #ffffff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
                .btn { display: inline-block; padding: 12px 24px; background-color: #2563eb; color: #ffffff !important; text-decoration: none; border-radius: 6px; font-weight: bold; margin-top: 20px; }
                .footer { font-size: 12px; color: #6b7280; margin-top: 30px; border-top: 1px solid #e5e7eb; padding-top: 15px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <h2>Welcome to Travel Agency, {$name}!</h2>
                <p>Thank you for registering. Please confirm your email address by clicking the button below:</p>
                <a href='{$url}' class='btn' target='_blank'>Verify Email Address</a>
                <p style='margin-top: 25px;'>If the button above does not work, copy and paste this link into your browser:</p>
                <p><a href='{$url}'>{$url}</a></p>
                <p><strong>Note:</strong> This verification link will expire in 24 hours.</p>
                <div class='footer'>
                    <p>If you did not create an account, please ignore this email.</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }

    /**
     * Render Template: Booking & Receipt Confirmation
     */
    public static function getBookingReceiptTemplate($fullName, $bookingRef, $packageName, $totalAmount)
    {
        $name    = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
        $ref     = htmlspecialchars($bookingRef, ENT_QUOTES, 'UTF-8');
        $package = htmlspecialchars($packageName, ENT_QUOTES, 'UTF-8');
        $amount  = number_format((float)$totalAmount, 2);

        return "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; background-color: #f4f6f8; color: #333; margin: 0; padding: 20px; }
                .container { max-width: 600px; margin: 0 auto; background: #ffffff; padding: 30px; border-radius: 8px; }
                .receipt-box { background: #f8fafc; border: 1px solid #e2e8f0; padding: 15px; border-radius: 6px; margin: 20px 0; }
                .footer { font-size: 12px; color: #6b7280; margin-top: 30px; border-top: 1px solid #e5e7eb; padding-top: 15px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <h2>Booking Confirmed!</h2>
                <p>Hi {$name}, your payment has been processed and your booking is confirmed.</p>
                
                <div class='receipt-box'>
                    <p><strong>Booking Reference:</strong> {$ref}</p>
                    <p><strong>Package:</strong> {$package}</p>
                    <p><strong>Total Amount Paid:</strong> \${$amount}</p>
                    <p><strong>Status:</strong> Confirmed</p>
                </div>

                <p>We look forward to hosting you! You can view your full itinerary and itinerary details in your account dashboard.</p>
                <div class='footer'>
                    <p>Travel Agency Support Team</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }
}
