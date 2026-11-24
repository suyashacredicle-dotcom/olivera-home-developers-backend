
<?php

use PHPMailer\PHPMailer\PHPMailer;

// --------------------------------------------------
// LOAD PHPMailer
// --------------------------------------------------

require_once __DIR__ . "/../vendor/autoload.php";

// --------------------------------------------------
// GMAIL SMTP SETTINGS
// --------------------------------------------------

// Enter your Gmail address privately.
define("MAIL_USERNAME", "suyasha.credicle@gmail.com");

// Enter your NEW Google App Password privately.
// Do not share this password.
// Remove spaces if Google displays the password in groups.
define("CredicleTech@2", "YOUR_GOOGLE_APP_PASSWORD");

// --------------------------------------------------
// SEND PASSWORD RESET EMAIL
// --------------------------------------------------

function sendPasswordResetEmail($toEmail, $toName, $resetLink)
{
    try {

        // Validate recipient email.
        if (
            !is_string($toEmail) ||
            !filter_var($toEmail, FILTER_VALIDATE_EMAIL)
        ) {
            throw new InvalidArgumentException(
                "Invalid recipient email address."
            );
        }

        // Validate reset URL.
        if (
            !is_string($resetLink) ||
            !filter_var($resetLink, FILTER_VALIDATE_URL)
        ) {
            throw new InvalidArgumentException(
                "Invalid password reset URL."
            );
        }

        $scheme = parse_url($resetLink, PHP_URL_SCHEME);

        if (!in_array($scheme, ["http", "https"], true)) {
            throw new InvalidArgumentException(
                "Invalid password reset URL scheme."
            );
        }

        // Validate sender credentials.
        if (
            !filter_var(MAIL_USERNAME, FILTER_VALIDATE_EMAIL) ||
            trim(MAIL_PASSWORD) === ""
        ) {
            throw new RuntimeException(
                "Email configuration is incomplete."
            );
        }

        // Safely prepare recipient name.
        $toName = is_string($toName) ? $toName : "";

        $toName = preg_replace(
            '/[\r\n\x00-\x1F\x7F]/',
            '',
            $toName
        );

        $safeName = htmlspecialchars(
            $toName,
            ENT_QUOTES | ENT_SUBSTITUTE,
            "UTF-8"
        );

        $safeLink = htmlspecialchars(
            $resetLink,
            ENT_QUOTES | ENT_SUBSTITUTE,
            "UTF-8"
        );

        $mail = new PHPMailer(true);

        // --------------------------------------------------
        // SMTP CONFIGURATION
        // --------------------------------------------------

        $mail->isSMTP();

        $mail->Host = "smtp.gmail.com";

        $mail->SMTPAuth = true;

        $mail->Username = MAIL_USERNAME;

        $mail->Password = str_replace(
            " ",
            "",
            MAIL_PASSWORD
        );

        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;

        $mail->Port = 587;

        $mail->CharSet = "UTF-8";

        $mail->Encoding = "base64";

        $mail->Timeout = 15;

        $mail->Timelimit = 30;

        $mail->SMTPDebug = 0;

        // --------------------------------------------------
        // SENDER AND RECIPIENT
        // --------------------------------------------------

        $mail->setFrom(
            MAIL_USERNAME,
            "My Website"
        );

        $mail->addAddress(
            $toEmail,
            $toName
        );

        // --------------------------------------------------
        // EMAIL CONTENT
        // --------------------------------------------------

        $mail->isHTML(true);

        $mail->Subject = "Reset Your Password";

        $mail->Body = "
            <div style='font-family:Arial,sans-serif;
                        max-width:600px;
                        margin:auto;
                        padding:25px;
                        border:1px solid #ddd;
                        border-radius:8px;'>

                <h2>Password Reset Request</h2>

                <p>Hello {$safeName},</p>

                <p>
                    We received a request to reset your
                    My Website account password.
                </p>

                <p>
                    Click the button below to create
                    a new password.
                </p>

                <p style='margin:30px 0;'>

                    <a href='{$safeLink}'
                       style='background:#1e40af;
                              color:white;
                              padding:12px 22px;
                              text-decoration:none;
                              border-radius:5px;'>
                        Reset Password
                    </a>

                </p>

                <p>
                    This link is valid for a limited time
                    and can only be used once.
                </p>

                <p>
                    If you did not request this,
                    you can ignore this email.
                </p>

                <p>
                    Regards,<br>
                    My Website Team
                </p>

            </div>
        ";

        $mail->AltBody =
            "Hello {$toName},\n\n" .
            "Use this link to reset your password:\n" .
            $resetLink .
            "\n\n" .
            "If you did not request this, ignore this email.";

        // --------------------------------------------------
        // SEND EMAIL
        // --------------------------------------------------

        $mail->send();

        return true;

    } catch (\Throwable $e) {

        error_log(
            "Password reset email error: " .
            $e->getMessage()
        );

        return false;
    }
}
