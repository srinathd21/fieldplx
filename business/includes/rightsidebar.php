<?php
$sampleActivity = [
    [
        'icon' => 'file-text',
        'title' => 'Invoice activity',
        'message' => 'Recent invoice updates will appear here.',
        'time' => 'Today'
    ],
    [
        'icon' => 'user-round',
        'title' => 'Team activity',
        'message' => 'Assignments and team actions will appear here.',
        'time' => 'Today'
    ],
];
?>

<div id="rightDrawerOverlay" class="right-drawer-overlay" aria-hidden="true"></div>

<aside id="rightDrawer"
       class="right-drawer"
       aria-hidden="true"
       aria-label="Quick panel">

    <div class="right-drawer-view" data-drawer-view="help">
        <div class="right-drawer-header">
            <h2>Need a hand?</h2>
            <button class="right-drawer-close" type="button" aria-label="Close">
                <i data-lucide="x"></i>
            </button>
        </div>

        <div class="right-drawer-body help-drawer-body">
            <a href="#" class="drawer-help-center-btn">
                <i data-lucide="circle-help"></i>
                <span>Visit Help Center</span>
            </a>

            <div class="drawer-section-label">Recommendations</div>

            <div class="drawer-recommendations">
                <a href="#" class="drawer-recommendation">
                    <strong>Get Started</strong>
                    <span>A hand-picked list of articles focused on getting your company up and running.</span>
                </a>

                <a href="#" class="drawer-recommendation">
                    <strong>Schedule</strong>
                    <span>View your schedule, tasks, events, and upcoming work.</span>
                </a>

                <a href="#" class="drawer-recommendation">
                    <strong>Work</strong>
                    <span>Help articles that cover requests, quotes, jobs, invoices, and timesheets.</span>
                </a>

                <a href="#" class="drawer-recommendation">
                    <strong>Clients</strong>
                    <span>Manage clients and the communications you send to them.</span>
                </a>
            </div>

            <div class="drawer-help-bottom">
                <a href="#" class="drawer-video-link">
                    <i data-lucide="circle-play"></i>
                    <span>Watch Product Videos</span>
                </a>

                <a href="#" class="drawer-support-btn">
                    <i data-lucide="messages-square"></i>
                    <span>Get Support</span>
                </a>

                <a href="#" class="drawer-terms-link">Terms of Service</a>
            </div>
        </div>
    </div>

    <div class="right-drawer-view" data-drawer-view="notifications">
        <div class="right-drawer-header">
            <h2>Notifications</h2>
            <button class="right-drawer-close" type="button" aria-label="Close">
                <i data-lucide="x"></i>
            </button>
        </div>

        <div class="notification-tabs" role="tablist">
            <button type="button" class="notification-tab active"
                    data-notification-tab="mentions" role="tab">
                Mentions
            </button>
            <button type="button" class="notification-tab"
                    data-notification-tab="activity" role="tab">
                Activity
            </button>
        </div>

        <div class="right-drawer-body notification-body">
            <div class="notification-panel active" data-notification-panel="mentions">
                <div class="notification-empty">
                    <div class="notification-empty-icon">@</div>
                    <div>
                        <strong>Any mentions from teammates will show up here</strong>
                        <p>When someone mentions you, you’ll see it in this panel.</p>
                    </div>
                </div>
            </div>

            <div class="notification-panel" data-notification-panel="activity">
                <?php foreach ($sampleActivity as $activity): ?>
                    <div class="notification-activity-item">
                        <div class="notification-activity-icon">
                            <i data-lucide="<?= htmlspecialchars($activity['icon']) ?>"></i>
                        </div>
                        <div class="notification-activity-copy">
                            <strong><?= htmlspecialchars($activity['title']) ?></strong>
                            <p><?= htmlspecialchars($activity['message']) ?></p>
                            <span><?= htmlspecialchars($activity['time']) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</aside>
