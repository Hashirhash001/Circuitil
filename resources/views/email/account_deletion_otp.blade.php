<!DOCTYPE html>
<html>
<head>
    <title>Account Deletion OTP</title>
</head>
<body>
    <p>Dear {{ auth()->user()->name }},</p>
    <p>You have requested to delete your account. Please use the following OTP to confirm your request:</p>
    <h2>{{ $otp }}</h2>
    <p>This OTP is valid for 10 minutes.</p>
    <p>If you did not request this, please ignore this email.</p>
    <p>Regards,<br>Circuitil Team</p>
</body>
</html>
