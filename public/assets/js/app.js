
try {
    // Dropdown stop
    var dropdownMenus = document.querySelectorAll('.dropdown-menu.stop');
    dropdownMenus.forEach(function (dropdownMenu) {
        dropdownMenu.addEventListener('click', function (event) {
            event.stopPropagation();
        });
    });
} catch (err) {
}

try {
    // Icon
    lucide.createIcons();
} catch (err) { }


try {
    // TopBar Light Dark + persistência
    var themeConfig = window.APP_THEME || {};
    var themeColorToggle = document.getElementById('light-dark-mode');
    var htmlRoot = document.documentElement;

    function applyTheme(theme) {
        htmlRoot.setAttribute('data-bs-theme', theme);
        htmlRoot.setAttribute('data-startbar', theme);
        if (themeConfig) {
            themeConfig.current = theme;
        }
    }

    function persistTheme(theme) {
        if (!themeConfig.endpoint) return;
        var payload = new URLSearchParams();
        payload.append('theme', theme);
        payload.append('csrf_token', themeConfig.csrf || '');

        fetch(themeConfig.endpoint, {
            method: 'POST',
            body: payload,
            credentials: 'same-origin'
        }).then(function (resp) {
            return resp.json().catch(function () { return {}; });
        }).then(function (data) {
            if (data && data.success && data.theme) {
                themeConfig.current = data.theme;
            }
        }).catch(function () {
            // ignora falha silenciosamente
        });
    }

    if (themeColorToggle) {
        themeColorToggle.addEventListener('click', function () {
            var currentTheme = htmlRoot.getAttribute('data-bs-theme') || themeConfig.current || 'light';
            var nextTheme = currentTheme === 'light' ? 'dark' : 'light';
            applyTheme(nextTheme);
            persistTheme(nextTheme);
        });
    }

    if (themeConfig.current) {
        applyTheme(themeConfig.current);
    }
} catch (err) { }

try {

    //collapsed
    var collapsedToggle = document.querySelector(".mobile-menu-btn");
    const sidebarOverlay = document.querySelector('.startbar-overlay');
    collapsedToggle?.addEventListener('click', function () {

        var sidebarSize = document.body.getAttribute("data-sidebar-size");

        if (sidebarSize == "collapsed") {
            document.body.setAttribute("data-sidebar-size", "default")

        } else {
            document.body.setAttribute("data-sidebar-size", "collapsed")
        }

    });

    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', () => {
            document.body.setAttribute("data-sidebar-size", "collapsed")
        })
    }

    const changeSidebarSize = () => {
        if (window.innerWidth >= 310 && window.innerWidth <= 1440) {
            document.body.setAttribute("data-sidebar-size", "collapsed")
        } else {
            document.body.setAttribute("data-sidebar-size", "default")
        }
    }

    window.addEventListener('resize', () => {
        changeSidebarSize()
    })

    changeSidebarSize();

} catch (err) {
}

try {
    function initTooltips() {
        if (!window.bootstrap || !window.bootstrap.Tooltip) {
            return;
        }
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
            if (el._tooltipInstance) {
                return;
            }
            el._tooltipInstance = new bootstrap.Tooltip(el);
        });
    }
    window.initTooltips = initTooltips;
    initTooltips();

    var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
        return new bootstrap.Popover(popoverTriggerEl);
    });
} catch (err) {
}


try {

   
    changeSidebarSize();

    // Add event listener for window resize
    window.addEventListener('resize', changeSidebarSize);

    window.addEventListener('resize', () => {
        changeSidebarSize()
    })

    changeSidebarSize();

} catch (err) {
}


/*********************/
/*   Menu Sticky     */

/*********************/
function windowScroll() {
    const navbar = document.getElementById("topbar-custom");
    if (navbar != null) {
        if (
            document.body.scrollTop >= 50 ||
            document.documentElement.scrollTop >= 50
        ) {
            navbar.classList.add("nav-sticky");
        } else {
            navbar.classList.remove("nav-sticky");
        }
    }
}

window.addEventListener('scroll', (ev) => {
    ev.preventDefault();
    windowScroll();
})


const initVerticalMenu = () => {
    const navCollapse = document.querySelectorAll('.navbar-nav li .collapse');
    const navToggle = document.querySelectorAll(".navbar-nav li [data-bs-toggle='collapse']");

    navToggle.forEach(toggle => {
        toggle.addEventListener('click', function (e) {
            e.preventDefault();
        });
    });

    // open one menu at a time only (Auto Close Menu)
    navCollapse.forEach(collapse => {
        collapse.addEventListener('show.bs.collapse', function (event) {
            const parent = event.target.closest('.collapse.show');
            document.querySelectorAll('.navbar-nav .collapse.show').forEach(element => {
                if (element !== event.target && element !== parent) {
                    const collapseInstance = new bootstrap.Collapse(element);
                    collapseInstance.hide();
                }
            });
        });
    });

    if (document.querySelector(".navbar-nav")) {
        // Activate the menu in left side bar based on url
        document.querySelectorAll(".navbar-nav a").forEach(function (link) {
            var pageUrl = window.location.href.split(/[?#]/)[0];

            if (link.href === pageUrl) {
                link.classList.add("active");
                link.parentNode.classList.add("active");

                let parentCollapseDiv = link.closest(".collapse");
                while (parentCollapseDiv) {
                    parentCollapseDiv.classList.add("show");
                    parentCollapseDiv.parentElement.children[0].classList.add("active");
                    parentCollapseDiv.parentElement.children[0].setAttribute("aria-expanded", "true");
                    parentCollapseDiv = parentCollapseDiv.parentElement.closest(".collapse");
                }
            }
        });

        setTimeout(function () {
            var activatedItem = document.querySelector('.nav-item li a.active');

            if (activatedItem != null) {
                var simplebarContent = document.querySelector('.main-nav .simplebar-content-wrapper');
                var offset = activatedItem.offsetTop - 300;
                if (simplebarContent && offset > 100) {
                    scrollTo(simplebarContent, offset, 600);
                }
            }
        }, 200);

        // scrollTo (Left Side Bar Active Menu)
        function easeInOutQuad(t, b, c, d) {
            t /= d / 2;
            if (t < 1) return c / 2 * t * t + b;
            t--;
            return -c / 2 * (t * (t - 2) - 1) + b;
        }

        function scrollTo(element, to, duration) {
            var start = element.scrollTop, change = to - start, currentTime = 0, increment = 20;
            var animateScroll = function () {
                currentTime += increment;
                var val = easeInOutQuad(currentTime, start, change, duration);
                element.scrollTop = val;
                if (currentTime < duration) {
                    setTimeout(animateScroll, increment);
                }
            };
            animateScroll();
        }
    }
}

initVerticalMenu();
