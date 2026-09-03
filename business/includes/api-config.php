<?php

declare(strict_types=1);

/**
 * FieldPlx Mobile API Configuration
 *
 * Recommended location:
 *   /includes/api-config.php
 *
 * IMPORTANT:
 * - Keep this file on the server only.
 * - Never send FIELDPLX_API_SECRET to the mobile application.
 * - Do not commit this file to a public Git repository.
 */

define(
    'FIELDPLX_API_SECRET',
    '29e8057628dde3cd19d3b8588d9c14c9df1733d467f0e9a0930036d22f37568529d850f59fc73704941ad3d907b566d7ee6b97b467c7babf89563e55aea939d8'
);

define(
    'FIELDPLX_ACCESS_TOKEN_TTL',
    60 * 60 * 24 * 30
);

define(
    'FIELDPLX_TOKEN_ISSUER',
    'FieldPlx'
);

define(
    'FIELDPLX_TOKEN_AUDIENCE',
    'FieldPlx-Mobile'
);
