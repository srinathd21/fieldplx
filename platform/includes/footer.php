<?php
/**
 * FieldPlx Platform Panel Footer
 *
 * This file closes the layout wrappers opened by:
 *
 * platform/includes/topbar.php
 *
 * Compatible with PHP 7.2.
 */

$basePath = isset($basePath)
    ? (string) $basePath
    : '';

$currentYear = date('Y');

$platformFooterVersion = defined('FIELDPLX_VERSION')
    ? FIELDPLX_VERSION
    : '1.0.0';

/*
|--------------------------------------------------------------------------
| Platform helper fallback
|--------------------------------------------------------------------------
*/

if (!function_exists('platformEscape')) {
    function platformEscape($value)
    {
        return htmlspecialchars(
            (string) ($value === null ? '' : $value),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}
?>

        </div>
    </main>
</div>

<footer class="platform-footer">
    <div class="platform-footer-inner">

        <div class="platform-footer-copyright">
            &copy; <?= (int) $currentYear; ?>
            FieldPlx. All rights reserved.
        </div>

        <div class="platform-footer-links">
            <a
                href="<?= platformEscape($basePath); ?>privacy-policy.php"
            >
                Privacy
            </a>

            <span class="platform-footer-separator">
                &bull;
            </span>

            <a
                href="<?= platformEscape($basePath); ?>terms.php"
            >
                Terms
            </a>

            <span class="platform-footer-separator">
                &bull;
            </span>

            <a
                href="<?= platformEscape($basePath); ?>support.php"
            >
                Support
            </a>

            <span class="platform-footer-separator">
                &bull;
            </span>

            <a
                href="<?= platformEscape($basePath); ?>system-health.php"
            >
                System Status
            </a>
        </div>

        <div class="platform-footer-version">
            Platform v<?= platformEscape(
                $platformFooterVersion
            ); ?>
        </div>

    </div>
</footer>

<style>
    .platform-footer {
        min-height: 52px;
        margin-left: var(--platform-sidebar-width);
        border-top: 1px solid var(--platform-border);
        background: #ffffff;
        transition: margin-left 0.22s ease;
    }

    .platform-footer-inner {
        min-height: 52px;
        padding: 10px 18px;
        display: flex;
        align-items: center;
        gap: 18px;
        color: #6b7280;
        font-size: 10px;
    }

    .platform-footer-copyright {
        min-width: 0;
        white-space: nowrap;
    }

    .platform-footer-links {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .platform-footer-links a {
        color: #6b7280;
        text-decoration: none;
        transition: color 0.15s ease;
    }

    .platform-footer-links a:hover {
        color: var(--platform-accent);
    }

    .platform-footer-separator {
        color: #d1d5db;
        font-size: 8px;
    }

    .platform-footer-version {
        margin-left: auto;
        white-space: nowrap;
        color: #9ca3af;
        font-size: 9px;
    }

    body.platform-sidebar-collapsed
    .platform-footer {
        margin-left:
            var(
                --platform-sidebar-collapsed-width
            );
    }

    @media (max-width: 991.98px) {
        .platform-footer,
        body.platform-sidebar-collapsed
        .platform-footer {
            margin-left: 0;
        }
    }

    @media (max-width: 767.98px) {
        .platform-footer-inner {
            padding: 12px;
            flex-wrap: wrap;
            justify-content: center;
            gap: 7px 14px;
            text-align: center;
        }

        .platform-footer-copyright {
            width: 100%;
            white-space: normal;
        }

        .platform-footer-links {
            flex-wrap: wrap;
            justify-content: center;
        }

        .platform-footer-version {
            width: 100%;
            margin-left: 0;
        }
    }
</style>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<script
    src="<?= platformEscape($basePath); ?>assets/js/platform.js"
></script>

<script>
(function () {
    'use strict';

    /*
    |--------------------------------------------------------------------------
    | Platform namespace
    |--------------------------------------------------------------------------
    */

    window.FieldPlxPlatform =
        window.FieldPlxPlatform || {};

    window.FieldPlxPlatform.basePath =
        <?= json_encode(
            $basePath,
            JSON_UNESCAPED_SLASHES
        ); ?>;

    /*
    |--------------------------------------------------------------------------
    | Bootstrap tooltips
    |--------------------------------------------------------------------------
    */

    const tooltipElements =
        document.querySelectorAll(
            '[data-bs-toggle="tooltip"]'
        );

    tooltipElements.forEach(function (element) {
        bootstrap.Tooltip.getOrCreateInstance(
            element
        );
    });

    /*
    |--------------------------------------------------------------------------
    | Bootstrap popovers
    |--------------------------------------------------------------------------
    */

    const popoverElements =
        document.querySelectorAll(
            '[data-bs-toggle="popover"]'
        );

    popoverElements.forEach(function (element) {
        bootstrap.Popover.getOrCreateInstance(
            element
        );
    });

    /*
    |--------------------------------------------------------------------------
    | Auto-dismiss alerts
    |--------------------------------------------------------------------------
    |
    | Example:
    |
    | <div
    |     class="alert alert-success"
    |     data-auto-dismiss="4000"
    | >
    |     Saved successfully.
    | </div>
    |
    */

    const autoDismissAlerts =
        document.querySelectorAll(
            '.alert[data-auto-dismiss]'
        );

    autoDismissAlerts.forEach(function (alertElement) {
        let dismissTime = parseInt(
            alertElement.getAttribute(
                'data-auto-dismiss'
            ),
            10
        );

        if (!dismissTime || dismissTime < 1000) {
            dismissTime = 4000;
        }

        window.setTimeout(function () {
            const alertInstance =
                bootstrap.Alert.getOrCreateInstance(
                    alertElement
                );

            alertInstance.close();
        }, dismissTime);
    });

    /*
    |--------------------------------------------------------------------------
    | Prevent duplicate form submission
    |--------------------------------------------------------------------------
    |
    | Add data-prevent-double-submit to a form.
    |
    */

    const protectedForms =
        document.querySelectorAll(
            'form[data-prevent-double-submit]'
        );

    protectedForms.forEach(function (form) {
        form.addEventListener(
            'submit',
            function (event) {
                if (
                    form.dataset.submitting === '1'
                ) {
                    event.preventDefault();
                    return;
                }

                if (!form.checkValidity()) {
                    return;
                }

                form.dataset.submitting = '1';

                const submitButtons =
                    form.querySelectorAll(
                        'button[type="submit"], ' +
                        'input[type="submit"]'
                    );

                submitButtons.forEach(
                    function (button) {
                        button.disabled = true;

                        if (
                            button.tagName === 'BUTTON'
                        ) {
                            button.dataset.originalHtml =
                                button.innerHTML;

                            button.innerHTML =
                                '<span class="spinner-border ' +
                                'spinner-border-sm me-1" ' +
                                'aria-hidden="true"></span>' +
                                'Please wait...';
                        }
                    }
                );
            }
        );
    });

    /*
    |--------------------------------------------------------------------------
    | Confirmation actions
    |--------------------------------------------------------------------------
    |
    | Example:
    |
    | data-confirm="Suspend this tenant?"
    |
    */

    const confirmElements =
        document.querySelectorAll(
            '[data-confirm]'
        );

    confirmElements.forEach(function (element) {
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
    | Password visibility
    |--------------------------------------------------------------------------
    |
    | Example:
    |
    | <button
    |     type="button"
    |     data-password-toggle="#password"
    | >
    |     <i class="bi bi-eye"></i>
    | </button>
    |
    */

    const passwordToggleButtons =
        document.querySelectorAll(
            '[data-password-toggle]'
        );

    passwordToggleButtons.forEach(
        function (button) {
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

                    const passwordInput =
                        document.querySelector(
                            targetSelector
                        );

                    if (!passwordInput) {
                        return;
                    }

                    const shouldShow =
                        passwordInput.type ===
                        'password';

                    passwordInput.type =
                        shouldShow
                            ? 'text'
                            : 'password';

                    const icon =
                        button.querySelector('i');

                    if (icon) {
                        icon.classList.toggle(
                            'bi-eye',
                            !shouldShow
                        );

                        icon.classList.toggle(
                            'bi-eye-slash',
                            shouldShow
                        );
                    }
                }
            );
        }
    );

    /*
    |--------------------------------------------------------------------------
    | Select all checkboxes
    |--------------------------------------------------------------------------
    |
    | Example:
    |
    | <input
    |     type="checkbox"
    |     data-select-all=".tenant-checkbox"
    | >
    |
    */

    const selectAllCheckboxes =
        document.querySelectorAll(
            '[data-select-all]'
        );

    selectAllCheckboxes.forEach(
        function (selectAllCheckbox) {
            selectAllCheckbox.addEventListener(
                'change',
                function () {
                    const selector =
                        selectAllCheckbox.getAttribute(
                            'data-select-all'
                        );

                    if (!selector) {
                        return;
                    }

                    document
                        .querySelectorAll(selector)
                        .forEach(
                            function (checkbox) {
                                if (
                                    !checkbox.disabled
                                ) {
                                    checkbox.checked =
                                        selectAllCheckbox
                                            .checked;

                                    checkbox.dispatchEvent(
                                        new Event(
                                            'change',
                                            {
                                                bubbles: true
                                            }
                                        )
                                    );
                                }
                            }
                        );
                }
            );
        }
    );

    /*
    |--------------------------------------------------------------------------
    | Copy text buttons
    |--------------------------------------------------------------------------
    |
    | Example:
    |
    | <button
    |     type="button"
    |     data-copy-text="TENANT-00001"
    | >
    |     Copy
    | </button>
    |
    */

    const copyButtons =
        document.querySelectorAll(
            '[data-copy-text]'
        );

    copyButtons.forEach(function (button) {
        button.addEventListener(
            'click',
            function () {
                const text =
                    button.getAttribute(
                        'data-copy-text'
                    );

                if (!text) {
                    return;
                }

                navigator.clipboard
                    .writeText(text)
                    .then(function () {
                        const originalText =
                            button.innerHTML;

                        button.innerHTML =
                            '<i class="bi bi-check2"></i> ' +
                            'Copied';

                        window.setTimeout(
                            function () {
                                button.innerHTML =
                                    originalText;
                            },
                            1500
                        );
                    })
                    .catch(function () {
                        window.prompt(
                            'Copy this value:',
                            text
                        );
                    });
            }
        );
    });

    /*
    |--------------------------------------------------------------------------
    | Common platform request helper
    |--------------------------------------------------------------------------
    */

    window.FieldPlxPlatform.request =
        function (url, options) {
            options = options || {};
            options.headers =
                options.headers || {};

            options.headers[
                'X-Requested-With'
            ] = 'XMLHttpRequest';

            return fetch(url, options)
                .then(function (response) {
                    return response.text()
                        .then(
                            function (responseText) {
                                let responseData;

                                try {
                                    responseData =
                                        JSON.parse(
                                            responseText
                                        );
                                } catch (error) {
                                    responseData = {
                                        success: false,
                                        message:
                                            'Invalid server response.'
                                    };
                                }

                                if (!response.ok) {
                                    const requestError =
                                        new Error(
                                            responseData
                                                .message ||
                                            'The request could not be completed.'
                                        );

                                    requestError.status =
                                        response.status;

                                    requestError.response =
                                        responseData;

                                    throw requestError;
                                }

                                return responseData;
                            }
                        );
                });
        };

    /*
    |--------------------------------------------------------------------------
    | Close mobile sidebar after navigation
    |--------------------------------------------------------------------------
    */

    const platformSidebarLinks =
        document.querySelectorAll(
            '.platform-sidebar a'
        );

    platformSidebarLinks.forEach(
        function (sidebarLink) {
            sidebarLink.addEventListener(
                'click',
                function () {
                    if (
                        window.innerWidth < 992
                    ) {
                        document.body.classList.remove(
                            'platform-sidebar-mobile-open'
                        );
                    }
                }
            );
        }
    );

    /*
    |--------------------------------------------------------------------------
    | Browser online and offline status
    |--------------------------------------------------------------------------
    */

    function updateConnectionStatus() {
        document.body.classList.toggle(
            'platform-is-offline',
            !navigator.onLine
        );

        document.dispatchEvent(
            new CustomEvent(
                'fieldplx:platform-connection',
                {
                    detail: {
                        online: navigator.onLine
                    }
                }
            )
        );
    }

    window.addEventListener(
        'online',
        updateConnectionStatus
    );

    window.addEventListener(
        'offline',
        updateConnectionStatus
    );

    updateConnectionStatus();
})();
</script>

</body>
</html>
