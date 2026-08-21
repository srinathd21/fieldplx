(function () {
            try {
                if (
                    window.innerWidth >= 992 &&
                    localStorage.getItem('fieldplx_sidebar_compact') === '1'
                ) {
                    document.documentElement.classList.add('sidebar-compact');
                }
            } catch (error) {
                /* Continue with the expanded sidebar if storage is unavailable. */
            }
        })();

document.addEventListener('DOMContentLoaded', function () {
const root = document.documentElement;
    const body = document.body;
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const sidebarStorageKey = 'fieldplx_sidebar_compact';

    function readSavedSidebarState() {
        try {
            return localStorage.getItem(sidebarStorageKey) === '1';
        } catch (error) {
            return false;
        }
    }

    function saveSidebarState(isCompact) {
        try {
            localStorage.setItem(sidebarStorageKey, isCompact ? '1' : '0');
        } catch (error) {
            /* The page still works when browser storage is disabled. */
        }
    }

    function closeMobileSidebar() {
        body.classList.remove('mobile-sidebar-open');
        sidebarToggle.setAttribute('aria-expanded', 'false');
    }

    function applySavedDesktopSidebarState() {
        if (window.innerWidth >= 992) {
            root.classList.toggle('sidebar-compact', readSavedSidebarState());
        } else {
            root.classList.remove('sidebar-compact');
        }
    }

    sidebarToggle.addEventListener('click', function () {
        if (window.innerWidth < 992) {
            const isOpen = body.classList.toggle('mobile-sidebar-open');
            sidebarToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            return;
        }

        const isCompact = root.classList.toggle('sidebar-compact');
        saveSidebarState(isCompact);

        window.setTimeout(function () {
            window.dispatchEvent(new Event('resize'));
        }, 270);
    });

    sidebarOverlay.addEventListener('click', closeMobileSidebar);

    window.addEventListener('resize', function () {
        if (window.innerWidth >= 992) {
            closeMobileSidebar();
            applySavedDesktopSidebarState();
        } else {
            root.classList.remove('sidebar-compact');
        }
    });

    applySavedDesktopSidebarState();

    document.querySelectorAll('.sidebar-link').forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();

            document.querySelectorAll('.sidebar-link').forEach(function (item) {
                item.classList.remove('active');
            });

            this.classList.add('active');
            closeMobileSidebar();
        });
    });

    // Toast action messages

    let toastTimer;

    document.querySelectorAll('.action-button').forEach(function (button) {
        button.addEventListener('click', function () {
            showToast(this.dataset.action + ' opened');
        });
    });

    function showToast(message) {
        const toast = document.getElementById('actionToast');
        document.getElementById('toastText').textContent = message;

        toast.style.display = 'flex';

        clearTimeout(toastTimer);

        toastTimer = setTimeout(function () {
            toast.style.display = 'none';
        }, 2200);
    }

    // Today's task completion state

    document.querySelectorAll('.today-task-check').forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
            const taskItem = this.closest('.today-task-item');

            if (taskItem) {
                taskItem.classList.toggle('is-complete', this.checked);
            }
        });
    });

    // Main jobs chart

    const jobsCanvas = document.getElementById('jobsChart');
    const jobsContext = jobsCanvas.getContext('2d');

    const blueGradient = jobsContext.createLinearGradient(0, 0, 0, 250);
    blueGradient.addColorStop(0, 'rgba(18,61,112,.22)');
    blueGradient.addColorStop(1, 'rgba(18,61,112,0)');

    const greenGradient = jobsContext.createLinearGradient(0, 0, 0, 250);
    greenGradient.addColorStop(0, 'rgba(116,184,36,.18)');
    greenGradient.addColorStop(1, 'rgba(116,184,36,0)');

    new Chart(jobsCanvas, {
        type: 'line',
        data: {
            labels: [
                'May 12',
                'May 13',
                'May 14',
                'May 15',
                'May 16',
                'May 17',
                'May 18'
            ],
            datasets: [
                {
                    label: 'Total Jobs',
                    data: [190, 260, 290, 360, 275, 260, 315],
                    borderColor: '#123d70',
                    backgroundColor: blueGradient,
                    fill: true,
                    borderWidth: 2,
                    pointRadius: 3,
                    pointBackgroundColor: '#123d70',
                    tension: 0
                },
                {
                    label: 'Completed Jobs',
                    data: [70, 125, 160, 205, 155, 135, 190],
                    borderColor: '#74b824',
                    backgroundColor: greenGradient,
                    fill: true,
                    borderWidth: 2,
                    pointRadius: 3,
                    pointBackgroundColor: '#74b824',
                    tension: 0
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                intersect: false,
                mode: 'index'
            },
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: '#202743',
                    padding: 10,
                    titleFont: {
                        size: 10
                    },
                    bodyFont: {
                        size: 10
                    }
                }
            },
            scales: {
                x: {
                    border: {
                        display: false
                    },
                    grid: {
                        display: false
                    },
                    ticks: {
                        color: '#6f7b90',
                        font: {
                            size: 8
                        }
                    }
                },
                y: {
                    min: 0,
                    max: 400,
                    border: {
                        display: false
                    },
                    grid: {
                        color: '#e5eaf1'
                    },
                    ticks: {
                        stepSize: 100,
                        color: '#6f7b90',
                        font: {
                            size: 8
                        }
                    }
                }
            }
        }
    });

    // Small sparkline charts

    document.querySelectorAll('.spark-chart').forEach(function (canvas) {
        const color = canvas.dataset.color;
        const values = canvas.dataset.values.split(',').map(Number);
        const context = canvas.getContext('2d');
        const gradient = context.createLinearGradient(0, 0, 0, 45);

        gradient.addColorStop(0, hexToRgba(color, .22));
        gradient.addColorStop(1, hexToRgba(color, 0));

        new Chart(canvas, {
            type: 'line',
            data: {
                labels: values.map(function (_, index) {
                    return index + 1;
                }),
                datasets: [{
                    data: values,
                    borderColor: color,
                    backgroundColor: gradient,
                    fill: true,
                    borderWidth: 1.5,
                    pointRadius: 0,
                    tension: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        enabled: false
                    }
                },
                scales: {
                    x: {
                        display: false
                    },
                    y: {
                        display: false
                    }
                }
            }
        });
    });

    function hexToRgba(hex, opacity) {
        const value = hex.replace('#', '');
        const red = parseInt(value.substring(0, 2), 16);
        const green = parseInt(value.substring(2, 4), 16);
        const blue = parseInt(value.substring(4, 6), 16);

        return `rgba(${red}, ${green}, ${blue}, ${opacity})`;
    }
});
