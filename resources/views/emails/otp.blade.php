<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>OTP Verification</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f7; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color:#333;">

    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
        <tr>
            <td align="center" style="padding: 40px 10px;">
                <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width:600px; background-color:#ffffff; border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,0.1);">
                    <tr>
                        <td align="center" style="padding: 30px 40px;">

                            <!-- Logo (optional) -->
                            <h2 style="margin:0; color:#4a90e2;">CoiioT</h2>
                            <p style="margin: 8px 0 20px; font-size:16px; color:#555;">Secure OTP Verification</p>

                            <p style="margin:0; font-size:14px; text-align:left;">
                                Dear User,
                            </p>

                            <p style="font-size:14px; text-align:left;">
                                Your confidential OTP to access your account is:
                            </p>

                            <p style="background-color:#4adde2; color:#ffffff; padding:12px 20px; border-radius:6px; display:inline-block; font-size:20px; letter-spacing:2px; margin: 20px 0;">
                                {{ $otp }}
                            </p>

                            <p style="font-size:13px; text-align:left; color:#777;">
                                The OTP is valid for 10 minutes only. If you didn’t request this OTP, please ignore this email.
                            </p>

                            <p style="margin-top:30px; font-size:13px; text-align:left;">
                                Regards,<br>
                                <strong>CoiioT Administrator</strong>
                            </p>

                            <p style="margin-top:20px; font-size:11px; color:#aaa; text-align:left;">
                                Note: This is an automated message, please do not reply.
                            </p>

                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 20px 40px; border-top:1px solid #e4e4e4;">
                            <p style="font-size:10px; color: #999; line-height:1.5; text-align:left;">
                                Notice: The information contained in this e-mail and/or attachments may be confidential or privileged.
                                If you are not the intended recipient, any review, use, distribution, or copying is strictly prohibited.
                                If you received this email in error, please notify us immediately and permanently delete it.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

</body>
</html>
