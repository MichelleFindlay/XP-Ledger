(function () {
    "use strict";

    var config = window.APP_CONFIG || { pollIntervalMs: 30000, enabled: true };
    var lastImage = null;
    var activeLayer = "a";
    var lastCheckTime = Date.now();
    var currentGame = window.INITIAL_GAME || null;

    var els = {
        artImg: document.querySelector("[data-art-img]"),
        artFallback: document.querySelector("[data-art-fallback]"),
        badge: document.querySelector("[data-status-badge]"),
        name: document.querySelector("[data-game-name]"),
        platform: document.querySelector("[data-game-platform]"),
        subtitle: document.querySelector("[data-game-subtitle]"),
        presence: document.querySelector("[data-game-presence]"),
        facts: document.querySelector("[data-game-facts]"),
        achWrap: document.querySelector("[data-ach-wrap]"),
        achFill: document.querySelector("[data-ach-fill]"),
        achLabel: document.querySelector("[data-ach-label]"),
        links: document.querySelector("[data-game-links]"),
        prevWrap: document.querySelector("[data-prev]"),
        prevArtImg: document.querySelector("[data-prev-art-img]"),
        prevArtFallback: document.querySelector("[data-prev-art-fallback]"),
        prevName: document.querySelector("[data-prev-name]"),
        prevMeta: document.querySelector("[data-prev-meta]"),
        heroTooltip: document.querySelector("[data-hero-tooltip]"),
        prevTooltip: document.querySelector("[data-prev-tooltip]"),
        updated: document.querySelector("[data-updated]"),
        bgA: document.querySelector("[data-bg-a]"),
        bgB: document.querySelector("[data-bg-b]"),
    };

    function setText(node, value) {
        if (node) {
            node.textContent = value || "";
        }
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = text;
        return node;
    }

    // Game art has two candidates — the tall library capsule first, then
    // the wide store header (some older or delisted apps only have the
    // latter) — and finally the letter placeholder if neither loads.
    function attachArtFallback(imgEl, fallbackEl) {
        if (!imgEl) {
            return;
        }
        imgEl.addEventListener("error", function () {
            var next = imgEl.getAttribute("data-fallback-src");
            if (next && imgEl.src !== next) {
                imgEl.setAttribute("data-fallback-src", "");
                imgEl.src = next;
                return;
            }
            imgEl.style.display = "none";
            if (fallbackEl) fallbackEl.style.display = "";
        });
    }

    attachArtFallback(els.artImg, els.artFallback);
    attachArtFallback(els.prevArtImg, els.prevArtFallback);

    function setArt(imgEl, fallbackEl, url, fallbackUrl, name) {
        if (!imgEl) {
            return;
        }
        if (fallbackEl) {
            fallbackEl.textContent = (name || "?").charAt(0).toUpperCase();
        }
        imgEl.setAttribute("data-fallback-src", fallbackUrl || "");
        if (url) {
            if (imgEl.getAttribute("src") !== url) {
                imgEl.src = url;
            }
            imgEl.style.display = "";
            if (fallbackEl) fallbackEl.style.display = "none";
        } else {
            imgEl.style.display = "none";
            if (fallbackEl) fallbackEl.style.display = "";
        }
    }

    /**
     * Crossfade the blurred background to a new game art image.
     */
    function updateBackground(imageUrl) {
        if (!imageUrl || imageUrl === lastImage) {
            return;
        }
        lastImage = imageUrl;

        var incoming = activeLayer === "a" ? els.bgB : els.bgA;
        var outgoing = activeLayer === "a" ? els.bgA : els.bgB;

        incoming.style.backgroundImage = "url('" + imageUrl + "')";
        incoming.classList.add("visible");
        outgoing.classList.remove("visible");

        activeLayer = activeLayer === "a" ? "b" : "a";

        extractPalette(imageUrl);
    }

    // Once extractPalette() sets --accent via JS, it overrides the CSS
    // default indefinitely — there was previously no path back, so a game
    // with no art at all would keep showing whatever the last game with
    // art left behind. Reset everything to its CSS default when that
    // happens: fading out the background layer reveals the plain dark page
    // background, and removing the --accent overrides (rather than
    // hardcoding the default here too) lets them fall back to :root's
    // declared values, still through the same 5s --color-transition every
    // other accent change already uses.
    function resetTheme() {
        if (lastImage === null) {
            return; // already at the default; nothing to reset
        }
        lastImage = null;

        if (els.bgA) els.bgA.classList.remove("visible");
        if (els.bgB) els.bgB.classList.remove("visible");

        var root = document.documentElement.style;
        root.removeProperty("--accent");
        root.removeProperty("--accent-soft");
    }

    /**
     * Sample the game art on a canvas to derive an accent colour for the
     * theme. Falls back to the default accent if the image can't be read
     * (e.g. blocked by CORS), since the blurred background still updates
     * regardless.
     */
    function extractPalette(imageUrl) {
        var img = new Image();
        img.crossOrigin = "anonymous";

        img.onload = function () {
            try {
                var size = 24;
                var canvas = document.createElement("canvas");
                canvas.width = size;
                canvas.height = size;
                var ctx = canvas.getContext("2d");
                ctx.drawImage(img, 0, 0, size, size);
                var data = ctx.getImageData(0, 0, size, size).data;

                var r = 0, g = 0, b = 0, weight = 0;

                for (var i = 0; i < data.length; i += 4) {
                    var pr = data[i], pg = data[i + 1], pb = data[i + 2];
                    var max = Math.max(pr, pg, pb);
                    var min = Math.min(pr, pg, pb);
                    var sat = max === 0 ? 0 : (max - min) / max;
                    // Weight saturated, mid-brightness pixels more heavily so the
                    // accent isn't dragged towards a muddy grey average.
                    var w = 0.15 + sat;

                    r += pr * w;
                    g += pg * w;
                    b += pb * w;
                    weight += w;
                }

                r = Math.round(r / weight);
                g = Math.round(g / weight);
                b = Math.round(b / weight);

                // Keep the accent readable no matter what the game art
                // looks like: it's used both as white-text-on-accent
                // (active period buttons, art fallback tiles) and as
                // accent-text-on-the-page's-own-dark-background (status
                // badge, footer links). Both need the accent's own
                // brightness to sit in a safe middle band — and since
                // perceived brightness depends on hue (yellow reads much
                // brighter than blue at the same HSL lightness), the only
                // reliable way to do that is to target real WCAG relative
                // luminance directly, not raw HSL lightness. Hue and
                // saturation are left untouched, so the theme still
                // visibly follows the art's actual colour.
                var hsl = rgbToHsl(r, g, b);
                var safeRgb = ensureSafeLuminance(hsl[0], hsl[1], hsl[2]);

                applyAccent(safeRgb[0], safeRgb[1], safeRgb[2]);
            } catch (err) {
                // Canvas was tainted (no CORS headers) — keep the default accent.
            }
        };

        img.onerror = function () {};
        img.src = imageUrl;
    }

    function rgbToHsl(r, g, b) {
        r /= 255; g /= 255; b /= 255;
        var max = Math.max(r, g, b), min = Math.min(r, g, b);
        var h = 0, s = 0, l = (max + min) / 2;

        if (max !== min) {
            var d = max - min;
            s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
            switch (max) {
                case r: h = (g - b) / d + (g < b ? 6 : 0); break;
                case g: h = (b - r) / d + 2; break;
                default: h = (r - g) / d + 4;
            }
            h /= 6;
        }

        return [h * 360, s * 100, l * 100];
    }

    function hslToRgb(h, s, l) {
        h /= 360; s /= 100; l /= 100;
        var r, g, b;

        if (s === 0) {
            r = g = b = l;
        } else {
            var hue2rgb = function (p, q, t) {
                if (t < 0) t += 1;
                if (t > 1) t -= 1;
                if (t < 1 / 6) return p + (q - p) * 6 * t;
                if (t < 1 / 2) return q;
                if (t < 2 / 3) return p + (q - p) * (2 / 3 - t) * 6;
                return p;
            };
            var q = l < 0.5 ? l * (1 + s) : l + s - l * s;
            var p = 2 * l - q;
            r = hue2rgb(p, q, h + 1 / 3);
            g = hue2rgb(p, q, h);
            b = hue2rgb(p, q, h - 1 / 3);
        }

        return [Math.round(r * 255), Math.round(g * 255), Math.round(b * 255)];
    }

    function relativeLuminance(r, g, b) {
        function linearize(c) {
            c /= 255;
            return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
        }
        return 0.2126 * linearize(r) + 0.7152 * linearize(g) + 0.0722 * linearize(b);
    }

    // Binary-searches lightness (at a fixed hue/saturation) until the
    // resulting colour's WCAG relative luminance lands in a band that
    // reads clearly both as white-text-on-accent and as accent-text on the
    // page's near-black background — see the call site for why luminance
    // rather than raw HSL lightness is the right thing to target.
    function ensureSafeLuminance(h, s, l) {
        var MIN_LUM = 0.12;
        var MAX_LUM = 0.16;

        var rgb = hslToRgb(h, s, l);
        var lum = relativeLuminance(rgb[0], rgb[1], rgb[2]);
        if (lum >= MIN_LUM && lum <= MAX_LUM) {
            return rgb;
        }

        var lo = 0, hi = 100, result = rgb;
        for (var i = 0; i < 18; i++) {
            var mid = (lo + hi) / 2;
            var testRgb = hslToRgb(h, s, mid);
            var testLum = relativeLuminance(testRgb[0], testRgb[1], testRgb[2]);
            result = testRgb;

            if (testLum < MIN_LUM) {
                lo = mid;
            } else if (testLum > MAX_LUM) {
                hi = mid;
            } else {
                break;
            }
        }

        return result;
    }

    function applyAccent(r, g, b) {
        var root = document.documentElement.style;
        root.setProperty("--accent", "rgb(" + r + ", " + g + ", " + b + ")");
        root.setProperty("--accent-soft", "rgba(" + r + ", " + g + ", " + b + ", 0.35)");
    }

    // --- Hover info cards over game art ---
    // Same markup index.php's renderTooltip() produces, from the 'info'
    // payload built by Library::steamInfo() / retroCards().

    function fillTooltip(tooltip, info) {
        if (!tooltip) return;
        tooltip.innerHTML = "";
        if (!info) {
            tooltip.style.display = "none";
            return;
        }
        tooltip.style.display = "";
        tooltip.appendChild(el("span", "art-tooltip-title", info.title));
        (info.groups || []).forEach(function (group) {
            var g = el("span", "art-tooltip-group" + (group.accent ? " accent" : ""));
            group.lines.forEach(function (line) {
                g.appendChild(el("span", "art-tooltip-line", line));
            });
            tooltip.appendChild(g);
        });
    }

    function buildTooltip(info) {
        var tooltip = el("span", "art-tooltip");
        fillTooltip(tooltip, info);
        return tooltip;
    }

    // Tooltips open upward by default; flip them below the art when there
    // isn't room above in the viewport, and pin them to the art's right
    // edge when opening left-aligned would run off the right of the screen
    // (the previously-played thumb sits at the far right of the hero).
    function positionArtTooltip(wrapper) {
        var tooltip = wrapper.querySelector(".art-tooltip");
        if (!tooltip || tooltip.style.display === "none") return;

        var rect = wrapper.getBoundingClientRect();
        wrapper.classList.toggle("art-hover-below", rect.top - tooltip.offsetHeight - 20 < 0);
        wrapper.classList.toggle("art-hover-right", rect.left + tooltip.offsetWidth > document.documentElement.clientWidth - 8);
    }

    // The hero and every panel use backdrop-filter, which makes each one
    // its own stacking context: a tooltip spilling out of its card gets
    // painted underneath any card that comes later in the page, whatever
    // its z-index (and Chromium also bleeds the later card's content
    // through it). Dropping backdrop-filter page-wide while a tooltip is
    // open removes those stacking contexts so the tooltip sits on top.
    // The difference is barely visible: the page background is already a
    // heavily blurred copy of the art.
    document.addEventListener("mouseover", function (evt) {
        var wrapper = evt.target.closest && evt.target.closest(".art-hover");
        document.body.classList.toggle("tooltip-open", !!wrapper);
        if (wrapper) positionArtTooltip(wrapper);
    });

    // --- Relative times ---

    function timeAgo(ts) {
        if (!ts) return "never";
        var secs = Math.max(0, Math.floor(Date.now() / 1000) - ts);
        if (secs < 120) return "just now";
        var units = [[31536000, "year"], [2592000, "month"], [604800, "week"], [86400, "day"], [3600, "hour"], [60, "minute"]];
        for (var i = 0; i < units.length; i++) {
            if (secs >= units[i][0]) {
                var n = Math.floor(secs / units[i][0]);
                return n + " " + units[i][1] + (n === 1 ? "" : "s") + " ago";
            }
        }
        return "just now";
    }

    function formatDuration(secs) {
        var h = Math.floor(secs / 3600);
        var m = Math.floor((secs % 3600) / 60);
        return h > 0 ? h + "h " + (m < 10 ? "0" : "") + m + "m" : m + "m";
    }

    // --- Now playing hero ---

    function renderStatus() {
        var badge = els.badge;
        if (!badge) return;

        if (!currentGame) {
            badge.className = "status-badge";
            badge.textContent = "Nothing played yet";
            return;
        }

        if (currentGame.playing) {
            badge.className = "status-badge live";
            badge.innerHTML = '<span class="eq"><span></span><span></span><span></span></span> ';
            var label = "In game";
            if (currentGame.session_started) {
                label += " · " + formatDuration(Math.floor(Date.now() / 1000) - currentGame.session_started);
                badge.title = "Measured from when XP Ledger first saw this session";
            }
            badge.appendChild(document.createTextNode(label));
        } else {
            badge.className = "status-badge";
            badge.removeAttribute("title");
            badge.textContent = "Last played " + timeAgo(currentGame.last_played);
        }
    }

    function renderLinks(links) {
        if (!els.links) return;
        els.links.innerHTML = "";
        (links || []).forEach(function (link) {
            var a = el("a", "game-link game-link-" + link.kind);
            a.href = link.url;
            if (link.kind === "launch") {
                a.innerHTML = '<svg class="game-link-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="6 3 20 12 6 21 6 3"/></svg>';
            } else {
                a.target = "_blank";
                a.rel = "noopener";
            }
            a.appendChild(document.createTextNode(link.label));
            els.links.appendChild(a);
        });
    }

    function renderGame(game) {
        currentGame = game;
        renderStatus();

        setArt(els.artImg, els.artFallback, game.art, game.art_fallback, game.name);
        setText(els.name, game.name);
        setText(els.platform, game.platform_label);
        setText(els.subtitle, game.subtitle);
        setText(els.facts, (game.facts || []).join(" · "));

        if (els.presence) {
            setText(els.presence, game.presence);
            els.presence.style.display = game.presence ? "" : "none";
        }

        if (els.achWrap) {
            var ach = game.achievements;
            els.achWrap.style.display = ach ? "" : "none";
            if (ach) {
                els.achFill.style.width = ach.pct + "%";
                setText(els.achLabel, ach.unlocked + " / " + ach.total + " " + (ach.noun || "achievements") + " · " + ach.pct + "%");
            }
        }

        renderLinks(game.links);
        fillTooltip(els.heroTooltip, game.info);

        var bg = game.background || game.art_fallback || game.art;
        if (bg) {
            updateBackground(bg);
        } else {
            resetTheme();
        }
    }

    function renderPrevious(prev) {
        if (!els.prevWrap) return;
        if (!prev) {
            els.prevWrap.style.display = "none";
            return;
        }
        els.prevWrap.style.display = "";
        setArt(els.prevArtImg, els.prevArtFallback, prev.art, prev.art_fallback, prev.name);
        setText(els.prevName, prev.name);
        setText(els.prevMeta, prev.platform_label + " · " + timeAgo(prev.last_played));
        fillTooltip(els.prevTooltip, prev.info);
    }

    function renderStats(stats) {
        if (!stats) return;
        Object.keys(stats).forEach(function (key) {
            var node = document.querySelector('[data-stat="' + key + '"]');
            if (node) {
                node.textContent = stats[key];
            }
        });
    }

    function poll() {
        fetch("api.php", { cache: "no-store" })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data && data.ok) {
                    renderGame(data.current);
                    renderPrevious(data.previous);
                    renderStats(data.stats);
                    lastCheckTime = Date.now();
                    updateElapsedLabel();
                }
            })
            .catch(function () {
                /* silently retry on next interval */
            });
    }

    function updateElapsedLabel() {
        if (!els.updated) return;
        var secs = Math.max(0, Math.round((Date.now() - lastCheckTime) / 1000));
        els.updated.textContent = secs < 1 ? "Updated just now" : "Updated " + secs + "s ago";
    }

    if (currentGame) {
        var initialBg = currentGame.background || currentGame.art_fallback || currentGame.art;
        if (initialBg) updateBackground(initialBg);
    }

    if (config.enabled) {
        updateElapsedLabel();
        setInterval(function () {
            updateElapsedLabel();
            if (currentGame && currentGame.playing) renderStatus();
        }, 1000);
        setInterval(poll, config.pollIntervalMs);
    }

    // --- Game lists & genres (period pickers) ---

    function appendNote(container, text, extraClass) {
        if (text) {
            container.appendChild(el("p", "panel-note" + (extraClass ? " " + extraClass : ""), text));
        }
    }

    function renderGameListContent(container, data) {
        container.innerHTML = "";
        appendNote(container, data.note);

        if (!data.games || !data.games.length) {
            container.appendChild(el("p", "empty-state", "Nothing played in this period yet."));
            return;
        }

        var ol = el("ol", "game-list");
        data.games.forEach(function (g) {
            var li = el("li", "game-row");
            li.appendChild(el("span", "rank", String(g.rank)));

            var capsule = el("a", "capsule");
            capsule.href = g.url;
            capsule.target = "_blank";
            capsule.rel = "noopener";
            capsule.tabIndex = -1;
            var img = document.createElement("img");
            img.alt = "";
            img.loading = "lazy";
            var fallback = el("span", "capsule-fallback", (g.name || "?").charAt(0).toUpperCase());
            fallback.style.display = "none";
            img.addEventListener("error", function () {
                img.style.display = "none";
                fallback.style.display = "";
            });
            img.src = g.art;
            capsule.appendChild(img);
            capsule.appendChild(fallback);
            var hover = el("span", "art-hover");
            hover.appendChild(capsule);
            hover.appendChild(buildTooltip(g.info));
            li.appendChild(hover);

            var meta = el("span", "meta");
            var name = el("a", "name", g.name);
            name.href = g.url;
            name.target = "_blank";
            name.rel = "noopener";
            meta.appendChild(name);
            var sub = g.total_label;
            if (g.achievements) {
                sub += " · " + g.achievements.unlocked + "/" + g.achievements.total + " achievements";
            }
            meta.appendChild(el("span", "sub", sub));
            li.appendChild(meta);

            var count = el("span", "count", g.label);
            if (g.pct > 0) {
                var bar = el("span", "bar");
                var fill = el("span", "bar-fill");
                fill.style.width = g.pct + "%";
                bar.appendChild(fill);
                count.appendChild(bar);
            }
            li.appendChild(count);

            ol.appendChild(li);
        });
        container.appendChild(ol);
    }

    function genreColor(i) {
        return "hsl(" + ((i * 137.508) % 360) + ", 65%, 55%)";
    }

    function formatMinutes(minutes) {
        if (minutes < 60) return Math.max(1, Math.round(minutes)) + " min";
        var hours = minutes / 60;
        return (hours < 100 ? hours.toFixed(1) : Math.round(hours).toLocaleString()) + " h";
    }

    function renderGenreContent(container, data) {
        container.innerHTML = "";
        appendNote(container, data.note);

        if (!data.genres || !data.genres.length) {
            container.appendChild(el("p", "empty-state", "No genre data for this period yet — store details fill in as cron.php runs."));
            return;
        }

        var bar = el("div", "genre-bar");
        data.genres.forEach(function (g, i) {
            var seg = el("div", "genre-segment");
            seg.style.width = g.pct + "%";
            seg.style.background = genreColor(i);
            seg.title = g.name + " — " + g.pct + "%";
            bar.appendChild(seg);
        });
        container.appendChild(bar);

        var legend = el("ul", "genre-legend");
        data.genres.forEach(function (g, i) {
            var li = el("li", "genre-legend-item");
            li.setAttribute("data-pct", g.pct);
            var swatch = el("span", "genre-swatch");
            swatch.style.background = genreColor(i);
            li.appendChild(swatch);
            li.appendChild(el("span", "genre-name", g.name));
            li.appendChild(el("span", "genre-pct", g.pct + "% · " + formatMinutes(g.hours * 60)));
            legend.appendChild(li);
        });
        container.appendChild(legend);

        if (data.coverage < 100) {
            appendNote(container, "Based on " + data.coverage + "% of this period's playtime — the rest is from games whose store details haven't been fetched yet.", "panel-note-after");
        }
        applyGenreThreshold();
    }

    var genreThresholdSelect = document.querySelector("[data-genre-threshold]");

    function applyGenreThreshold() {
        if (!genreThresholdSelect) return;
        var threshold = parseFloat(genreThresholdSelect.value) || 0;
        document.querySelectorAll(".genre-legend-item").forEach(function (li) {
            li.classList.toggle("genre-hidden", parseFloat(li.getAttribute("data-pct")) < threshold);
        });
    }

    if (genreThresholdSelect) {
        genreThresholdSelect.addEventListener("change", applyGenreThreshold);
    }

    var PERIOD_PICKERS = {
        games: renderGameListContent,
        genre: renderGenreContent,
    };

    Object.keys(PERIOD_PICKERS).forEach(function (group) {
        var render = PERIOD_PICKERS[group];
        var buttons = document.querySelectorAll('[data-period-group="' + group + '"]');
        var container = document.querySelector('[data-period-content="' + group + '"]');
        if (!buttons.length || !container) return;

        buttons.forEach(function (btn) {
            btn.addEventListener("click", function () {
                if (btn.classList.contains("active")) return;
                var period = btn.getAttribute("data-period");

                buttons.forEach(function (b) { b.classList.remove("active"); });
                btn.classList.add("active");

                container.innerHTML = "";
                container.appendChild(el("div", "widget-loading", "Loading…"));

                fetch("widgets.php?id=" + group + "&period=" + encodeURIComponent(period), { cache: "no-store" })
                    .then(function (res) { return res.json(); })
                    .then(function (payload) {
                        if (payload && payload.ok) {
                            render(container, payload.data);
                        } else {
                            container.innerHTML = "";
                            container.appendChild(el("p", "empty-state", "Couldn't load this period."));
                        }
                    })
                    .catch(function () {
                        container.innerHTML = "";
                        container.appendChild(el("p", "empty-state", "Couldn't load this period."));
                    });
            });
        });
    });

    // --- Insight widget popups ---

    var modalOverlay = document.querySelector("[data-modal-overlay]");
    var modalBody = document.querySelector("[data-modal-body]");
    var modalClose = document.querySelector("[data-modal-close]");
    var widgetCache = {};

    function openModal() {
        if (modalOverlay) modalOverlay.hidden = false;
    }

    function closeModal() {
        if (modalOverlay) modalOverlay.hidden = true;
    }

    function loadWidget(id) {
        if (!modalBody) return;
        openModal();
        modalBody.innerHTML = "";
        modalBody.appendChild(el("div", "widget-loading", "Loading…"));

        if (widgetCache[id]) {
            renderWidget(id, widgetCache[id]);
            return;
        }

        fetch("widgets.php?id=" + encodeURIComponent(id), { cache: "no-store" })
            .then(function (res) { return res.json(); })
            .then(function (payload) {
                if (!payload || !payload.ok) {
                    modalBody.innerHTML = "";
                    modalBody.appendChild(el("div", "widget-empty", "Couldn't load this widget."));
                    return;
                }
                widgetCache[id] = payload.data;
                renderWidget(id, payload.data);
            })
            .catch(function () {
                modalBody.innerHTML = "";
                modalBody.appendChild(el("div", "widget-empty", "Couldn't load this widget."));
            });
    }

    var WIDGETS = {
        unlock_clock: { title: "Achievement Clock", render: renderUnlockClock, empty: "Not enough achievement history yet — XP Ledger checks a batch of games each time cron.php runs." },
        weekly_rhythm: { title: "Weekly Rhythm", render: renderWeeklyRhythm, empty: "No sessions recorded yet. Steam doesn't keep dated play history, so XP Ledger builds it by snapshotting your playtime — schedule cron.php and check back after a few play sessions." },
        time_translated: { title: "Time Well Spent", render: renderTimeTranslated },
        loot_tiers: { title: "Loot Tiers", render: renderLootTiers },
        pile_of_shame: { title: "Pile of Shame", render: renderPileOfShame },
        completionist: { title: "Completionist", render: renderCompletionist, empty: "No achievement data yet — XP Ledger checks a batch of games each time cron.php runs." },
        rarest: { title: "Rarest Unlocks", render: renderRarest, empty: "No global unlock rates fetched yet — these fill in alongside your achievements as cron.php runs." },
        hidden_gems: { title: "Hidden Gems", render: renderHiddenGems, empty: "Not enough store data yet — review counts fill in as cron.php runs." },
        mainstream: { title: "Mainstream Meter", render: renderMainstream, empty: "Not enough store data yet — review counts fill in as cron.php runs." },
        platform_split: { title: "Where You Play", render: renderPlatformSplit },
    };

    function renderWidget(id, data) {
        var def = WIDGETS[id] || {};
        modalBody.innerHTML = "";
        modalBody.appendChild(el("h3", "widget-title", def.title || ""));

        if (!data || data.available === false) {
            modalBody.appendChild(el("div", "widget-empty", def.empty || "Not enough play history yet — check back after a few more sessions."));
            return;
        }
        if (def.render) def.render(data);
    }

    function hourLabel(h) {
        return (h % 12 === 0 ? 12 : h % 12) + (h < 12 ? "am" : "pm");
    }

    function renderUnlockClock(data) {
        var max = Math.max.apply(null, data.hours);
        var cx = 130, cy = 130, rMax = 90, rMin = 22;
        var svgNS = "http://www.w3.org/2000/svg";
        var svg = document.createElementNS(svgNS, "svg");
        svg.setAttribute("viewBox", "0 0 260 260");
        svg.setAttribute("class", "clock-svg");

        for (var h = 0; h < 24; h++) {
            var angle = (h / 24) * Math.PI * 2 - Math.PI / 2;
            var frac = max > 0 ? data.hours[h] / max : 0;
            var r = rMin + frac * (rMax - rMin);
            var line = document.createElementNS(svgNS, "line");
            line.setAttribute("x1", cx + Math.cos(angle) * rMin);
            line.setAttribute("y1", cy + Math.sin(angle) * rMin);
            line.setAttribute("x2", cx + Math.cos(angle) * r);
            line.setAttribute("y2", cy + Math.sin(angle) * r);
            line.setAttribute("stroke", "var(--accent)");
            line.setAttribute("stroke-width", "6");
            line.setAttribute("stroke-linecap", "round");
            var title = document.createElementNS(svgNS, "title");
            title.textContent = hourLabel(h) + ": " + data.hours[h] + " unlocks";
            line.appendChild(title);
            svg.appendChild(line);
        }

        [0, 6, 12, 18].forEach(function (h) {
            var angle = (h / 24) * Math.PI * 2 - Math.PI / 2;
            var text = document.createElementNS(svgNS, "text");
            text.setAttribute("x", cx + Math.cos(angle) * (rMax + 18));
            text.setAttribute("y", cy + Math.sin(angle) * (rMax + 18));
            text.setAttribute("text-anchor", "middle");
            text.setAttribute("dominant-baseline", "middle");
            text.textContent = h + ":00";
            svg.appendChild(text);
        });

        modalBody.appendChild(el("div", "widget-headline", data.label));
        modalBody.appendChild(svg);
        modalBody.appendChild(el("div", "widget-subtext", "Peak hour: " + hourLabel(data.peak_hour) + ". " + data.note));
    }

    function renderWeeklyRhythm(data) {
        modalBody.appendChild(el("div", "widget-headline", data.label));

        var max = Math.max.apply(null, data.days.concat([0.1]));
        var bars = el("div", "energy-bars");
        ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"].forEach(function (name, i) {
            var col = el("div", "energy-bar-col" + (i === data.peak_day && data.days[i] > 0 ? " peak" : ""));
            col.title = data.days[i] + " h";
            var bar = el("div", "energy-bar");
            bar.style.height = Math.max(3, (data.days[i] / max) * 100) + "%";
            col.appendChild(bar);
            col.appendChild(el("span", "energy-bar-label", name));
            bars.appendChild(col);
        });
        modalBody.appendChild(bars);

        // 12-week day grid, GitHub-contributions style: one column per
        // week, Monday at the top.
        var gridMax = Math.max.apply(null, data.grid.map(function (d) { return d.minutes; }).concat([1]));
        var grid = el("div", "day-grid");
        var firstDow = (new Date(data.grid[0].date + "T12:00:00").getDay() + 6) % 7;
        for (var pad = 0; pad < firstDow; pad++) {
            grid.appendChild(el("span", "day-cell day-cell-pad"));
        }
        data.grid.forEach(function (d) {
            var cell = el("span", "day-cell");
            if (d.minutes > 0) {
                var level = Math.min(4, Math.ceil((d.minutes / gridMax) * 4));
                cell.className += " day-level-" + level;
            }
            cell.title = d.date + ": " + (d.minutes > 0 ? formatMinutes(d.minutes) : "no play recorded");
            grid.appendChild(cell);
        });
        modalBody.appendChild(grid);

        var streak = data.streak === 1 ? "1-day streak" : data.streak + "-day streak";
        modalBody.appendChild(el("div", "widget-subtext", (data.streak > 0 ? "🔥 " + streak + " · " : "") + data.note));
    }

    function renderProgressRows(rows) {
        var list = el("ul", "distance-list");
        rows.forEach(function (c) {
            var li = el("li", "distance-row");
            var top = el("div", "distance-row-top");
            var left = document.createElement("span");
            left.appendChild(el("span", "distance-count", c.count));
            left.appendChild(document.createTextNode(" " + c.label));
            top.appendChild(left);
            top.appendChild(el("span", null, c.right));
            li.appendChild(top);

            var bar = el("div", "distance-bar");
            var fill = el("div", "distance-bar-fill" + (c.fillClass ? " " + c.fillClass : ""));
            fill.style.width = c.pct + "%";
            bar.appendChild(fill);
            li.appendChild(bar);
            list.appendChild(li);
        });
        return list;
    }

    function renderTimeTranslated(data) {
        modalBody.appendChild(el("div", "widget-headline", data.total_hours.toLocaleString() + " hours played"));
        modalBody.appendChild(el("div", "widget-subtext", "That's about " + data.total_days.toLocaleString() + " full days"));
        modalBody.appendChild(renderProgressRows(data.comparisons.map(function (c) {
            return { count: c.count.toLocaleString(), label: c.label, right: c.pct + "% to the next", pct: c.pct };
        })));
        modalBody.appendChild(el("div", "widget-subtext", "From Steam's own lifetime playtime counters. Speedrun and playthrough lengths are rounded, typical times."));
    }

    function renderLootTiers(data) {
        var chest = el("div", "loot-drop");
        chest.appendChild(el("div", "loot-title", "Loot Drop"));
        chest.appendChild(el("div", "loot-sub", "Rolled from your lifetime playtime"));

        var labels = { legendary: "Legendary", epic: "Epic", rare: "Rare", uncommon: "Uncommon" };
        ["legendary", "epic", "rare", "uncommon"].forEach(function (tier) {
            var items = data.drops.filter(function (d) { return d.tier === tier; });
            if (!items.length) return;
            var section = el("div", "loot-tier loot-" + tier);
            section.appendChild(el("div", "loot-tier-label", labels[tier]));
            var list = el("ul", "loot-items");
            items.forEach(function (d) {
                var li = el("li");
                li.appendChild(el("span", "loot-name", d.name));
                li.appendChild(el("span", "loot-hours", d.hours));
                list.appendChild(li);
            });
            section.appendChild(list);
            chest.appendChild(section);
        });
        modalBody.appendChild(chest);
    }

    function renderPileOfShame(data) {
        modalBody.appendChild(el("div", "widget-headline", data.unplayed.toLocaleString() + " games never launched"));
        modalBody.appendChild(el("div", "widget-subtext", data.unplayed_pct + "% of your " + data.owned.toLocaleString() + "-game library — plus " + data.barely.toLocaleString() + " you've played for under an hour."));

        var bar = el("div", "shame-bar");
        var playedPct = 100 - data.unplayed_pct;
        var played = el("div", "shame-played");
        played.style.width = playedPct + "%";
        played.title = "Played: " + playedPct + "%";
        bar.appendChild(played);
        modalBody.appendChild(bar);

        if (data.famous.length) {
            modalBody.appendChild(el("div", "widget-section-title", "The famous ones you haven't touched"));
            var list = el("ul", "distance-list");
            data.famous.forEach(function (g) {
                var li = el("li");
                li.appendChild(el("span", null, g.name));
                li.appendChild(el("span", "distance-count", g.reviews.toLocaleString() + " reviews"));
                list.appendChild(li);
            });
            modalBody.appendChild(list);
        }

        if (data.forecast_years !== null) {
            modalBody.appendChild(el("div", "widget-section-title", "Backlog forecast"));
            modalBody.appendChild(el("div", "widget-headline widget-headline-small", "~" + data.forecast_years.toLocaleString() + " years to clear it"));
            modalBody.appendChild(el("div", "widget-subtext", data.forecast_note));
        }
    }

    function renderCompletionist(data) {
        var s = data.summary;
        modalBody.appendChild(el("div", "widget-headline", s.unlocked.toLocaleString() + " achievements unlocked"));

        var stats = el("div", "mini-stats");
        [
            [s.perfect_games, "perfect games"],
            [s.avg_completion_pct !== null ? s.avg_completion_pct + "%" : "—", "avg completion"],
            [s.games_started, "games started"],
        ].forEach(function (pair) {
            var box = el("div", "mini-stat");
            box.appendChild(el("strong", null, String(pair[0])));
            box.appendChild(el("span", null, pair[1]));
            stats.appendChild(box);
        });
        modalBody.appendChild(stats);

        if (data.close.length) {
            modalBody.appendChild(el("div", "widget-section-title", "Closest to 100%"));
            modalBody.appendChild(renderProgressRows(data.close.map(function (c) {
                return { count: c.remaining + " to go", label: "in " + c.name, right: c.unlocked + "/" + c.total, pct: c.pct };
            })));
        }

        if (data.perfect.length) {
            modalBody.appendChild(el("div", "widget-section-title", "Perfect games"));
            var tags = el("div", "tag-cloud");
            data.perfect.forEach(function (p) {
                tags.appendChild(el("span", "tag", p.name));
            });
            modalBody.appendChild(tags);
        }

        if (s.coverage_pct < 100) {
            modalBody.appendChild(el("div", "widget-subtext", "From " + s.checked_games + " games checked so far (" + s.coverage_pct + "% of the games you've played) — cron.php checks more each run."));
        }
    }

    function renderRarest(data) {
        var list = el("ul", "rare-list");
        data.unlocks.forEach(function (u) {
            var li = el("li");
            var left = el("div", "rare-meta");
            left.appendChild(el("div", "rare-name", u.name));
            left.appendChild(el("div", "rare-game", u.game + " · " + u.date));
            if (u.desc) left.appendChild(el("div", "rare-desc", u.desc));
            li.appendChild(left);
            li.appendChild(el("span", "rare-pct", u.percent + "%"));
            list.appendChild(li);
        });
        modalBody.appendChild(el("div", "widget-subtext", "Percent of all players who own the game and have unlocked it."));
        modalBody.appendChild(list);
    }

    function renderHiddenGems(data) {
        modalBody.appendChild(el("div", "widget-subtext", "Games you've given real time to, ranked by fewest Steam reviews."));
        var list = el("ul", "distance-list");
        data.games.forEach(function (g) {
            var li = el("li");
            var left = document.createElement("span");
            left.appendChild(document.createTextNode(g.name + " "));
            left.appendChild(el("span", "muted", "· " + g.hours));
            li.appendChild(left);
            li.appendChild(el("span", "distance-count", g.reviews.toLocaleString() + " reviews"));
            list.appendChild(li);
        });
        modalBody.appendChild(list);
        modalBody.appendChild(el("div", "widget-subtext", "From " + data.sampled + " of your most-played games with review data."));
    }

    function renderMainstream(data) {
        modalBody.appendChild(el("div", "widget-headline", data.median.toLocaleString()));
        modalBody.appendChild(el("div", "widget-subtext", "median Steam reviews across your most-played games (average " + data.average.toLocaleString() + ")"));
        modalBody.appendChild(el("div", "widget-verdict", data.label));
        modalBody.appendChild(el("div", "widget-subtext", "Sampled from " + data.sampled + " games you've played for 2+ hours."));
    }

    function renderPlatformSplit(data) {
        var bar = el("div", "genre-bar");
        data.rows.forEach(function (r) {
            var seg = el("div", "genre-segment platform-" + r.key);
            seg.style.width = r.pct + "%";
            seg.title = r.label + " — " + r.pct + "%";
            bar.appendChild(seg);
        });
        modalBody.appendChild(bar);

        modalBody.appendChild(renderProgressRows(data.rows.map(function (r) {
            return { count: r.hours, label: r.label, right: r.pct + "%", pct: r.pct, fillClass: "platform-" + r.key };
        })));

        if (data.deck_hours) {
            modalBody.appendChild(el("div", "widget-subtext", "Including " + data.deck_hours + " (" + data.deck_pct + "%) on Steam Deck."));
        }
    }

    document.querySelectorAll("[data-widget-id]").forEach(function (card) {
        card.addEventListener("click", function () {
            loadWidget(card.getAttribute("data-widget-id"));
        });
    });

    if (modalClose) {
        modalClose.addEventListener("click", closeModal);
    }
    if (modalOverlay) {
        modalOverlay.addEventListener("click", function (evt) {
            if (evt.target === modalOverlay) closeModal();
        });
    }
    document.addEventListener("keydown", function (evt) {
        if (evt.key === "Escape") closeModal();
    });
})();
