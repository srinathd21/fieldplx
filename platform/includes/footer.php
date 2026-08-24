<footer class="fp-footer">
        <div class="fp-footer-inner">
            <div>© 2026 FieldPlx. All rights reserved.</div>

            <div class="fp-footer-links">
                <a href="#">Privacy</a>
                <span>•</span>
                <a href="#">Terms</a>
                <span>•</span>
                <a href="#">Support</a>
                <span>•</span>
                <a href="#">System Status</a>
            </div>

            <div class="fp-footer-version">
                Platform v1.0.0
            </div>
        </div>
    </footer>

    <style>
        .fp-footer {
            min-height: 52px;
            margin-left: var(--fp-sidebar-width);
            border-top: 1px solid #e8e5f1;
            background: #ffffff;
            transition: margin-left .22s ease;
        }

        .fp-footer-inner {
            min-height: 52px;
            padding: 10px 18px;
            display: flex;
            align-items: center;
            gap: 18px;
            color: #736d8b;
            font-size: 10px;
        }

        .fp-footer-links {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .fp-footer-links a {
            color: #6b7280;
        }

        .fp-footer-links a:hover {
            color: var(--fp-accent);
        }

        .fp-footer-version {
            margin-left: auto;
            color: #9ca3af;
            font-size: 9px;
        }

        body.fp-sidebar-collapsed .fp-footer {
            margin-left: var(--fp-sidebar-collapsed-width);
        }

        @media (max-width: 991.98px) {

            .fp-footer,
            body.fp-sidebar-collapsed .fp-footer {
                margin-left: 0;
            }
        }

        @media (max-width: 767.98px) {
            .fp-footer-inner {
                flex-wrap: wrap;
                justify-content: center;
                gap: 7px 14px;
                text-align: center;
            }

            .fp-footer-version {
                width: 100%;
                margin-left: 0;
            }
        }
    </style>