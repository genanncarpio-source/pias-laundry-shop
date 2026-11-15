<?php

function send_transactional_email(string $email, string $subject, string $text): void
{
    $apiKey = trim((string)getenv('BREVO_API_KEY'));
    $from = trim((string)getenv('EMAIL_FROM'));
    $fromName = trim((string)(getenv('EMAIL_FROM_NAME') ?: "Pia's Laundry Shop"));

    if ($apiKey === '' || $from === '') {
        throw new RuntimeException('Email delivery is not configured. Add BREVO_API_KEY and EMAIL_FROM.');
    }

    $payload = json_encode([
        'sender' => ['name' => $fromName, 'email' => $from],
        'to' => [['email' => $email]],
        'subject' => $subject,
        'textContent' => $text,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => 'api-key: ' . $apiKey . "\r\n"
            . 'Accept: application/json' . "\r\n"
            . 'Content-Type: application/json' . "\r\n",
        'content' => $payload,
        'timeout' => 7,
        'ignore_errors' => true,
    ]]);
    $response = @file_get_contents('https://api.brevo.com/v3/smtp/email', false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) $status = (int)$matches[1];
    }
    if ($status >= 200 && $status < 300) return;

    $provider = is_string($response) ? json_decode($response, true) : null;
    error_log('Brevo email request failed with HTTP ' . $status . ' and code ' . strtolower((string)($provider['code'] ?? '')) . '.');
    throw new RuntimeException('Brevo could not send the email. Check the email settings and verified sender.');
}

function send_staff_password_reset_otp(string $email, string $name, string $code): void
{
    send_transactional_email(
        $email,
        "Reset your Pia's Laundry Shop staff password",
        "Hello {$name},\n\nYour staff/admin password reset code is: {$code}\n\nThis code expires in 10 minutes and can only be used once. If you did not request a reset, you can ignore this email."
    );
}

function send_driver_password_reset_otp(string $email, string $name, string $code): void
{
    send_transactional_email(
        $email,
        "Reset your Pia's Laundry Shop driver password",
        "Hello {$name},`n`nYour driver app password reset code is: {$code}`n`nThis code expires in 10 minutes and can only be used once. If you did not request a reset, you can ignore this email."
    );
}