<?php
/**
 * FieldPlx Shared Footer
 *
 * File:
 * includes/footer.php
 *
 * This file closes the wrappers opened in topbar.php:
 *
 * .fieldplx-content-wrapper
 * .fieldplx-main-content
 * .fieldplx-main-layout
 *
 * Compatible with:
 * - PHP 7.2+
 * - Bootstrap 5.3
 */

$basePath = isset($basePath)
    ? (string) $basePath
    : '';

$currentYear = date('Y');

$companyName = !empty($_SESSION['company_name'])
    ? (string) $_SESSION['company_name']
    : 'FieldPlx';

$tenantId = !empty($_SESSION['tenant_id'])
    ? (int) $_SESSION['tenant_id']
    : 0;

$userId = !empty($_SESSION['user_id'])
    ? (int) $_SESSION['user_id']
    : 0;

$sessionTimeout = defined('FIELDPLX_SESSION_TIMEOUT')
    ? (int) FIELDPLX_SESSION_TIMEOUT
    : 7200;

$loginUrl = function_exists('getLoginUrl')
    ? getLoginUrl()
    : $basePath . 'login.php';
?>

        </div>
    </main>
</div>

<footer class="fieldplx-footer">
    <div class="fieldplx-footer-inner">

        <div class="fieldplx-footer-copyright">
            &copy; <?= e($currentYear); ?>
            <?= e($companyName); ?>.
            All rights reserved.
        </div>

        <div class="fieldplx-footer-links">
            <a href="<?= e($basePath); ?>privacy-policy.php">
                Privacy
            </a>

            <span class="fieldplx-footer-separator">
                &bull;
            </span>

            <a href="<?= e($basePath); ?>terms.php">
                Terms
            </a>

            <span class="fieldplx-footer-separator">
                &bull;
            </span>

            <a href="<?= e($basePath); ?>support.php">
                Support
            </a>
        </div>

        <div class="fieldplx-footer-product">
            Powered by
            <strong>FieldPlx</strong>
        </div>

    </div>
</footer>

<style>
    .fieldplx-footer {
        min-height: 52px;
        margin-left: var(--fieldplx-sidebar-width);
        border-top: 1px solid var(--fieldplx-border);
        background: #ffffff;
        transition:
            margin-left 0.22s ease,
            background-color 0.22s ease;
    }

    .fieldplx-footer-inner {
        min-height: 52px;
        padding: 10px 18px;
        display: flex;
        align-items: center;
        gap: 18px;
        color: #6b7280;
        font-size: 10px;
    }

    .fieldplx-footer-copyright {
        min-width: 0;
    }

    .fieldplx-footer-links {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .fieldplx-footer-links a {
        color: #6b7280;
        text-decoration: none;
        transition: color 0.18s ease;
    }

    .fieldplx-footer-links a:hover {
        color: var(--fieldplx-primary);
    }

    .fieldplx-footer-separator {
        color: #d1d5db;
        font-size: 8px;
    }

    .fieldplx-footer-product {
        margin-left: auto;
        white-space: nowrap;
        color: #9ca3af;
    }

    .fieldplx-footer-product strong {
        color: var(--fieldplx-primary);
        font-weight: 700;
    }

    body.fieldplx-sidebar-collapsed
    .fieldplx-footer {
        margin-left: var(--fieldplx-sidebar-collapsed-width);
    }

    @media (max-width: 991.98px) {
        .fieldplx-footer {
            margin-left: 0;
        }

        body.fieldplx-sidebar-collapsed
        .fieldplx-footer {
            margin-left: 0;
        }
    }

    @media (max-width: 767.98px) {
        .fieldplx-footer-inner {
            padding: 12px;
            flex-wrap: wrap;
            justify-content: center;
            gap: 7px 14px;
            text-align: center;
        }

        .fieldplx-footer-product {
            width: 100%;
            margin-left: 0;
        }
    }
</style>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<script src="<?= e($basePath); ?>assets/js/scripts.js"></script>

<script>
(function () {
    'use strict';

    window.FieldPlx = window.FieldPlx || {};

    window.FieldPlx.basePath = <?= json_encode(
        $basePath,
        JSON_UNESCAPED_SLASHES
    ); ?>;

    window.FieldPlx.loginUrl = <?= json_encode(
        $loginUrl,
        JSON_UNESCAPED_SLASHES
    ); ?>;

    window.FieldPlx.tenantId = <?= (int) $tenantId; ?>;
    window.FieldPlx.userId = <?= (int) $userId; ?>;
    window.FieldPlx.sessionTimeout = <?= (int) $sessionTimeout; ?>;

    /*
    |--------------------------------------------------------------------------
    | CSRF token
    |--------------------------------------------------------------------------
    */

    const csrfMeta = document.querySelector(
        'meta[name="csrf-token"]'
    );

    window.FieldPlx.csrfToken = csrfMeta
        ? csrfMeta.getAttribute('content')
        : '';

    /*
    |--------------------------------------------------------------------------
    | Bootstrap tooltips
    |--------------------------------------------------------------------------
    */

    if (
        typeof bootstrap !== 'undefined' &&
        bootstrap.Tooltip
    ) {
        document
            .querySelectorAll(
                '[data-bs-toggle="tooltip"]'
            )
            .forEach(function (element) {
                bootstrap.Tooltip.getOrCreateInstance(
                    element
                );
            });
    }

    /*
    |--------------------------------------------------------------------------
    | Bootstrap popovers
    |--------------------------------------------------------------------------
    */

    if (
        typeof bootstrap !== 'undefined' &&
        bootstrap.Popover
    ) {
        document
            .querySelectorAll(
                '[data-bs-toggle="popover"]'
            )
            .forEach(function (element) {
                bootstrap.Popover.getOrCreateInstance(
                    element
                );
            });
    }

    /*
    |--------------------------------------------------------------------------
    | Automatically dismiss alerts
    |--------------------------------------------------------------------------
    */

    if (
        typeof bootstrap !== 'undefined' &&
        bootstrap.Alert
    ) {
        document
            .querySelectorAll(
                '.alert[data-auto-dismiss]'
            )
            .forEach(function (alertElement) {
                let dismissTime = parseInt(
                    alertElement.getAttribute(
                        'data-auto-dismiss'
                    ),
                    10
                );

                if (
                    !dismissTime ||
                    dismissTime < 1000
                ) {
                    dismissTime = 4000;
                }

                window.setTimeout(function () {
                    if (!document.body.contains(alertElement)) {
                        return;
                    }

                    bootstrap.Alert
                        .getOrCreateInstance(
                            alertElement
                        )
                        .close();
                }, dismissTime);
            });
    }

    /*
    |--------------------------------------------------------------------------
    | Prevent duplicate form submission
    |--------------------------------------------------------------------------
    |
    | Usage:
    | <form data-prevent-double-submit>
    |
    */

    document
        .querySelectorAll(
            'form[data-prevent-double-submit]'
        )
        .forEach(function (form) {
            form.addEventListener(
                'submit',
                function (event) {
                    if (form.dataset.submitting === '1') {
                        event.preventDefault();
                        return;
                    }

                    if (
                        typeof form.checkValidity === 'function' &&
                        !form.checkValidity()
                    ) {
                        return;
                    }

                    form.dataset.submitting = '1';

                    form
                        .querySelectorAll(
                            'button[type="submit"], input[type="submit"]'
                        )
                        .forEach(function (button) {
                            button.disabled = true;

                            if (
                                button.tagName === 'BUTTON' &&
                                !button.dataset.originalHtml
                            ) {
                                button.dataset.originalHtml =
                                    button.innerHTML;

                                button.innerHTML =
                                    '<span class="spinner-border ' +
                                    'spinner-border-sm me-1" ' +
                                    'aria-hidden="true"></span>' +
                                    'Please wait...';
                            }
                        });
                }
            );
        });

    /*
    |--------------------------------------------------------------------------
    | Confirm actions
    |--------------------------------------------------------------------------
    */

    document
        .querySelectorAll('[data-confirm]')
        .forEach(function (element) {
            element.addEventListener(
                'click',
                function (event) {
                    const message =
                        element.getAttribute(
                            'data-confirm'
                        );

                    if (
                        message &&
                        !window.confirm(message)
                    ) {
                        event.preventDefault();
                        event.stopImmediatePropagation();
                    }
                }
            );
        });

    /*
    |--------------------------------------------------------------------------
    | Password visibility buttons
    |--------------------------------------------------------------------------
    */

    document
        .querySelectorAll(
            '[data-password-toggle]'
        )
        .forEach(function (button) {
            button.addEventListener(
                'click',
                function () {
                    const targetSelector =
                        button.getAttribute(
                            'data-password-toggle'
                        );

                    if (!targetSelector) {
                        return;
                    }

                    const input =
                        document.querySelector(
                            targetSelector
                        );

                    if (!input) {
                        return;
                    }

                    const showPassword =
                        input.type === 'password';

                    input.type = showPassword
                        ? 'text'
                        : 'password';

                    const icon =
                        button.querySelector('i');

                    if (icon) {
                        icon.classList.toggle(
                            'bi-eye',
                            !showPassword
                        );

                        icon.classList.toggle(
                            'bi-eye-slash',
                            showPassword
                        );
                    }

                    button.setAttribute(
                        'aria-label',
                        showPassword
                            ? 'Hide password'
                            : 'Show password'
                    );
                }
            );
        });

    /*
    |--------------------------------------------------------------------------
    | Common request helper
    |--------------------------------------------------------------------------
    |
    | Automatically sends:
    | - X-Requested-With
    | - CSRF token
    | - same-origin credentials
    | - JSON Accept header
    |
    */

    window.FieldPlx.request = function (
        url,
        options
    ) {
        options = options || {};

        const requestOptions =
            Object.assign(
                {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-store'
                },
                options
            );

        requestOptions.headers =
            Object.assign(
                {
                    'X-Requested-With':
                        'XMLHttpRequest',
                    'Accept':
                        'application/json'
                },
                options.headers || {}
            );

        if (window.FieldPlx.csrfToken) {
            requestOptions.headers[
                'X-CSRF-Token'
            ] = window.FieldPlx.csrfToken;
        }

        if (
            requestOptions.body instanceof FormData &&
            window.FieldPlx.csrfToken &&
            !requestOptions.body.has('csrf_token')
        ) {
            requestOptions.body.append(
                'csrf_token',
                window.FieldPlx.csrfToken
            );
        }

        return fetch(
            url,
            requestOptions
        ).then(function (response) {
            return response.text()
                .then(function (responseText) {
                    let responseData = null;

                    if (responseText.trim() !== '') {
                        try {
                            responseData = JSON.parse(
                                responseText
                            );
                        } catch (error) {
                            responseData = {
                                success: false,
                                message:
                                    'Invalid server response.',
                                raw_response:
                                    responseText
                            };
                        }
                    } else {
                        responseData = {
                            success: response.ok,
                            message: response.ok
                                ? ''
                                : 'Empty server response.'
                        };
                    }

                    if (
                        response.status === 401
                    ) {
                        const redirectUrl =
                            responseData &&
                            responseData.redirect_url
                                ? responseData.redirect_url
                                : window.FieldPlx.loginUrl;

                        window.location.href =
                            redirectUrl;

                        const sessionError =
                            new Error(
                                responseData.message ||
                                'Your session has expired.'
                            );

                        sessionError.status = 401;
                        sessionError.response =
                            responseData;

                        throw sessionError;
                    }

                    if (!response.ok) {
                        const requestError =
                            new Error(
                                responseData.message ||
                                'The request could not be completed.'
                            );

                        requestError.status =
                            response.status;

                        requestError.response =
                            responseData;

                        throw requestError;
                    }

                    return responseData;
                });
        });
    };

    /*
    |--------------------------------------------------------------------------
    | POST JSON helper
    |--------------------------------------------------------------------------
    */

    window.FieldPlx.postJson = function (
        url,
        data
    ) {
        return window.FieldPlx.request(
            url,
            {
                method: 'POST',
                headers: {
                    'Content-Type':
                        'application/json'
                },
                body: JSON.stringify(
                    data || {}
                )
            }
        );
    };

    /*
    |--------------------------------------------------------------------------
    | POST form helper
    |--------------------------------------------------------------------------
    */

    window.FieldPlx.postForm = function (
        url,
        data
    ) {
        const formData =
            data instanceof FormData
                ? data
                : new FormData();

        if (
            !(data instanceof FormData) &&
            data &&
            typeof data === 'object'
        ) {
            Object.keys(data)
                .forEach(function (key) {
                    formData.append(
                        key,
                        data[key]
                    );
                });
        }

        return window.FieldPlx.request(
            url,
            {
                method: 'POST',
                body: formData
            }
        );
    };

    /*
    |--------------------------------------------------------------------------
    | Session activity tracking
    |--------------------------------------------------------------------------
    |
    | Stores the last local browser activity time.
    | Server-side auth.php remains the source of truth.
    |
    */

    function updateLocalActivity() {
        try {
            window.sessionStorage.setItem(
                'fieldplx_last_activity',
                String(Date.now())
            );
        } catch (error) {
            // Storage may be unavailable.
        }
    }

    [
        'click',
        'keydown',
        'touchstart',
        'scroll'
    ].forEach(function (eventName) {
        document.addEventListener(
            eventName,
            updateLocalActivity,
            {
                passive: true
            }
        );
    });

    updateLocalActivity();

    /*
    |--------------------------------------------------------------------------
    | Close mobile sidebar after navigation
    |--------------------------------------------------------------------------
    */

    document
        .querySelectorAll(
            '.fieldplx-sidebar a'
        )
        .forEach(function (link) {
            link.addEventListener(
                'click',
                function () {
                    if (
                        window.innerWidth < 992
                    ) {
                        document.body.classList.remove(
                            'fieldplx-sidebar-mobile-open'
                        );
                    }
                }
            );
        });

    /*
    |--------------------------------------------------------------------------
    | Escape key closes mobile sidebar
    |--------------------------------------------------------------------------
    */

    document.addEventListener(
        'keydown',
        function (event) {
            if (event.key === 'Escape') {
                document.body.classList.remove(
                    'fieldplx-sidebar-mobile-open'
                );
            }
        }
    );
})();
</script>

</body>
</html>
