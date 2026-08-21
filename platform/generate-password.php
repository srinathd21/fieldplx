<?php

$password = 'Ari@2003';

echo password_hash(
    $password,
    PASSWORD_DEFAULT
);