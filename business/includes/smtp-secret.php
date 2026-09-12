<?php
/* FieldPlx permanent SMTP credential encryption key.
 * IMPORTANT: deploy this SAME file/value to localhost and live server.
 * Keep it outside public downloads and do not rotate it after SMTP passwords are saved.
 */
if (!defined('FIELDPLX_SMTP_ENCRYPTION_KEY')) {
    define('FIELDPLX_SMTP_ENCRYPTION_KEY', '4aec935c6587acad807a66de2c03aee10f68dfd67532847476d6e63c95ce49e9c50ea99dae30531252321fc8dcddbff4');
}
