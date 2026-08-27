<?php
/**
 * FieldPlx SMTP credential encryption key.
 * IMPORTANT: Keep this exact file/key identical on localhost and live server.
 * Do not change it after SMTP passwords have been saved, unless you re-enter
 * and re-save every SMTP password so it can be encrypted with the new key.
 */
if (!defined('FIELDPLX_SMTP_ENCRYPTION_KEY')) {
    define('FIELDPLX_SMTP_ENCRYPTION_KEY', '5e97c9304cb9902501537847eccbbdf65ff460fdb795bbdfad684287d49dde40');
}
