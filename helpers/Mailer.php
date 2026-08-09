<?php
// helpers/Mailer.php

use Mailgun\Mailgun;

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
        $driver      = strtolower(getenv('MAIL_DRIVER'));
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

        $plainText = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlContent));

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
                    'type'  => 'text/plain',
                    'value' => $plainText
                ],
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
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            error_log("SendGrid cURL Error: " . $curlError);
            return false;
        }

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

        // Standard US region endpoint; use https://api.eu.mailgun.net/v3/{$domain}/messages for EU domains
        $apiUrl = "https://api.mailgun.net/v3/{$domain}/messages";

        // Pass the raw array directly (DO NOT use http_build_query)
        $postFields = [
            'from'    => "{$fromName} <{$fromEmail}>",
            'to'      => $to,
            'subject' => $subject,
            'html'    => $htmlContent
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields); // Sends multipart/form-data
        curl_setopt($ch, CURLOPT_USERPWD, 'api:' . $apiKey);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            error_log("Mailgun cURL Network Error: " . $curlError);
            return false;
        }

        if ($httpCode === 200) {
            return true;
        }

        error_log("Mailgun API Error (HTTP {$httpCode}): " . $response);
        return false;
    }

    /**
     * Generates a responsive HTML template for OTP verification emails.
     *
     * @param string $fullName The recipient's full name.
     * @param string $otp      The 6-digit verification code.
     * @return string          Rendered HTML body.
     */
    public static function getOtpVerificationTemplate(string $fullName, string $otp): string
    {
        $safeName = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
        $safeOtp  = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');

        // Formatted version with letter-spacing spacing out digits
        $formattedOtp = implode('&nbsp;&nbsp;', str_split($safeOtp));

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Encoding" content="IE=edge">
    <title>Your Verification Code</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f6f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased;">
    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="table-layout: fixed;">
        <tr>
            <td align="center" style="padding: 40px 10px; background-color: #f4f6f9;">
                
                <!-- Main Card Container -->
                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 520px; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05); border: 1px solid #e5e7eb;">
                    
                    <!-- Header Bar -->
                    <tr>
                        <td align="center" style="padding: 32px 32px 16px 32px; background-color: #ffffff; border-bottom: 1px solid #f3f4f6;">
                            <h1 style="margin: 0; font-size: 22px; font-weight: 700; color: #111827; letter-spacing: -0.5px;">
                                Travel Agency
                            </h1>
                        </td>
                    </tr>

                    <!-- Body Content -->
                    <tr>
                        <td style="padding: 32px; text-align: left;">
                            <h2 style="margin: 0 0 12px 0; font-size: 18px; font-weight: 600; color: #111827;">
                                Verify Your Email Address
                            </h2>
                            <p style="margin: 0 0 24px 0; font-size: 15px; line-height: 1.5; color: #4b5563;">
                                Hi {$safeName},
                            </p>
                            <p style="margin: 0 0 28px 0; font-size: 15px; line-height: 1.5; color: #4b5563;">
                                Thank you for registering. Please use the verification code below to complete your registration. This code will expire in <strong>10 minutes</strong>.
                            </p>

                            <!-- OTP Box -->
                            <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                <tr>
                                    <td align="center" style="padding: 20px; background-color: #f8fafc; border-radius: 8px; border: 1px dashed #cbd5e1;">
                                        <span style="font-family: 'Courier New', Courier, monospace; font-size: 32px; font-weight: 700; color: #2563eb; letter-spacing: 6px; display: inline-block;">
                                            {$safeOtp}
                                        </span>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin: 28px 0 0 0; font-size: 13px; line-height: 1.5; color: #6b7280;">
                                If you did not create an account or request this code, you can safely ignore this email.
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td align="center" style="padding: 20px 32px; background-color: #f9fafb; border-top: 1px solid #f3f4f6;">
                            <p style="margin: 0; font-size: 12px; color: #9ca3af; line-height: 1.4;">
                                &copy; " . date('Y') . " Travel Agency. All rights reserved.<br>
                                This is an automated message, please do not reply to this email.
                            </p>
                        </td>
                    </tr>

                </table>
                <!-- End Main Card -->

            </td>
        </tr>
    </table>
</body>
</html>
HTML;
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
