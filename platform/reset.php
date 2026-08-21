<?php
session_start();

unset(
    $_SESSION['platform_login_attempts'],
    $_SESSION['platform_last_failed_login_at']
);

echo 'Platform login lock reset successfully.';