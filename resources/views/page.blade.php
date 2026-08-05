<style>
    .infra-dashboard {
        padding: 0 4px 28px;
    }

    .infra-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
        margin-bottom: 10px;
    }

    .infra-title {
        margin: 0;
        font-size: 24px;
        font-weight: 700;
    }

    .infra-refresh {
        color: #777;
        font-size: 11px;
        text-align: right;
    }

    .infra-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 8px;
        background: #fff;
        border: 1px solid #ddd;
        border-radius: 4px;
        margin-bottom: 8px;
        padding: 7px 9px;
    }

    .infra-actions {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 5px;
    }

    .infra-button {
        background: #fff;
        border: 1px solid #bbb;
        border-radius: 3px;
        cursor: pointer;
        font-size: 10px;
        padding: 5px 8px;
        transition: background .12s ease, box-shadow .12s ease;
    }

    .infra-button:hover {
        background: #f2f2f2;
    }


    .infra-button.is-active {
        background: #337ab7;
        border-color: #2e6da4;
        box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .35);
        color: #fff;
        font-weight: 700;
    }

    .infra-button.is-active:hover {
        background: #2e6da4;
    }

    .infra-visible-count {
        color: #777;
        font-size: 10px;
        margin-left: 4px;
    }

    .mode-summary {
        color: #444;
        font-size: 10px;
        font-weight: 700;
        margin-left: 4px;
    }

    .mode-summary-empty {
        color: #d9534f;
    }

    .infra-legend {
        background: #fff;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 10px;
        margin-bottom: 10px;
        padding: 7px 9px;
    }

    .infra-legend summary {
        color: #337ab7;
        cursor: pointer;
        font-weight: 700;
        outline: none;
    }

    .legend-grid {
        display: grid;
        gap: 6px 16px;
        grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
        margin-top: 8px;
    }

    .legend-item {
        line-height: 1.4;
    }

    .legend-term {
        display: inline-block;
        font-weight: 700;
        margin-right: 3px;
        min-width: 78px;
    }

    .legend-term.legend-critical {
        color: #d9534f;
    }

    .legend-term.legend-warning {
        color: #8a5a00;
    }

    .legend-term.legend-healthy {
        color: #1c7a40;
    }

    .legend-term.legend-unknown {
        color: #5c6bc0;
    }

    .coverage-panel,
    .summary-panel {
        display: grid;
        gap: 7px;
        grid-template-columns: repeat(auto-fit, minmax(155px, 1fr));
        margin-bottom: 10px;
    }

    .coverage-card,
    .summary-card {
        background: #fff;
        border: 1px solid #ddd;
        border-radius: 4px;
        padding: 8px 10px;
    }

    .coverage-card.coverage-good {
        border-left: 5px solid #27ae60;
    }

    .coverage-card.coverage-bad {
        border-left: 5px solid #d9534f;
    }

    .coverage-card.coverage-neutral {
        border-left: 5px solid #b6c2cc;
    }

    .coverage-label,
    .summary-label {
        color: #777;
        font-size: 10px;
        text-transform: uppercase;
    }

    .coverage-value,
    .summary-value {
        font-size: 22px;
        font-weight: 700;
        line-height: 1.1;
    }

    .coverage-subtitle {
        color: #888;
        font-size: 9px;
        line-height: 1.35;
        margin-top: 3px;
    }

    /*
     * Priority Attention — one row per unhealthy device, worst first,
     * naming the exact cause (see buildPriorityAttention()/
     * primaryIssueFor() in Page.php). This is the "what do I check
     * first" feed the rest of the dashboard's counts and per-location
     * cards summarize; it sits above them for that reason.
     */
    .priority-panel {
        background: #fff;
        border: 1px solid #ddd;
        border-radius: 4px;
        margin-bottom: 10px;
        overflow: hidden;
    }

    .priority-header {
        align-items: center;
        background: #f7f7f7;
        border-bottom: 1px solid #ddd;
        display: flex;
        font-size: 12px;
        font-weight: 700;
        gap: 8px;
        justify-content: space-between;
        padding: 7px 10px;
        text-transform: uppercase;
    }

    .priority-count {
        color: #777;
        font-size: 10px;
        font-weight: 700;
        text-transform: none;
    }

    .priority-list {
        display: flex;
        flex-direction: column;
    }

    .priority-item {
        align-items: center;
        border-bottom: 1px solid #eee;
        border-left: 4px solid transparent;
        color: inherit;
        display: grid;
        gap: 4px 10px;
        grid-template-columns: 62px 90px minmax(0, 1fr) 70px minmax(0, 1.4fr) 110px;
        padding: 6px 10px;
        text-decoration: none;
    }

    .priority-item:hover {
        background: #f7f7f7;
    }

    .priority-item:last-child {
        border-bottom: 0;
    }

    .priority-critical {
        background: #fdf2f2;
        border-left-color: #d9534f;
    }

    .priority-warning {
        background: #fffaf1;
        border-left-color: #f0ad4e;
    }

    .priority-unknown {
        background: #f2f2fb;
        border-left-color: #5c6bc0;
    }

    .priority-stale {
        background: #fffaf1;
        border-left-color: #607d8b;
    }

    .priority-severity {
        font-size: 10px;
        font-weight: 700;
    }

    .priority-critical .priority-severity {
        color: #d9534f;
    }

    .priority-warning .priority-severity {
        color: #8a5a00;
    }

    .priority-unknown .priority-severity {
        color: #5c6bc0;
    }

    .priority-stale .priority-severity {
        color: #546e7a;
    }

    .priority-location,
    .priority-role {
        color: #777;
        font-size: 10px;
        overflow: hidden;
        text-overflow: ellipsis;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .priority-device {
        font-size: 12px;
        font-weight: 700;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .priority-cause {
        font-size: 11px;
        line-height: 1.35;
        overflow-wrap: anywhere;
        white-space: normal;
    }

    .priority-since {
        color: #888;
        font-size: 10px;
        text-align: right;
        white-space: nowrap;
    }

    @media (max-width: 900px) {
        .priority-item {
            grid-template-columns: 56px 1fr auto;
            grid-template-areas:
                "severity device device"
                "severity location role"
                "cause cause cause"
                "since since since";
        }

        .priority-severity { grid-area: severity; }
        .priority-device { grid-area: device; }
        .priority-location { grid-area: location; }
        .priority-role { grid-area: role; text-align: right; }
        .priority-cause { grid-area: cause; white-space: normal; }
        .priority-since { grid-area: since; text-align: left; }
    }

    body.tv-mode-active .priority-panel {
        display: none !important;
    }

    body.tv-mode-active .priority-header {
        background: #171f30;
        border-color: #253046;
        color: #f4f7fc;
        font-size: 15px;
    }

    body.tv-mode-active .priority-count {
        color: #9aa7bd;
    }

    body.tv-mode-active .priority-item {
        border-bottom-color: #232c3d;
    }

    body.tv-mode-active .priority-critical {
        background: #3a1418;
    }

    body.tv-mode-active .priority-warning {
        background: #3a2c10;
    }

    body.tv-mode-active .priority-unknown {
        background: #1e2050;
    }

    body.tv-mode-active .priority-device {
        color: #f4f7fc;
        font-size: 14px;
    }

    body.tv-mode-active .priority-cause {
        color: #e6ecf6;
        font-size: 13px;
    }

    body.tv-mode-active .priority-location,
    body.tv-mode-active .priority-role,
    body.tv-mode-active .priority-since {
        font-size: 11px;
    }

    .infra-section {
        margin-bottom: 12px;
    }

    .infra-section-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        background: #eceff1;
        border: 1px solid #ddd;
        padding: 7px 10px;
    }

    .infra-section-title {
        margin: 0;
        font-size: 17px;
        font-weight: 700;
    }

    .infra-section-meta {
        color: #777;
        font-size: 10px;
        text-align: right;
    }

    .infra-section-body {
        background: #0d1119;
        border: 1px solid #ddd;
        border-top: 0;
        padding: 7px;
    }

    .device-card-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(255px, 1fr));
        gap: 6px;
    }

    .location-grid {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 7px;
    }

    .location-card {
        background: #fff;
        border: 1px solid #d8d8d8;
        border-left: 5px solid #27ae60;
        border-radius: 4px;
        overflow: hidden;
        position: relative;
    }

    .location-card.health-warning {
        background: #fffaf1;
        border-left-color: #f0ad4e;
        box-shadow: 0 0 0 1px rgba(240, 173, 78, .3);
    }

    .location-card.health-critical {
        animation: cardCriticalGlow 2s ease-in-out infinite;
        background: #fdf2f2;
        border-left-color: #d9534f;
        border-left-width: 6px;
    }

    /*
     * "Unknown" / Needs Review — a state sensor whose current value
     * has no resolvable translation (see sensorState() in Page.php).
     * Deliberately a distinct slate/indigo, not red/amber/green: it is
     * neither a confirmed problem nor confirmed healthy, and must
     * never be mistaken for either at a glance.
     */
    .location-card.health-unknown {
        background: #f2f2fb;
        border-left-color: #5c6bc0;
        box-shadow: 0 0 0 1px rgba(92, 107, 192, .3);
    }

    .location-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 7px;
        background: #f7f7f7;
        border-bottom: 1px solid #ddd;
        padding: 7px 8px;
    }

    .location-title {
        margin: 0;
        font-size: 15px;
        font-weight: 700;
    }

    .health-pill {
        border-radius: 12px;
        color: #fff;
        font-size: 9px;
        font-weight: 700;
        padding: 3px 7px;
        text-transform: uppercase;
    }

    .pill-healthy {
        background: #1c7a40;
    }

    .pill-warning {
        background: #8a5a00;
    }

    .pill-critical {
        background: #d9534f;
        animation: criticalGlow 1.7s ease-in-out infinite;
    }

    .pill-unknown {
        background: #5c6bc0;
    }

    .pill-maintenance,
    .pill-stale {
        background: #607d8b;
    }

    .location-counts {
        border-bottom: 1px solid #eee;
        color: #777;
        font-size: 9px;
        padding: 5px 8px;
    }

    .location-device-list {
        list-style: none;
        margin: 0;
        padding: 0;
    }

    .device-row {
        border-bottom: 1px solid #eee;
        border-left: 3px solid transparent;
        overflow: hidden;
        padding: 6px 8px;
        position: relative;
    }

    .device-row:last-child {
        border-bottom: 0;
    }

    .device-row[data-health="warning"] {
        background: #fffaf1;
        border-left-color: #f0ad4e;
    }

    .device-row[data-health="critical"] {
        animation: cardCriticalGlow 2s ease-in-out infinite;
        background: #fdf2f2;
        border-left-color: #d9534f;
    }

    .device-row[data-health="maintenance"],
    .device-row[data-health="stale"] {
        border-left-color: #607d8b;
    }

    .device-row[data-health="unknown"] {
        background: #f2f2fb;
        border-left-color: #5c6bc0;
    }

    .device-main {
        display: grid;
        grid-template-columns: 13px minmax(0, 1fr) auto;
        align-items: center;
        gap: 6px;
    }

    .device-name {
        min-width: 0;
    }

    .device-name a {
        display: block;
        font-size: 11px;
        font-weight: 700;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .device-meta {
        color: #888;
        font-size: 9px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .device-state {
        font-size: 9px;
        font-weight: 700;
        text-transform: uppercase;
    }

    .device-card {
        background: #fff;
        border: 1px solid #ddd;
        border-left: 4px solid #27ae60;
        border-radius: 4px;
        overflow: hidden;
        padding: 7px 8px;
        position: relative;
    }

    .device-card.health-warning {
        background: #fffaf1;
        border-left-color: #f0ad4e;
        box-shadow: 0 0 0 1px rgba(240, 173, 78, .3);
    }

    .device-card.health-critical {
        animation: cardCriticalGlow 2s ease-in-out infinite;
        background: #fdf2f2;
        border-left-color: #d9534f;
        border-left-width: 6px;
    }

    .device-card.health-unknown {
        background: #f2f2fb;
        border-left-color: #5c6bc0;
        box-shadow: 0 0 0 1px rgba(92, 107, 192, .3);
    }

    .device-card-title {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 7px;
        font-size: 11px;
        font-weight: 700;
    }

    .device-card-title a {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .device-card-meta {
        color: #777;
        font-size: 9px;
        margin-top: 3px;
    }

    .status-up {
        color: #1c7a40;
    }

    .status-down {
        color: #d9534f;
        animation: downPulse 1s ease-in-out infinite;
    }

    .telemetry {
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
        margin-top: 5px;
    }

    .metric {
        background: #f3f5f6;
        border: 1px solid #dfe3e5;
        border-radius: 3px;
        font-size: 9px;
        padding: 3px 5px;
    }

    .metric-warning {
        background: #fff8e5;
        border-color: #f0ad4e;
    }

    .metric-critical {
        background: #fdeaea;
        border-color: #d9534f;
    }

    .metric-unknown {
        background: #eeeefa;
        border-color: #5c6bc0;
    }

    .metric-missing {
        color: #888;
    }

    .metric-no_sensor {
        background: #f7f7f7;
        border-style: dotted;
        color: #666;
    }

    .metric-freshness {
        display: inline-block;
        margin-left: 4px;
        opacity: .78;
    }

    .health-maintenance {
        border-color: #607d8b !important;
    }

    .health-stale {
        border-color: #f0ad4e !important;
        border-style: dashed !important;
    }

    /*
     * Freshness is layered on top of severity, never a replacement
     * for it — a metric whose last known reading was critical stays
     * visually critical (red) even when stale; this dashed outline +
     * clock icon only adds "this may not be the current instant" on
     * top of whatever severity color already applies. See
     * AUDIT_NOTES.md for the incident this design corrects (a stale
     * dead UPS battery was previously shown as a neutral gray badge
     * instead of a critical one).
     */
    .metric-is-stale {
        border-style: dashed;
    }

    .metric-is-stale::after {
        content: "\f017";
        font-family: FontAwesome;
        margin-left: 4px;
        opacity: .6;
    }

    .metric-filtered {
        display: none !important;
    }

    .telemetry-empty-note {
        color: #888;
        display: none;
        font-size: 9px;
        font-style: italic;
        margin-top: 5px;
    }

    .telemetry-empty-note.telemetry-empty-visible {
        display: block;
    }

    .metric-icon {
        margin-right: 3px;
    }

    .metric-temperature.metric-warning .metric-icon {
        color: #e67e22;
        animation: heatPulse 1.7s ease-in-out infinite;
    }

    .metric-temperature.metric-critical .metric-icon {
        color: #d9534f;
        animation: heatCritical .9s ease-in-out infinite;
    }

    .metric-humidity.metric-warning .metric-icon {
        color: #428bca;
        animation: humidityPulse 1.7s ease-in-out infinite;
    }

    .metric-humidity.metric-critical .metric-icon {
        color: #d9534f;
        animation: humidityCritical 1s ease-in-out infinite;
    }

    .metric-battery.metric-warning .metric-icon {
        color: #e67e22;
        animation: batteryPulse 1.4s ease-in-out infinite;
    }

    .metric-battery.metric-critical .metric-icon {
        color: #d9534f;
        animation: batteryCritical .75s ease-in-out infinite;
    }

    .service-summary {
        font-size: 9px;
        margin-top: 5px;
    }

    .service-issue {
        border-top: 1px solid #eee;
        font-size: 9px;
        margin-top: 5px;
        padding-top: 5px;
    }

    .service-message {
        color: #777;
        margin-top: 2px;
    }

    .alert-issue-critical strong {
        color: #d9534f;
    }

    .alert-issue-warning strong {
        color: #8a5a00;
    }

    .recent-event-line {
        color: #888;
        font-size: 9px;
        margin-top: 4px;
    }

    .empty-filter-result {
        display: none;
        background: #f7f7f7;
        border: 1px dashed #bbb;
        border-radius: 4px;
        color: #777;
        margin-bottom: 10px;
        padding: 18px;
        text-align: center;
    }

    @keyframes criticalGlow {
        0%, 100% {
            box-shadow: 0 0 0 rgba(217, 83, 79, 0);
        }

        50% {
            box-shadow: 0 0 8px rgba(217, 83, 79, .7);
        }
    }

    /*
     * Card-level "this needs attention right now" cue for any Critical
     * card (device or location) — a slow, wide pulsing red glow around
     * the whole card, not just its metric icons, so severity reads at
     * a glance from across a room on a TV.
     */
    @keyframes cardCriticalGlow {
        0%, 100% {
            box-shadow: 0 0 0 1px rgba(217, 83, 79, .35), 0 0 6px rgba(217, 83, 79, .25);
        }

        50% {
            box-shadow: 0 0 0 2px rgba(217, 83, 79, .8), 0 0 22px rgba(217, 83, 79, .65);
        }
    }

    /*
     * Same idea, tuned for TV Mode's dark theme and wall-display
     * viewing distance: faster pulse, wider/brighter glow.
     */
    @keyframes cardCriticalGlowTv {
        0%, 100% {
            box-shadow: 0 0 0 1px rgba(255, 90, 90, .5), 0 0 10px rgba(255, 90, 90, .35);
        }

        50% {
            box-shadow: 0 0 0 3px rgba(255, 90, 90, 1), 0 0 40px rgba(255, 90, 90, .85);
        }
    }

    /*
     * The decorative per-problem-type overlays (heat shimmer, humidity
     * "rain" streaks, service sweep) that used to render here were
     * removed at explicit user request — reported as visual noise that
     * competed with the actual severity/cause text on a "Problems
     * only" view. Severity is still fully conveyed by the existing
     * card background tint, left border color, and (for Critical) the
     * pulsing glow below — this only removes the extra animated
     * texture layered on top of that.
     */

    @keyframes downPulse {
        0%, 100% {
            opacity: .55;
        }

        50% {
            opacity: 1;
            text-shadow: 0 0 5px rgba(217, 83, 79, .7);
        }
    }

    @keyframes heatPulse {
        0%, 100% {
            transform: translateY(0) scale(1);
        }

        50% {
            transform: translateY(-2px) scale(1.16);
        }
    }

    @keyframes heatCritical {
        0%, 100% {
            transform: translateY(0) rotate(-3deg) scale(1);
        }

        50% {
            transform: translateY(-2px) rotate(3deg) scale(1.25);
        }
    }

    @keyframes humidityPulse {
        0%, 100% {
            opacity: .65;
            transform: translateY(0);
        }

        50% {
            opacity: 1;
            transform: translateY(2px);
        }
    }

    @keyframes humidityCritical {
        0%, 100% {
            transform: scale(1);
        }

        50% {
            transform: scale(1.3);
        }
    }

    @keyframes batteryPulse {
        0%, 100% {
            opacity: .6;
            transform: scale(1);
        }

        50% {
            opacity: 1;
            transform: scale(1.18);
        }
    }

    @keyframes batteryCritical {
        0%, 100% {
            opacity: .45;
            transform: translateX(0);
        }

        25% {
            transform: translateX(-2px);
        }

        50% {
            opacity: 1;
            transform: translateX(0) scale(1.22);
        }

        75% {
            transform: translateX(2px);
        }
    }

    @media (max-width: 1550px) {
        .location-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }
    }

    @media (max-width: 1150px) {
        .location-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    @media (max-width: 800px) {
        .infra-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .infra-refresh {
            text-align: left;
        }

        .location-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    /*
     * Phone portrait — a device/location card is dense enough (name,
     * status, several metric badges, sometimes an alert line) that
     * splitting the screen into 2 columns here just makes every card
     * cramped and the text wrap awkwardly; one full-width column per
     * card reads far better at this size.
     */
    @media (max-width: 480px) {
        .location-grid,
        .device-card-grid {
            grid-template-columns: minmax(0, 1fr) !important;
        }

        .infra-actions {
            flex-wrap: wrap;
        }

        .coverage-panel {
            grid-template-columns: minmax(0, 1fr) !important;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .infra-dashboard *,
        .infra-dashboard *::before,
        .infra-dashboard *::after {
            animation: none !important;
            transition: none !important;
        }
    }

    /*
     * Admin-controlled equivalent of the media query above ("Enable
     * card animations" in Settings) — same effect, triggered by a
     * setting instead of the visitor's OS preference.
     */
    body.animations-disabled .infra-dashboard *,
    body.animations-disabled .infra-dashboard *::before,
    body.animations-disabled .infra-dashboard *::after {
        animation: none !important;
        transition: none !important;
    }

    /*
     * TV Mode — a large-screen, low-interaction presentation of the
     * same data. Toggled client-side only (button, Escape key, or a
     * ?tv=1 query string), so it needs no server route of its own.
     */
    .tv-clock {
        display: none;
        color: #cdd6e4;
        font-size: 13px;
        font-weight: 700;
        letter-spacing: .04em;
        position: fixed;
        right: 14px;
        top: 44px;
        z-index: 20;
    }

    /*
     * The clock and exit-button JS toggles set `element.style.display
     * = tvMode ? '' : 'none'` — the empty string clears any inline
     * override and falls back to the stylesheet, which (with no rule
     * below) always resolved to the base `display: none` above,
     * regardless of tvMode. Both were effectively permanently
     * invisible in TV Mode until these two rules were added.
     */
    body.tv-mode-active .tv-clock {
        display: block;
    }

    /*
     * Deliberately high-contrast and always fully visible (no
     * hover-to-reveal) — the old opacity:.35 version was reported as
     * effectively invisible on a phone (no hover state at all on
     * touch, and 35% opacity against a busy background reads as
     * decoration, not a button). This has to be found instantly by
     * someone who doesn't already know it's there.
     */
    .tv-exit-button {
        align-items: center;
        background: #d9534f;
        border: 2px solid #ff8b8b;
        border-radius: 5px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, .45);
        color: #fff;
        display: none;
        flex-shrink: 0;
        font-size: 12px;
        font-weight: 700;
        gap: 5px;
        padding: 6px 10px;
        transition: transform .12s ease, box-shadow .12s ease;
    }

    body.tv-mode-active .tv-exit-button {
        display: flex;
    }

    .tv-exit-button:hover,
    .tv-exit-button:focus-visible {
        box-shadow: 0 4px 16px rgba(0, 0, 0, .6);
        transform: scale(1.05);
    }

    .tv-status-banner {
        display: none;
        align-items: center;
        border-radius: 5px;
        font-size: 15px;
        font-weight: 700;
        justify-content: center;
        letter-spacing: .03em;
        margin-bottom: 8px;
        padding: 8px 12px;
        text-align: center;
    }

    .tv-status-healthy {
        background: #123a24;
        color: #6fe3a0;
    }

    .tv-status-warning {
        background: #402c0c;
        color: #ffc76b;
    }

    .tv-status-critical {
        background: #3d1414;
        color: #ff8b8b;
        animation: criticalGlow 1.7s ease-in-out infinite;
    }

    .tv-status-unknown {
        background: #23265e;
        color: #b3bdf5;
    }

    /*
     * "All clear" slide — shown instead of a section whenever every
     * device in it is currently healthy, so a fully-healthy section
     * (e.g. Other Locations with nothing active) is still visited by
     * the TV rotation rather than silently disappearing from it.
     */
    .tv-clear-slide {
        display: none;
    }

    body.tv-mode-active .tv-clear-slide.tv-active-slide {
        align-items: center;
        background: #101625;
        border: 1px solid #232c3d;
        border-radius: 6px;
        display: flex !important;
        flex-direction: column;
        gap: 10px;
        justify-content: center;
        min-height: 340px;
        padding: 40px;
        text-align: center;
    }

    .tv-clear-icon {
        color: #6fe3a0;
        font-size: 54px;
    }

    .tv-clear-title {
        color: #f4f7fc;
        font-size: 26px;
        font-weight: 700;
    }

    .tv-clear-subtitle {
        color: #9aa7bd;
        font-size: 15px;
    }

    body.tv-mode-active {
        background: #05070d;
    }

    /*
     * LibreNMS's own site header (`nav.navbar-sticky-top`, defined in
     * the core layout, `position: sticky; top: 0; z-index: 1100` —
     * see html/css/styles.css) used to sit far above every z-index
     * this plugin used, back when `.tv-exit-button` was itself
     * `position: fixed` (it's a normal flex child of `.infra-header`
     * now — see the comment where it's rendered — so it no longer has
     * a z-index of its own at all). It isn't part of this plugin's
     * markup, so it was never hidden by `[data-dashboard-section]`/
     * `.infra-*` rules above, and TV Mode never removed it — on a real
     * display it stayed pinned at the same top-of-viewport strip as
     * the exit button/clock, visually clipping them (reported as "the
     * exit button gets cut off in TV Mode"). `position:
     * sticky` still occupies normal document flow (unlike `fixed`), so
     * hiding it needs no compensating body padding — the layout
     * reclaims that space cleanly. This is a display-only CSS rule
     * scoped to `body.tv-mode-active`, added from this plugin's own
     * view; no LibreNMS core file is touched. (LibreNMS also supports
     * loading any page with `?bare=yes` to skip rendering this navbar
     * entirely server-side — worth using for a dedicated TV/kiosk URL
     * instead of relying on this client-side override, but this rule
     * keeps TV Mode correct even when toggled from a normal session.)
     */
    body.tv-mode-active nav.navbar-sticky-top {
        display: none !important;
    }

    /*
     * Denser TV Mode, per explicit user request: less padding/margin/
     * gap so more cards fit per slide (fewer rotations to see the
     * whole fleet) — text/icon sizes below are deliberately left
     * unchanged from before this pass, only spacing shrank, so
     * readability from TV viewing distance is unaffected.
     */
    body.tv-mode-active .infra-dashboard {
        padding: 8px 14px 12px;
    }

    body.tv-mode-active .infra-toolbar,
    body.tv-mode-active .infra-legend,
    body.tv-mode-active .coverage-panel,
    body.tv-mode-active .summary-panel {
        display: none !important;
    }

    body.tv-mode-active .infra-refresh {
        display: none;
    }

    body.tv-mode-active .infra-header {
        margin-bottom: 6px;
    }

    body.tv-mode-active .infra-title {
        color: #fff;
        font-size: 22px;
    }

    body.tv-mode-active [data-dashboard-section] {
        display: none !important;
    }

    body.tv-mode-active [data-dashboard-section].tv-active-slide {
        display: block !important;
    }

    body.tv-mode-active .infra-section-header {
        background: #101625;
        border-color: #232c3d;
        padding: 5px 10px;
    }

    body.tv-mode-active .infra-section-body {
        background: #0b0f18;
        border-color: #232c3d;
    }

    body.tv-mode-active .infra-section-title,
    body.tv-mode-active .infra-section-meta {
        color: #e6ecf6;
        font-size: 16px;
    }

    body.tv-mode-active .infra-section-meta {
        font-size: 11px;
    }

    body.tv-mode-active .location-grid,
    body.tv-mode-active .device-card-grid {
        grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)) !important;
        gap: 8px;
    }

    body.tv-mode-active .location-card,
    body.tv-mode-active .device-card {
        padding: 5px 7px;
    }

    body.tv-mode-active .location-header {
        padding: 5px 8px;
    }

    body.tv-mode-active .location-counts {
        padding: 3px 8px;
    }

    body.tv-mode-active .device-row {
        padding: 4px 8px;
    }

    body.tv-mode-active .device-card-meta {
        margin-top: 2px;
    }

    body.tv-mode-active .device-name a {
        font-size: 14px;
    }

    body.tv-mode-active .device-meta,
    body.tv-mode-active .device-state,
    body.tv-mode-active .sensor-pill,
    body.tv-mode-active .metric-freshness,
    body.tv-mode-active .service-summary,
    body.tv-mode-active .service-issue,
    body.tv-mode-active .service-message,
    body.tv-mode-active .alert-issue-critical,
    body.tv-mode-active .alert-issue-warning,
    body.tv-mode-active .recent-event-line,
    body.tv-mode-active .location-health-badge,
    body.tv-mode-active .infra-section-meta {
        font-size: 12px;
        line-height: 1.35;
    }

    body.tv-mode-active [data-location-card] {
        display: none !important;
    }

    body.tv-mode-active [data-location-card].tv-active-card {
        display: block !important;
    }

    /*
     * TV Mode shows exactly the devices selected by the Severity/
     * Problem checkboxes above (same `deviceMatches()` as the
     * interactive view) — `.tv-problem-device` is kept in sync with
     * that filter by `tvSyncDeviceClasses()`.
     */
    body.tv-mode-active .monitor-device {
        display: none !important;
    }

    /*
     * Devices inside an already-visible location card: the card
     * itself is the pagination unit, so passing the filter is enough.
     */
    body.tv-mode-active [data-location-card] .device-row.tv-problem-device {
        display: list-item !important;
    }

    /*
     * Devices directly in a standalone section's grid (MDF Servers/
     * Power/Infrastructure) have no location-card wrapper, so they
     * are paginated individually — a matching device only renders
     * once it is also part of the slide's current chunk
     * (`.tv-active-card`), keeping every slide within the screen with
     * no scrolling (see `tvEstimateChunkSize()`).
     */
    body.tv-mode-active .device-card-grid > .device-card.tv-problem-device.tv-active-card {
        display: block !important;
    }

    /*
     * TV Mode's combined "everything, worst first" slide — one pool
     * mixing devices from MDF Servers/Power/Infrastructure with
     * location cards from IDF/Other Locations, Critical-first, shown
     * on one static screen with no rotation whenever it all fits
     * (see tvBuildCombinedSlides()). `.tv-combined-slide` is the
     * pagination unit (toggled exactly like a normal `[data-
     * dashboard-section]`); the grid inside uses the same 340px
     * column-width assumption as tvEstimateChunkSize()'s capacity
     * math, so what JS calculates as "fits on screen" matches what
     * actually renders. Device-card and location-card clones inside
     * it both need their own visibility override here since the base
     * `.monitor-device`/`[data-location-card]` TV rules above hide
     * everything by default and only know about their *original*
     * section wrappers, not this one.
     */
    .tv-combined-slide {
        display: none;
    }

    body.tv-mode-active .tv-combined-slide.tv-active-slide {
        display: block;
    }

    body.tv-mode-active .tv-combined-grid {
        display: grid;
        gap: 8px;
        grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    }

    body.tv-mode-active .tv-combined-grid > .monitor-device.tv-active-card,
    body.tv-mode-active .tv-combined-grid > [data-location-card].tv-active-card {
        display: block !important;
    }

    .tv-card-issue-badge {
        display: none;
    }

    body.tv-mode-active .tv-card-issue-badge {
        color: #ffc76b;
        display: block;
        font-size: 12px;
        font-weight: 700;
        margin-top: 3px;
    }

    body.tv-mode-active .tv-card-issue-badge.tv-badge-critical {
        color: #ff8b8b;
    }

    body.tv-mode-active .location-card,
    body.tv-mode-active .device-card {
        background: #131a29;
        border-color: #253046;
    }

    /*
     * Higher specificity than the plain dark-card rule above so
     * Critical/Warning stay visually loud in TV Mode instead of
     * blending into the same navy as every healthy card — this is
     * the whole point of a wall display: a glance should tell you
     * what's wrong without reading any text.
     */
    body.tv-mode-active .location-card.health-warning,
    body.tv-mode-active .device-card.health-warning,
    body.tv-mode-active .device-row[data-health="warning"] {
        background: #3a2c10;
        border-color: #f0ad4e;
    }

    body.tv-mode-active .location-card.health-critical,
    body.tv-mode-active .device-card.health-critical,
    body.tv-mode-active .device-row[data-health="critical"] {
        animation: cardCriticalGlowTv 1.6s ease-in-out infinite;
        background: #3a1418;
        border-color: #ff5a5a;
    }

    body.tv-mode-active .location-card.health-unknown,
    body.tv-mode-active .device-card.health-unknown,
    body.tv-mode-active .device-row[data-health="unknown"] {
        background: #1e2050;
        border-color: #7986cb;
    }

    body.tv-mode-active .location-header {
        background: #171f30;
        border-color: #253046;
    }

    body.tv-mode-active .location-title,
    body.tv-mode-active .device-card-title,
    body.tv-mode-active .device-name a {
        color: #f4f7fc;
        font-size: 16px;
    }

    body.tv-mode-active .device-meta,
    body.tv-mode-active .location-counts,
    body.tv-mode-active .device-card-meta,
    body.tv-mode-active .recent-event-line {
        color: #9aa7bd;
        font-size: 12px;
    }

    body.tv-mode-active .health-pill {
        font-size: 12px;
        padding: 4px 10px;
    }

    body.tv-mode-active .metric {
        font-size: 12px;
        padding: 4px 7px;
    }

    body.tv-mode-active .metric-missing {
        display: none;
    }

    body.tv-mode-active .empty-filter-result {
        background: #131a29;
        border-color: #333f57;
        color: #cdd6e4;
        font-size: 18px;
        padding: 40px;
    }
</style>

@php
    $metricIcon = function (array $metric): array {
        $label = strtolower($metric['label']);

        if (str_contains($label, 'temperature')) {
            return ['temperature', 'fa-thermometer-full'];
        }

        if (str_contains($label, 'humidity')) {
            return ['humidity', 'fa-tint'];
        }

        if (str_contains($label, 'battery')) {
            return ['battery', 'fa-battery-quarter'];
        }

        if (str_contains($label, 'voltage')) {
            return ['voltage', 'fa-bolt'];
        }

        if (str_contains($label, 'fan')) {
            return ['fan', 'fa-refresh'];
        }

        if (str_contains($label, 'runtime')) {
            return ['runtime', 'fa-clock-o'];
        }

        if (str_contains($label, 'state')) {
            return ['state', 'fa-info-circle'];
        }

        if (str_contains($label, 'storage')) {
            return ['storage', 'fa-hdd-o'];
        }

        if (str_contains($label, 'memory')) {
            return ['memory', 'fa-microchip'];
        }

        if (str_contains($label, 'processor')) {
            return ['processor', 'fa-tachometer'];
        }

        return ['other', 'fa-line-chart'];
    };

    $renderTelemetry = function ($telemetry) use ($metricIcon) {
        if ($telemetry->isEmpty()) {
            return '';
        }

        $html = '<div class="telemetry">';

        foreach ($telemetry as $metric) {
            [$metricType, $icon] = $metricIcon($metric);

            $isStale = $metric['stale'] ?? false;

            $title = e($metric['cause'] ?? $metric['description']);

            if ($metric['lastupdate']) {
                $title .= ' · ' . e($metric['lastupdate']);
            }

            $html .= '<span class="metric metric-' . e($metric['state']) . ' metric-' . e($metricType)
                . ($isStale ? ' metric-is-stale' : '') . '"'
                . ' data-metric-state="' . e($metric['state']) . '"'
                . ' data-metric-type="' . e($metricType) . '"'
                . ' data-metric-stale="' . ($isStale ? '1' : '0') . '"'
                . ' title="' . $title . '">'
                . '<i class="fa ' . e($icon) . ' metric-icon" aria-hidden="true"></i>'
                . '<strong>' . e($metric['label']) . ':</strong> '
                . e($metric['value'])
                . (isset($metric['freshness']['label'])
                    ? '<small class="metric-freshness">' . e($metric['freshness']['label']) . '</small>'
                    : '')
                . '</span>';
        }

        $html .= '</div>';
        $html .= '<div class="telemetry-empty-note" data-telemetry-empty-note>'
            . 'No sensors match the current filters'
            . '</div>';

        return $html;
    };

    $renderIssues = function (array $device, int $limit = 3) {
        $html = '';

        if ($device['maintenance']) {
            $html .= '<div class="service-issue">'
                . '<i class="fa fa-wrench fa-fw" aria-hidden="true"></i> '
                . '<strong>MAINTENANCE</strong> — ' . e($device['maintenance_title'])
                . '</div>';
        }

        if ($device['recovered_recently'] && $device['recovered_at']) {
            $html .= '<div class="recent-event-line">'
                . '<i class="fa fa-check-circle fa-fw" aria-hidden="true"></i> '
                . 'Recovered ' . e($device['recovered_at']->diffForHumans())
                . '</div>';
        }

        foreach ($device['service_problems']->take($limit) as $service) {
            $html .= '<div class="service-issue">'
                . '<strong class="text-' . e($service['status_class']) . '">' . e($service['status_label']) . '</strong>'
                . ' — ' . e($service['name']);

            if ($service['message'] !== '') {
                $html .= '<div class="service-message">' . e($service['message']) . '</div>';
            }

            $html .= '</div>';
        }

        foreach ($device['alerts']->take($limit) as $alert) {
            $html .= '<div class="service-issue alert-issue-' . e($alert['severity_class']) . '">'
                . '<i class="fa fa-bell fa-fw" aria-hidden="true"></i> '
                . '<strong>' . e(strtoupper($alert['severity_class'])) . ' ALERT</strong>'
                . ' — ' . e($alert['name'])
                . '</div>';
        }

        if ($device['recent_event_count'] > 0) {
            $lastEvent = $device['recent_events']->first();

            $html .= '<div class="recent-event-line">'
                . '<i class="fa fa-history fa-fw" aria-hidden="true"></i> '
                . 'Last event: ' . e($lastEvent['message'] !== '' ? $lastEvent['message'] : 'Event logged')
                . ($lastEvent['time'] ? ' (' . e($lastEvent['time']) . ')' : '')
                . '</div>';
        }

        return $html;
    };

    /*
     * The same Settings-configured defaults the JS `defaults` object
     * below needs — embedded here as data on `.infra-dashboard` itself
     * (not only inside the outer <script> block) because this div,
     * unlike the script, IS replaced wholesale by every periodic
     * refreshDashboardData() cycle (see that function's own comment).
     * A `<script>` tag's Blade interpolation only ever runs once, at
     * the browser tab's original page load — so a TV screen left open
     * across an admin's Settings change would otherwise keep obeying
     * the *old* criteria indefinitely, never picking up the new ones
     * until someone manually reloads that specific screen. Reading
     * `defaults` from here instead means every refresh cycle re-reads
     * whatever Settings currently says, matching how `state` already
     * self-corrects via `settingsVersion` — see loadPersistedState().
     */
    $tvDefaults = [
        'critical' => (bool) $config['default_severity_critical'],
        'warning' => (bool) $config['default_severity_warning'],
        'unknown' => (bool) $config['default_severity_unknown'],
        'healthy' => (bool) $config['default_severity_healthy'],

        'temperature' => (bool) $config['default_problem_temperature'],
        'humidity' => (bool) $config['default_problem_humidity'],
        'battery' => (bool) $config['default_problem_battery'],
        'voltage' => (bool) $config['default_problem_voltage'],
        'fan' => (bool) $config['default_problem_fan'],
        'device' => (bool) $config['default_problem_device'],
        'service' => (bool) $config['default_problem_service'],
        'alert' => (bool) $config['default_problem_alert'],
        'state' => (bool) $config['default_problem_state'],
        'storage' => (bool) $config['default_problem_storage'],
        'memory' => (bool) $config['default_problem_memory'],
        'processor' => (bool) $config['default_problem_processor'],
        'stale' => (bool) $config['default_problem_stale'],
        'other' => (bool) $config['default_problem_other'],

        'priority' => (bool) $config['default_section_priority'],
        'coverage' => (bool) $config['default_section_coverage'],
        'summary' => (bool) $config['default_section_summary'],
        'mdfServers' => (bool) $config['default_section_mdfServers'],
        'mdfPower' => (bool) $config['default_section_mdfPower'],
        'mdfInfrastructure' => (bool) $config['default_section_mdfInfrastructure'],
        'idf' => (bool) $config['default_section_idf'],
        'otherLocations' => (bool) $config['default_section_otherLocations'],

        'tvSlideSeconds' => (int) $config['tv_default_slide_seconds'],
    ];

@endphp

<div
    class="infra-dashboard"
    data-idf-defaults="{{ json_encode($tvDefaults) }}"
    data-idf-settings-version="{{ $settingsVersion }}"
    data-idf-refresh-seconds="{{ $refreshSeconds }}"
    data-idf-animations-enabled="{{ $config['animations_enabled'] ? '1' : '0' }}"
    data-idf-severity-definitions="{{ json_encode($severityDefinitions) }}"
>
    {{--
        The exit button used to be `position: fixed`, positioned by
        hand-tuned pixel offsets independent of everything else in the
        header — every time the header's own size changed, those
        offsets needed re-tuning to match, and got it wrong at least
        once (reported: "queda sobrepuesto" — sitting on top of the
        title). Made a real flex child of `.infra-header` instead: in
        TV Mode `.infra-refresh` (the only other thing sharing this
        row) is hidden, so `justify-content: space-between` alone
        pushes this to the header's right edge, vertically centered by
        `align-items: center` — no coordinates to keep in sync by hand
        ever again.
    --}}
    <header class="infra-header">
        <h1 class="infra-title">
            <i class="fa fa-sitemap fa-fw" aria-hidden="true"></i>
            {{ $pluginTitle }}
        </h1>

        <div class="infra-refresh">
            Updated: {{ $generatedAt }}<br>
            Refresh: {{ $refreshSeconds }} seconds ·
            Sensor freshness: {{ $sensorFreshMinutes }} minutes
        </div>

        <button
            type="button"
            class="infra-button tv-exit-button"
            data-action="tv-exit"
        >
            <i class="fa fa-times-circle" aria-hidden="true"></i>
            Exit TV Mode
        </button>
    </header>

    <div
        class="tv-clock"
        data-updated-at="{{ $generatedAt }}"
        data-updated-at-epoch="{{ strtotime($generatedAt) }}"
    ></div>

    <div
        class="tv-status-banner"
        data-critical="{{ $summary['critical_devices'] }}"
        data-warning="{{ $summary['warning_devices'] }}"
        data-down="{{ $summary['devices_down'] }}"
        data-total="{{ $summary['active_devices'] }}"
    ></div>

    <div class="tv-clear-slide" data-dashboard-section="__tv_clear__">
        <div class="tv-clear-icon">
            <i class="fa fa-check-circle" aria-hidden="true"></i>
        </div>
        <div class="tv-clear-title"></div>
        <div class="tv-clear-subtitle"></div>
    </div>

    {{--
        TV Mode's combined "everything that matches, worst first"
        slide — populated in JS (tvBuildCombinedSlides()) with clones
        of whichever devices/location cards from MDF Servers/Power/
        Infrastructure/IDF/Other Locations currently match Settings'
        criteria, sorted Critical-first, shown on one static screen
        with no rotation whenever it all fits, paginated only when
        there's more than fits. Empty by default; never touched
        outside TV Mode. `infra-section-header` reused deliberately
        (not a new class) so tvEstimateChunkSize()'s existing chrome-
        height measurement (which looks for that exact selector)
        works for this slide too, without needing its own special
        case.
    --}}
    <div class="tv-combined-slide" data-tv-combined-slide>
        <header class="infra-section-header">
            <h2 class="infra-section-title">
                <i class="fa fa-exclamation-triangle fa-fw" aria-hidden="true"></i>
                All Issues — Worst First
            </h2>

            <div class="infra-section-meta" data-tv-combined-meta></div>
        </header>

        <div class="tv-combined-grid" data-tv-combined-grid></div>
    </div>

    {{--
        Minimal, operational-only toolbar — Severity/Problem/Section
        checkboxes and the TV transition timer used to live here, but
        those are now organization-wide admin defaults set on the
        Settings page (gear icon → Infrastructure Health Dashboard),
        not something to re-configure from a shared NOC screen. A
        viewer can still switch to "Problems only" / "View all" / "TV
        Mode" for their own session without touching those defaults.
    --}}
    <div class="infra-toolbar">
        <div class="infra-actions">
            <button
                type="button"
                class="infra-button"
                data-action="problems"
                title="Hide Healthy devices only. Leaves Critical/Warning/Needs Review and every problem-type filter exactly as Settings/your session has them — see the summary to the right for what that currently is."
            >
                Problems only
            </button>

            <button
                type="button"
                class="infra-button"
                data-action="all"
                title="Show every severity and every problem type, for this browser session only — does not change the Settings-page defaults."
            >
                View all
            </button>

            <button
                type="button"
                class="infra-button"
                data-action="reset"
                title="Discard this session's filter changes and go back to the Settings-page defaults (may still hide some severities/problem types, by admin design)."
            >
                Reset
            </button>

            <button
                type="button"
                class="infra-button"
                data-action="tv"
                title="Rotate full-screen through whatever devices your current filters show. Exit with the on-screen button or Escape."
            >
                TV Mode
            </button>

            <span class="mode-summary"></span>
            <span class="infra-visible-count"></span>
        </div>
    </div>

    <details class="infra-legend">
        <summary>What do Critical / Warning / the problem tags mean?</summary>

        <div class="legend-grid">
            <div class="legend-item">
                <span class="legend-term legend-critical">Critical</span>
                Device is down, a service check reports CRITICAL, a sensor
                crossed its critical threshold, or an active LibreNMS
                alert is rated critical. A device is never both Critical
                and Warning — Critical always wins.
            </div>

            <div class="legend-item">
                <span class="legend-term legend-warning">Warning</span>
                A service check reports WARNING/UNKNOWN, a sensor crossed
                its warning threshold, power telemetry on a PDU/UPS has
                gone stale while its last known reading was itself
                healthy (a monitoring gap, not a known problem — see
                "Stale data" below), or a non-critical LibreNMS alert is
                active.
            </div>

            <div class="legend-item">
                <span class="legend-term legend-unknown">Needs Review</span>
                A state sensor's current value has no translation
                LibreNMS recognizes — not confirmed healthy, and not
                invented as a problem either. Only applies if nothing
                above is already Critical/Warning.
            </div>

            <div class="legend-item">
                <span class="legend-term legend-healthy">Healthy</span>
                None of the above conditions are present.
            </div>

            <div class="legend-item">
                <span class="legend-term">Device down</span>
                LibreNMS reports the device itself as unreachable.
            </div>

            <div class="legend-item">
                <span class="legend-term">Temp / Humidity / Battery / Voltage / Fan</span>
                The specific sensor reading that crossed its threshold.
                Suppressed if alerting was disabled for that sensor in
                LibreNMS, to avoid false criticals.
            </div>

            <div class="legend-item">
                <span class="legend-term">State sensor</span>
                A discrete/enum LibreNMS sensor (e.g. "System Status",
                "Battery Status") whose current value decodes — via
                LibreNMS's own state_translations table, not a numeric
                threshold — to a Warning/Critical description instead
                of a plain number.
            </div>

            <div class="legend-item">
                <span class="legend-term">Service</span>
                A configured LibreNMS service check is not OK.
            </div>

            <div class="legend-item">
                <span class="legend-term">Alert</span>
                An active, open LibreNMS alert rule fired for this
                device (independent of the sensors/services above).
            </div>

            <div class="legend-item">
                <span class="legend-term">Stale data</span>
                A sensor hasn't reported a fresh value in over
                {{ $sensorFreshMinutes }} minutes (shown with a dashed
                border and a small clock mark, plus how long ago it was
                last seen). This never softens severity — a battery
                last seen at 0% still shows Critical, it just also
                tells you the reading itself is old. On a PDU/UPS, a
                stale sensor whose last known reading was healthy is
                treated as a Warning on its own, since losing
                visibility into critical power infrastructure is worth
                flagging even with no bad reading on record.
            </div>
        </div>
    </details>

    @if(count($priorityAttention['items']) > 0)
        {{--
            data-priority-panel (not data-dashboard-section) on
            purpose: data-dashboard-section drives TV Mode's slide-
            rotation hide/show sweep (`body.tv-mode-active
            [data-dashboard-section] { display: none !important }`,
            with only the active slide shown) — this panel is a
            persistent always-visible header, not a section to rotate
            through, same treatment as the TV status banner. It still
            needs its own admin-configurable on/off toggle (Settings →
            Sections → "Priority Attention"), wired separately in
            applyFilters() below via this attribute.
        --}}
        <div class="priority-panel" data-priority-panel>
            <div class="priority-header">
                Priority Attention
                <span class="priority-count">
                    {{ count($priorityAttention['items']) }}
                    @if($priorityAttention['total'] > count($priorityAttention['items']))
                        of {{ $priorityAttention['total'] }}
                    @endif
                    active issue{{ $priorityAttention['total'] === 1 ? '' : 's' }}
                </span>
            </div>

            <div class="priority-list">
                @foreach($priorityAttention['items'] as $item)
                    <a
                        href="{{ url('device/device=' . $item['device_id']) }}"
                        class="priority-item priority-{{ $item['severity'] }}"
                    >
                        <span class="priority-severity">
                            <i class="fa fa-{{ $item['icon'] }}" aria-hidden="true"></i>
                            {{ strtoupper($item['severity']) }}
                        </span>
                        <span class="priority-location">{{ e($item['location']) }}</span>
                        <span class="priority-device">{{ e($item['device_name']) }}</span>
                        <span class="priority-role">{{ strtoupper($item['category']) }}</span>
                        <span class="priority-cause">{{ e($item['cause']) }}</span>
                        <span class="priority-since">
                            {{ $item['since'] ? 'Since ' . $item['since'] : '' }}
                            @if($item['additional_count'] > 0)
                                · +{{ $item['additional_count'] }} more
                            @endif
                        </span>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    <div
        class="coverage-panel"
        data-dashboard-section="coverage"
    >
        <div class="coverage-card">
            <div class="coverage-label">Active devices</div>
            <div class="coverage-value">{{ $coverage['active_devices'] }}</div>
            <div class="coverage-subtitle">
                {{ $coverage['idf_count'] }} IDF ·
                {{ $coverage['mdf_count'] }} MDF ·
                {{ $coverage['other_count'] }} Other ·
                {{ $coverage['unassigned_count'] }} Unassigned
                @if($coverage['ignored_count'] > 0)
                    · {{ $coverage['ignored_count'] }} ignored (excluded)
                @endif
                @if($coverage['disabled_count'] > 0)
                    · {{ $coverage['disabled_count'] }} disabled (excluded)
                @endif
            </div>
        </div>

        <div class="coverage-card {{ $coverage['location_ok'] ? 'coverage-good' : 'coverage-bad' }}">
            <div class="coverage-label">Location Assigned</div>
            <div class="coverage-value">{{ $coverage['location_percent'] }}%</div>
            <div class="coverage-subtitle">
                Devices grouped under a named IDF/MDF/other location,
                not left "Unassigned" in LibreNMS.
            </div>
        </div>

        <div class="coverage-card {{ $coverage['power_sensor_ok'] ? 'coverage-good' : 'coverage-bad' }}">
            <div class="coverage-label">Power Sensor Coverage</div>
            <div class="coverage-value">{{ $coverage['power_sensor_percent'] }}%</div>
            <div class="coverage-subtitle">
                {{ $coverage['power_sensor_covered'] }} of {{ $coverage['power_devices'] }}
                PDU/UPS devices have at least one real sensor reading
                (fresh or stale — "missing" means no sensor at all).
            </div>
        </div>

        <div class="coverage-card coverage-neutral">
            <div class="coverage-label">Service Check Coverage</div>
            <div class="coverage-value">{{ $coverage['service_percent'] }}%</div>
            <div class="coverage-subtitle">
                {{ $coverage['service_covered'] }} of {{ $coverage['active_devices'] }}
                devices have at least one configured service check.
            </div>
        </div>

        <div class="coverage-card {{ $coverage['alert_ok'] ? 'coverage-good' : 'coverage-bad' }}">
            <div class="coverage-label">Alert Monitoring</div>
            <div class="coverage-value">
                {{ $coverage['alerts_available'] ? $coverage['devices_with_active_alerts'] : 'N/A' }}
            </div>
            <div class="coverage-subtitle">
                @if($coverage['alerts_available'])
                    {{ $coverage['active_alert_count'] }} active LibreNMS alert(s)
                    across {{ $coverage['devices_with_active_alerts'] }} device(s).
                @else
                    LibreNMS alert tables were not found in this schema.
                @endif
            </div>
        </div>

        <div class="coverage-card coverage-neutral">
            <div class="coverage-label">Event Log Activity (24h)</div>
            <div class="coverage-value">
                @if($coverage['events_available'] && $coverage['events_complete'])
                    {{ $coverage['event_percent'] }}%
                @elseif($coverage['events_available'])
                    Limited
                @else
                    N/A
                @endif
            </div>
            <div class="coverage-subtitle">
                @if($coverage['events_available'] && $coverage['events_complete'])
                    {{ $coverage['devices_with_recent_events'] }} of {{ $coverage['active_devices'] }}
                    devices logged an event in the last {{ $eventWindowHours }} hours.
                @elseif($coverage['events_available'])
                    Global safety limit reached; coverage percentage is intentionally suppressed.
                @else
                    LibreNMS eventlog table was not found in this schema.
                @endif
            </div>
        </div>
    </div>

    <div
        class="summary-panel"
        data-dashboard-section="summary"
    >
        <div class="summary-card">
            <div class="summary-label">Devices up</div>
            <div class="summary-value status-up">
                {{ $summary['devices_up'] }}
            </div>
        </div>

        <div class="summary-card">
            <div class="summary-label">Devices down</div>
            <div class="summary-value {{ $summary['devices_down'] > 0 ? 'status-down' : 'status-up' }}">
                {{ $summary['devices_down'] }}
            </div>
        </div>

        <div class="summary-card">
            <div class="summary-label">Critical devices</div>
            <div class="summary-value text-danger">
                {{ $summary['critical_devices'] }}
            </div>
        </div>

        <div class="summary-card">
            <div class="summary-label">Warning devices</div>
            <div class="summary-value text-warning">
                {{ $summary['warning_devices'] }}
            </div>
        </div>

        <div class="summary-card">
            <div class="summary-label">Needs review</div>
            <div class="summary-value text-info">
                {{ $summary['unknown_devices'] }}
            </div>
        </div>

        <div class="summary-card">
            <div class="summary-label">Service issues</div>
            <div class="summary-value {{ $summary['service_problems'] > 0 ? 'text-danger' : 'status-up' }}">
                {{ $summary['service_problems'] }}
            </div>
        </div>

        <div class="summary-card">
            <div class="summary-label">Active alerts</div>
            <div class="summary-value {{ $summary['active_alerts'] > 0 ? 'text-danger' : 'status-up' }}">
                {{ $summary['active_alerts'] }}
            </div>
        </div>

        <div class="summary-card">
            <div class="summary-label">Stale power sensors</div>
            <div class="summary-value {{ $summary['stale_sensor_devices'] > 0 ? 'text-warning' : 'status-up' }}">
                {{ $summary['stale_sensor_devices'] }}
            </div>
        </div>

        <div class="summary-card">
            <div class="summary-label">No sensor installed</div>
            <div class="summary-value text-muted">
                {{ $summary['no_sensor_installed'] }}
            </div>
        </div>
    </div>

    <div class="empty-filter-result">
        <i class="fa fa-check-circle fa-fw" aria-hidden="true"></i>
        No devices match the selected filters.
    </div>

    <section
        class="infra-section"
        data-dashboard-section="mdfServers"
    >
        <header class="infra-section-header">
            <h2 class="infra-section-title">
                <i class="fa fa-server fa-fw" aria-hidden="true"></i>
                MDF Servers
            </h2>

            <div class="infra-section-meta">
                {{ $mdf['server_count'] }} devices
            </div>
        </header>

        <div class="infra-section-body">
            <div class="device-card-grid">
                @foreach($mdf['servers'] as $device)
                    <article
                        class="monitor-device device-card health-{{ $device['health'] }}"
                        data-health="{{ $device['health'] }}"
                        data-problems="{{ implode(',', $device['problem_types']) }}"
                    >

                        <div class="device-card-title">
                            <a href="{{ url('device/device=' . $device['device_id']) }}">
                                {{ $device['name'] }}
                            </a>

                            <span class="{{ $device['maintenance'] ? 'text-info' : ($device['status'] ? 'status-up' : 'status-down') }}">
                                {{ $device['maintenance'] ? 'MAINTENANCE' : ($device['status'] ? 'UP' : 'DOWN') }}
                            </span>
                        </div>

                        <div class="device-card-meta">
                            {{ $device['hostname'] }} ·
                            {{ strtoupper($device['os']) }}
                        </div>

                        {!! $renderTelemetry($device['telemetry']) !!}

                        <div class="service-summary">
                            @if($device['service_total'] === 0)
                                <span class="text-muted">
                                    No service checks configured
                                </span>
                            @elseif($device['service_problem_count'] === 0)
                                <span class="status-up">
                                    <i class="fa fa-check-circle fa-fw"></i>
                                    {{ $device['service_total'] }} services OK
                                </span>
                            @else
                                <span class="status-down">
                                    <i class="fa fa-exclamation-circle fa-fw"></i>
                                    {{ $device['service_problem_count'] }}
                                    of {{ $device['service_total'] }}
                                    services have issues
                                </span>
                            @endif
                        </div>

                        {!! $renderIssues($device) !!}
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    <section
        class="infra-section"
        data-dashboard-section="mdfPower"
    >
        <header class="infra-section-header">
            <h2 class="infra-section-title">
                <i class="fa fa-bolt fa-fw" aria-hidden="true"></i>
                MDF Power
            </h2>

            <div class="infra-section-meta">
                {{ $mdf['power_count'] }} PDU/UPS devices
            </div>
        </header>

        <div class="infra-section-body">
            <div class="device-card-grid">
                @foreach($mdf['power'] as $device)
                    <article
                        class="monitor-device device-card health-{{ $device['health'] }}"
                        data-health="{{ $device['health'] }}"
                        data-problems="{{ implode(',', $device['problem_types']) }}"
                    >

                        <div class="device-card-title">
                            <a href="{{ url('device/device=' . $device['device_id']) }}">
                                {{ $device['name'] }}
                            </a>

                            <span class="{{ $device['maintenance'] ? 'text-info' : ($device['status'] ? 'status-up' : 'status-down') }}">
                                {{ $device['maintenance'] ? 'MAINTENANCE' : ($device['status'] ? 'UP' : 'DOWN') }}
                            </span>
                        </div>

                        <div class="device-card-meta">
                            <span title="{{ e($device['classification']['reason']) }}">{{ strtoupper($device['category']) }}</span> ·
                            {{ $device['hostname'] }}
                        </div>

                        {!! $renderTelemetry($device['telemetry']) !!}

                        {!! $renderIssues($device) !!}
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    <section
        class="infra-section"
        data-dashboard-section="mdfInfrastructure"
    >
        <header class="infra-section-header">
            <h2 class="infra-section-title">
                <i class="fa fa-random fa-fw" aria-hidden="true"></i>
                MDF Infrastructure
            </h2>

            <div class="infra-section-meta">
                {{ $mdf['infrastructure_count'] }}
                network, firewall, wireless and management devices
            </div>
        </header>

        <div class="infra-section-body">
            <div class="device-card-grid">
                @foreach($mdf['infrastructure'] as $device)
                    <article
                        class="monitor-device device-card health-{{ $device['health'] }}"
                        data-health="{{ $device['health'] }}"
                        data-problems="{{ implode(',', $device['problem_types']) }}"
                    >

                        <div class="device-card-title">
                            <a href="{{ url('device/device=' . $device['device_id']) }}">
                                {{ $device['name'] }}
                            </a>

                            <span class="{{ $device['maintenance'] ? 'text-info' : ($device['status'] ? 'status-up' : 'status-down') }}">
                                {{ $device['maintenance'] ? 'MAINTENANCE' : ($device['status'] ? 'UP' : 'DOWN') }}
                            </span>
                        </div>

                        <div class="device-card-meta">
                            <span title="{{ e($device['classification']['reason']) }}">{{ strtoupper($device['category']) }}</span> ·
                            {{ $device['hostname'] }} ·
                            {{ $device['os'] }}
                        </div>

                        {!! $renderTelemetry($device['telemetry']) !!}

                        {!! $renderIssues($device) !!}
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    <section
        class="infra-section"
        data-dashboard-section="idf"
    >
        <header class="infra-section-header">
            <h2 class="infra-section-title">
                <i class="fa fa-building fa-fw" aria-hidden="true"></i>
                IDF Locations
            </h2>

            <div class="infra-section-meta">
                {{ $summary['idf_locations'] }} locations ·
                {{ $summary['idf_devices'] }} devices
            </div>
        </header>

        <div class="infra-section-body">
            <div class="location-grid">
                @foreach($locations as $location)
                    <section
                        class="location-card health-{{ $location['health'] }}"
                        data-location-card
                    >
                        <header class="location-header">
                            <h3 class="location-title">
                                {{ $location['name'] }}
                            </h3>

                            <span class="health-pill pill-{{ $location['health'] }}">
                                {{ $location['health'] }}
                            </span>
                        </header>

                        <div class="location-counts">
                            {{ $location['total'] }} devices ·
                            {{ $location['up'] }} UP ·
                            {{ $location['down'] }} DOWN ·
                            <span data-visible-devices>
                                {{ $location['total'] }}
                            </span>
                            visible

                            <span class="tv-card-issue-badge" data-tv-issue-badge></span>
                        </div>

                        <ul class="location-device-list">
                            @foreach($location['devices'] as $device)
                                <li
                                    class="monitor-device device-row"
                                    data-health="{{ $device['health'] }}"
                                    data-problems="{{ implode(',', $device['problem_types']) }}"
                                >

                                    <div class="device-main">
                                        <i
                                            class="fa fa-circle {{ $device['maintenance'] ? 'text-info' : ($device['status'] ? 'status-up' : 'status-down') }}"
                                            aria-hidden="true"
                                        ></i>

                                        <div class="device-name">
                                            <a href="{{ url('device/device=' . $device['device_id']) }}">
                                                {{ $device['name'] }}
                                            </a>

                                            <div class="device-meta">
                                                {{ $device['hostname'] }} ·
                                                <span title="{{ e($device['classification']['reason']) }}">{{ strtoupper($device['category']) }}</span> ·
                                                {{ $device['os'] }}
                                            </div>
                                        </div>

                                        <span class="device-state {{ $device['maintenance'] ? 'text-info' : ($device['status'] ? 'status-up' : 'status-down') }}">
                                            {{ $device['maintenance'] ? 'MAINTENANCE' : ($device['status'] ? 'UP' : 'DOWN') }}
                                        </span>
                                    </div>

                                    {!! $renderTelemetry($device['telemetry']) !!}

                                    {!! $renderIssues($device, 2) !!}
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach
            </div>
        </div>
    </section>

    <section
        class="infra-section"
        data-dashboard-section="otherLocations"
    >
        <header class="infra-section-header">
            <h2 class="infra-section-title">
                <i class="fa fa-map-marker fa-fw" aria-hidden="true"></i>
                Other Locations
            </h2>

            <div class="infra-section-meta">
                {{ $summary['other_locations'] }} locations ·
                {{ $summary['other_devices'] }} devices
            </div>
        </header>

        <div class="infra-section-body">
            <div class="location-grid">
                @foreach($otherLocations as $location)
                    <section
                        class="location-card health-{{ $location['health'] }}"
                        data-location-card
                    >
                        <header class="location-header">
                            <h3 class="location-title">
                                {{ $location['name'] }}
                            </h3>

                            <span class="health-pill pill-{{ $location['health'] }}">
                                {{ $location['health'] }}
                            </span>
                        </header>

                        <div class="location-counts">
                            {{ $location['total'] }} devices ·
                            {{ $location['up'] }} UP ·
                            {{ $location['down'] }} DOWN ·
                            <span data-visible-devices>
                                {{ $location['total'] }}
                            </span>
                            visible

                            <span class="tv-card-issue-badge" data-tv-issue-badge></span>
                        </div>

                        <ul class="location-device-list">
                            @foreach($location['devices'] as $device)
                                <li
                                    class="monitor-device device-row"
                                    data-health="{{ $device['health'] }}"
                                    data-problems="{{ implode(',', $device['problem_types']) }}"
                                >

                                    <div class="device-main">
                                        <i
                                            class="fa fa-circle {{ $device['maintenance'] ? 'text-info' : ($device['status'] ? 'status-up' : 'status-down') }}"
                                            aria-hidden="true"
                                        ></i>

                                        <div class="device-name">
                                            <a href="{{ url('device/device=' . $device['device_id']) }}">
                                                {{ $device['name'] }}
                                            </a>

                                            <div class="device-meta">
                                                {{ $device['hostname'] }} ·
                                                <span title="{{ e($device['classification']['reason']) }}">{{ strtoupper($device['category']) }}</span> ·
                                                {{ $device['os'] }}
                                            </div>
                                        </div>

                                        <span class="device-state {{ $device['maintenance'] ? 'text-info' : ($device['status'] ? 'status-up' : 'status-down') }}">
                                            {{ $device['maintenance'] ? 'MAINTENANCE' : ($device['status'] ? 'UP' : 'DOWN') }}
                                        </span>
                                    </div>

                                    {!! $renderTelemetry($device['telemetry']) !!}

                                    {!! $renderIssues($device, 2) !!}
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach
            </div>
        </div>
    </section>
</div>

<script>
// A full `window.location.reload()` every {{ $refreshSeconds }}s used to
// cause the visible white-flash/re-layout "flicker" on this page (and
// especially on a TV-mode wall display). `refreshDashboardData()` below
// re-fetches the same URL and swaps only the `.infra-dashboard`
// markup in place, then re-runs `initDashboard()` to rewire it — no
// navigation, no flash. These three handles live outside
// `initDashboard()` (rather than as local `let`s re-declared on every
// call) specifically so each refresh cycle can clear the *previous*
// cycle's timers before starting new ones instead of leaking an extra
// overlapping interval/listener every {{ $refreshSeconds }} seconds.
let dashboardRefreshTimer = null;
let tvRotationTimer = null;
let tvClockTimer = null;
let dashboardKeydownHandler = null;
let dashboardRefreshFailures = 0;
let dashboardRefreshController = null;
let dashboardConnectionState = 'connected';
let dashboardRefreshStartedAt = null;
let dashboardUpdateClock = null;
let refreshSeconds = 30;

// Both live outside initDashboard() for the same reason as the timers
// above: a periodic data refresh calls initDashboard() again while TV
// Mode stays on, and it must resume the rotation where it left off
// rather than snapping back to slide 0 — otherwise, since a refresh
// happens every {{ $refreshSeconds }}s and each slide is only shown
// for `tvSlideMs` (12s), the rotation could never advance past
// whichever slide is a few positions in (it kept getting reset before
// reaching IDF/Other Locations, which sort near the end of the slide
// list) every single cycle.
let tvSlideIndex = 0;
let tvWasActive = false;

// Real measurements from the DOM, replacing hardcoded height guesses
// (see tvMeasureCardHeights()/tvEstimateChunkSize() below) — a real
// photo of this dashboard on an actual 1080p TV showed the old
// guessed constants left more than half the screen empty every slide.
// Two separate heights because MDF sections paginate individual
// `.device-card`s while IDF/Other Locations paginate whole
// `.location-card`s (each containing several stacked device rows,
// so much taller) — one shared guess for both was itself part of the
// original problem.
let tvMeasuredDeviceCardHeight = null;
let tvMeasuredLocationCardHeight = null;

function initDashboard() {
    const storageKeyV1 = 'InfrastructureHealthDashboard.filters.v1';
    const storageKey = 'InfrastructureHealthDashboard.filters.v2';
    const settingsVersionKey = 'InfrastructureHealthDashboard.settingsVersion.v1';
    const tvStorageKey = 'InfrastructureHealthDashboard.tvMode.v1';

    const dashboardEl = document.querySelector('.infra-dashboard');
    let severityDefinitions = {};

    try {
        severityDefinitions = JSON.parse(
            dashboardEl ? dashboardEl.dataset.idfSeverityDefinitions || '{}' : '{}'
        );
    } catch (error) {
        severityDefinitions = {};
    }

    // Changes every time an admin saves the Settings page (see
    // settingsVersion in Page.php::data()) — a hash of every resolved
    // setting, not just the filter defaults, so any Settings save
    // re-syncs an already-open browser/TV. Compared in
    // loadPersistedState() below.
    // Read from replaceable dashboard markup so a soft refresh sees
    // the new version instead of retaining the original script value.
    const settingsVersion = dashboardEl
        ? dashboardEl.dataset.idfSettingsVersion || ''
        : '';

    // Every boolean/number below is an admin-configured default from
    // the plugin's Settings page (Support/Config.php), not hardcoded —
    // see Settings.php / settings.blade.php. A viewer's own session
    // can still diverge locally via "Problems only"/"View all"/the
    // checkboxes without touching these org-wide defaults.
    //
    // Read from `.infra-dashboard`'s own `data-idf-defaults` (set in
    // page.blade.php right above that div) rather than interpolated
    // directly into this script as literals: this script tag is
    // OUTSIDE `.infra-dashboard` and only ever runs once, at the
    // browser tab's original page load — refreshDashboardData()'s
    // periodic soft-refresh replaces `.infra-dashboard` wholesale but
    // never re-executes this script. A `const defaults = { critical:
    // true, ... }` baked in here would freeze TV Mode on whatever
    // Settings said the moment that specific tab first opened,
    // forever — exactly what was reported: an already-open TV screen
    // kept ignoring Settings changes (and this very fix) until
    // reloaded by hand. Re-reading it from the DOM on every
    // initDashboard() call (which does re-run each refresh) means an
    // admin's Settings change reaches an unattended screen on its next
    // 30-second data refresh, no manual reload needed — matching how
    // `state` already self-corrects via `settingsVersion` below.
    const defaults = dashboardEl && dashboardEl.dataset.idfDefaults
        ? JSON.parse(dashboardEl.dataset.idfDefaults)
        : {};

    const parsedRefreshSeconds = dashboardEl
        ? Number.parseInt(dashboardEl.dataset.idfRefreshSeconds, 10)
        : NaN;

    refreshSeconds = Number.isFinite(parsedRefreshSeconds)
        ? parsedRefreshSeconds
        : 30;

    const animationsEnabled = dashboardEl
        ? dashboardEl.dataset.idfAnimationsEnabled === '1'
        : true;

    // Admin-controlled, dashboard-wide kill switch for every
    // animation (severity glow, alert pulse) — see the
    // ".animations-disabled" rules in <style>. Colors and text still
    // fully reflect severity either way; only motion stops.
    document.body.classList.toggle('animations-disabled', !animationsEnabled);

    // Keys in `defaults` whose value is a bounded number rather than a
    // boolean toggle — loadPersistedState() clamps these instead of
    // coercing them with Boolean(), which would collapse e.g. `12`
    // into `true`.
    const numericSettings = {
        tvSlideSeconds: { min: 5, max: 120 }
    };

    const severityKeys = ['critical', 'warning', 'unknown', 'healthy'];

    // Everything in `defaults` that isn't a severity, a dashboard
    // section, or the TV timer — i.e. every checkbox that can make a
    // real problem invisible without the severity badge itself
    // changing. "Problems only" deliberately never touches these (see
    // problemsPreset in currentPresetName() below) — it only affects
    // whether Healthy devices show. The mode-summary line uses this
    // list to say so honestly instead of leaving a silent gap between
    // what a viewer thinks "Problems only"/"Reset" show and what's
    // actually on screen.
    const problemTypeKeys = [
        'temperature', 'humidity', 'battery', 'voltage', 'fan',
        'device', 'service', 'alert', 'state', 'storage', 'memory',
        'processor', 'stale', 'other'
    ];

    function loadPersistedState() {
        let raw = null;
        let storedSettingsVersion = null;

        try {
            raw = window.localStorage.getItem(storageKey);
            storedSettingsVersion = window.localStorage.getItem(settingsVersionKey);

            if (raw === null) {
                const legacy = window.localStorage.getItem(storageKeyV1);

                if (legacy !== null) {
                    raw = legacy;
                    window.localStorage.removeItem(storageKeyV1);
                }
            }
        } catch (error) {
            raw = null;
        }

        // An admin saving Settings changes settingsVersion server-side;
        // a mismatch here means this browser's persisted filter state
        // predates that save. Discard it and re-adopt the fresh
        // defaults below, exactly as if "Reset" had just been clicked
        // — without this, a Settings change never took visible effect
        // on an already-open TV/browser until someone manually clicked
        // Reset (reported: "no revisa los setting, tengo que dar
        // reset"). A viewer can still diverge locally afterward; that
        // local choice only gets invalidated the next time Settings
        // actually change again.
        if (storedSettingsVersion !== settingsVersion) {
            raw = null;
        }

        try {
            window.localStorage.setItem(settingsVersionKey, settingsVersion);
        } catch (error) {
            // Storage unavailable — falls through to defaults every
            // load, which is safe, just not persisted.
        }

        let parsed = {};

        try {
            parsed = raw ? JSON.parse(raw) : {};
        } catch (error) {
            parsed = {};
        }

        const sanitized = {};

        Object.keys(defaults).forEach(function (key) {
            const has = Object.prototype.hasOwnProperty.call(parsed, key);
            const bounds = numericSettings[key];

            if (bounds) {
                const parsedNumber = has ? parseInt(parsed[key], 10) : NaN;

                sanitized[key] = Number.isFinite(parsedNumber)
                    ? Math.min(bounds.max, Math.max(bounds.min, parsedNumber))
                    : defaults[key];

                return;
            }

            sanitized[key] = has ? Boolean(parsed[key]) : defaults[key];
        });

        return sanitized;
    }

    const state = loadPersistedState();

    // How long each TV Mode slide stays on screen — user-controlled
    // via the "TV transition" number input, persisted like every
    // other setting. Re-derived fresh on every initDashboard() call
    // (including after a data refresh), so a change always takes
    // effect without needing special-case invalidation logic.
    let tvSlideMs = state.tvSlideSeconds * 1000;

    function saveState() {
        try {
            window.localStorage.setItem(storageKey, JSON.stringify(state));
        } catch (error) {
            // Storage unavailable (private browsing, quota, etc.) — the
            // dashboard still works for this session, it just will not
            // remember filters on the next load.
        }
    }

    function matchesPreset(preset) {
        return Object.keys(preset).every(function (key) {
            return Boolean(state[key]) === Boolean(preset[key]);
        });
    }

    function currentPresetName() {
        const allPreset = {};

        Object.keys(defaults).forEach(function (key) {
            if (key === 'coverage' || key === 'summary' || key === 'priority' || numericSettings[key]) {
                return;
            }

            allPreset[key] = true;
        });

        if (matchesPreset(allPreset)) {
            return 'all';
        }

        // "Problems only" no longer forces Critical/Warning/Needs
        // Review on (see its click handler below for why) — it only
        // ever turns Healthy off, so that's the only thing left to
        // check for here. Reported directly: an admin who set
        // Settings to Critical-only still saw Warning devices the
        // moment they clicked "Problems only" on the live dashboard,
        // because this used to hard-code all three "problem"
        // severities to true regardless of what Settings said.
        if (!state.healthy) {
            return 'problems';
        }

        return 'custom';
    }

    function updateButtonStates() {
        const preset = currentPresetName();

        const problemsBtn = document.querySelector('[data-action="problems"]');
        const allBtn = document.querySelector('[data-action="all"]');

        if (problemsBtn) {
            problemsBtn.classList.toggle('is-active', preset === 'problems');
        }

        if (allBtn) {
            allBtn.classList.toggle('is-active', preset === 'all');
        }

        const summaryEl = document.querySelector('.mode-summary');

        if (!summaryEl) {
            return;
        }

        const activeSeverities = severityKeys.filter(function (key) {
            return state[key];
        });

        let text;

        if (activeSeverities.length === 0) {
            text = 'Nothing selected — every device is hidden';
        } else if (activeSeverities.length === severityKeys.length) {
            text = 'Showing all severities';
        } else {
            text = 'Showing: ' + activeSeverities.map(function (severity) {
                return severity.charAt(0).toUpperCase() + severity.slice(1);
            }).join(' + ');
        }

        // Neither "Problems only" nor "Reset" guarantee every problem
        // type is visible (Reset can land on Settings-page defaults
        // that hide some; "Problems only" never touches this dimension
        // at all) — so the summary line names what's hidden here too,
        // not just severities, the same way a device card names what a
        // Critical badge's hidden metrics are (see AUDIT_NOTES.md).
        const hiddenProblemTypes = problemTypeKeys.filter(function (key) {
            return !state[key];
        });

        if (hiddenProblemTypes.length > 0) {
            text += ' · ' + hiddenProblemTypes.length
                + (hiddenProblemTypes.length === 1 ? ' problem type hidden' : ' problem types hidden');
        }

        summaryEl.textContent = text;
        summaryEl.title = hiddenProblemTypes.length > 0
            ? 'Hidden: ' + hiddenProblemTypes.join(', ')
            : '';
        summaryEl.classList.toggle('mode-summary-empty', activeSeverities.length === 0);
    }

    function parseProblems(device) {
        const value = device.dataset.problems || '';

        return value
            .split(',')
            .map(function (item) {
                return item.trim();
            })
            .filter(Boolean);
    }

    function deviceMatches(device) {
        const health = device.dataset.health || 'healthy';

        if (health === 'maintenance') {
            return Boolean(state.healthy);
        }

        if (!state[health]) {
            return false;
        }

        if (health === 'healthy') {
            return true;
        }

        const problems = parseProblems(device);

        if (problems.length === 0) {
            return state.other;
        }

        return problems.some(function (problem) {
            return Boolean(state[problem]);
        });
    }

    const metricTypeFilterKey = {
        temperature: 'temperature',
        humidity: 'humidity',
        battery: 'battery',
        voltage: 'voltage',
        fan: 'fan',
        runtime: 'other',
        state: 'state',
        storage: 'storage',
        memory: 'memory',
        processor: 'processor',
        other: 'other'
    };

    // `metricState` is always the real severity computed from the
    // metric's last known value (critical/warning/healthy/missing) —
    // Page.php never softens it just because the reading is old (a
    // UPS battery last seen at 0% stays Critical). Staleness is a
    // separate `data-metric-stale` flag, purely about how much to
    // trust the timestamp, and only gates visibility for the one case
    // where it is the *entire* story: a stale reading whose last
    // known value was itself healthy (no evidence of a problem, just
    // lost visibility). A stale Critical/Warning reading is a real
    // problem and is never hidden by the "Stale data" checkbox — only
    // by the normal Severity/Problem checkboxes, like any other one.
    function metricMatches(metricEl) {
        const metricState = metricEl.dataset.metricState;
        const metricType = metricEl.dataset.metricType;
        const isStale = metricEl.dataset.metricStale === '1';

        // Legacy missing rows remain hidden. The explicit no_sensor
        // state is intentionally visible and informational.
        if (metricState === 'missing') {
            return false;
        }

        const filterKey = metricTypeFilterKey[metricType] || 'other';

        if (metricState === 'no_sensor') {
            return Boolean(state[filterKey]);
        }

        if (isStale && metricState === 'healthy' && !state.stale) {
            return false;
        }

        if (!state[metricState]) {
            return false;
        }

        return Boolean(state[filterKey]);
    }

    // Beyond deciding which whole devices appear, each visible card
    // only shows the sensors whose severity (Critical/Warning/Healthy)
    // AND type (Temperature/Humidity/.../Other) are both checked above
    // — this is what stops a mostly-healthy PDU card from burying its
    // one critical humidity reading under a wall of unrelated "No
    // recent data" placeholders.
    function applyMetricVisibility(device) {
        const metrics = device.querySelectorAll('.metric');
        let anyVisible = false;
        const hiddenBySeverity = { critical: 0, warning: 0 };

        metrics.forEach(function (metricEl) {
            const show = metricMatches(metricEl);

            metricEl.classList.toggle('metric-filtered', !show);

            if (show) {
                anyVisible = true;
            } else if (metricEl.dataset.metricState in hiddenBySeverity) {
                hiddenBySeverity[metricEl.dataset.metricState]++;
            }
        });

        const emptyNote = device.querySelector('[data-telemetry-empty-note]');

        if (emptyNote) {
            const showEmptyNote = metrics.length > 0 && !anyVisible;

            emptyNote.classList.toggle('telemetry-empty-visible', showEmptyNote);

            // A card can show Critical/Warning even with zero visible
            // metrics — the badge always reflects real severity,
            // independent of what the viewer's filters currently show
            // (see AUDIT_NOTES.md). Left as a bare "No sensors match"
            // note, that reads as a bug ("why is this red with nothing
            // shown?"); naming what's hidden makes the badge legible
            // again without changing what the filters actually do.
            if (showEmptyNote) {
                const hidden = [];

                if (hiddenBySeverity.critical > 0) {
                    hidden.push(hiddenBySeverity.critical + ' Critical');
                }

                if (hiddenBySeverity.warning > 0) {
                    hidden.push(hiddenBySeverity.warning + ' Warning');
                }

                emptyNote.textContent = hidden.length > 0
                    ? 'No sensors match the current filters — hiding ' + hidden.join(', ')
                    : 'No sensors match the current filters';
            }
        }
    }

    function filterStandaloneSection(sectionName) {
        const section = document.querySelector(
            '[data-dashboard-section="' + sectionName + '"]'
        );

        if (!section) {
            return 0;
        }

        if (!state[sectionName]) {
            section.style.display = 'none';
            return 0;
        }

        let visible = 0;

        section.querySelectorAll(
            '.monitor-device'
        ).forEach(function (device) {
            const show = deviceMatches(device);

            device.style.display = show ? '' : 'none';
            applyMetricVisibility(device);

            if (show) {
                visible += 1;
            }
        });

        section.style.display = visible > 0 ? '' : 'none';

        return visible;
    }

    function filterLocationSection(sectionName) {
        const section = document.querySelector(
            '[data-dashboard-section="' + sectionName + '"]'
        );

        if (!section) {
            return 0;
        }

        if (!state[sectionName]) {
            section.style.display = 'none';
            return 0;
        }

        let totalVisible = 0;

        section.querySelectorAll(
            '[data-location-card]'
        ).forEach(function (locationCard) {
            let locationVisible = 0;

            locationCard.querySelectorAll(
                '.monitor-device'
            ).forEach(function (device) {
                const show = deviceMatches(device);

                device.style.display = show ? '' : 'none';
                applyMetricVisibility(device);

                if (show) {
                    locationVisible += 1;
                    totalVisible += 1;
                }
            });

            locationCard.style.display =
                locationVisible > 0 ? '' : 'none';

            const visibleLabel = locationCard.querySelector(
                '[data-visible-devices]'
            );

            if (visibleLabel) {
                visibleLabel.textContent = locationVisible;
            }
        });

        section.style.display =
            totalVisible > 0 ? '' : 'none';

        return totalVisible;
    }

    function applyFilters() {
        const priorityPanel = document.querySelector('[data-priority-panel]');

        if (priorityPanel) {
            priorityPanel.style.display = state.priority ? '' : 'none';
        }

        const coveragePanel = document.querySelector(
            '[data-dashboard-section="coverage"]'
        );

        if (coveragePanel) {
            coveragePanel.style.display =
                state.coverage ? '' : 'none';
        }

        const summaryPanel = document.querySelector(
            '[data-dashboard-section="summary"]'
        );

        if (summaryPanel) {
            summaryPanel.style.display =
                state.summary ? '' : 'none';
        }

        let visible = 0;

        visible += filterStandaloneSection('mdfServers');
        visible += filterStandaloneSection('mdfPower');
        visible += filterStandaloneSection('mdfInfrastructure');
        visible += filterLocationSection('idf');
        visible += filterLocationSection('otherLocations');

        const count = document.querySelector(
            '.infra-visible-count'
        );

        if (count) {
            count.textContent =
                visible + ' devices visible';
        }

        const empty = document.querySelector(
            '.empty-filter-result'
        );

        if (empty) {
            empty.style.display =
                visible === 0 ? 'block' : 'none';
        }

        updateButtonStates();

        if (tvMode) {
            tvShowSlide();
        }
    }

    function persistAndApply() {
        saveState();
        applyFilters();
    }

    document.querySelector(
        '[data-action="problems"]'
    ).addEventListener('click', function () {
        // Used to also force critical/warning/unknown to true — which
        // meant an admin whose Settings page only enables Critical
        // still saw Warning devices the instant they clicked this
        // button, silently overriding a choice already made in
        // Settings. Same rule as the Problem Type exclusion above
        // (see Page.php's $enabledProblemTypes comment): a Settings
        // default is authoritative until the viewer deliberately
        // changes it, not something a one-click shortcut quietly
        // widens. "Problems only" now does exactly what its name says
        // and nothing else — hide Healthy — leaving every other
        // severity/problem-type choice exactly as it already was.
        state.healthy = false;

        persistAndApply();
    });

    document.querySelector(
        '[data-action="all"]'
    ).addEventListener('click', function () {
        Object.keys(defaults).forEach(function (key) {
            if (key === 'coverage' || key === 'summary' || key === 'priority' || numericSettings[key]) {
                return;
            }

            state[key] = true;
        });

        persistAndApply();
    });

    document.querySelector(
        '[data-action="reset"]'
    ).addEventListener('click', function () {
        Object.assign(state, defaults);
        persistAndApply();
    });

    // --- TV Mode -----------------------------------------------------

    let tvMode = false;

    try {
        tvMode = window.localStorage.getItem(tvStorageKey) === '1';
    } catch (error) {
        tvMode = false;
    }

    try {
        if (new URLSearchParams(window.location.search).get('tv') === '1') {
            tvMode = true;
        }
    } catch (error) {
        // URLSearchParams unavailable — TV mode still works via the
        // button and localStorage, just not via the query string.
    }

    // TV Mode used to follow the interactive Severity/Problem filters
    // (`deviceMatches()`/`state`) — but `state` is whatever the last
    // person on this browser left it as (a "View all"/"Problems only"
    // click, or a persisted session from days ago), not necessarily
    // what Settings actually says. Reported directly: "solo quiero que
    // salga los devices que tiene los criterios puesto en setting" —
    // an unattended wall display must show exactly the Settings-
    // configured criteria (`defaults`), never a stray local override.
    // `tvDeviceMatches()`/`defaults` below are TV Mode's own copy of
    // `deviceMatches()`/`state` for exactly this reason; the
    // interactive view keeps using `state` unchanged.
    function tvDeviceMatches(device) {
        const health = device.dataset.health || 'healthy';

        if (!defaults[health]) {
            return false;
        }

        if (health === 'healthy') {
            return true;
        }

        const problems = parseProblems(device);

        if (problems.length === 0) {
            return Boolean(defaults.other);
        }

        return problems.some(function (problem) {
            return Boolean(defaults[problem]);
        });
    }

    function tvSyncDeviceClasses() {
        document.querySelectorAll('.monitor-device').forEach(function (device) {
            device.classList.toggle('tv-problem-device', tvDeviceMatches(device));
        });
    }

    function tvSyncCardBadges() {
        document.querySelectorAll('[data-location-card]').forEach(function (card) {
            const badge = card.querySelector('[data-tv-issue-badge]');

            if (!badge) {
                return;
            }

            const devices = Array.from(card.querySelectorAll('.monitor-device'));
            const matches = devices.filter(tvDeviceMatches);

            if (matches.length === 0) {
                badge.textContent = '';
                badge.classList.remove('tv-badge-critical');
                return;
            }

            const hasCritical = matches.some(function (device) {
                return (device.dataset.health || '') === 'critical';
            });

            badge.classList.toggle('tv-badge-critical', hasCritical);
            badge.textContent = matches.length + ' of ' + devices.length + ' shown';
        });
    }

    // Real photo evidence from an actual 1080p TV showed the old
    // hardcoded height/chrome guesses badly under-filled every slide
    // (one row of cards, most of the screen left black). Replaced with
    // real DOM measurements: tvMeasureCardHeights() reads an actual
    // rendered card's height every time a slide is (re)built —
    // tvShowSlide() calls tvBuildSlides() *before* clearing the
    // previous slide's `.tv-active-card` classes, so on every call
    // after the first there is a real, currently-visible TV-mode card
    // to measure directly; only the very first slide of a session
    // falls back to measuring an interactive-mode card (smaller TV
    // fonts, so scaled up as an approximation) since no TV-mode card
    // has rendered yet.
    function tvMeasureCardHeights() {
        function measure(activeSelector, interactiveSelector) {
            const activeCard = document.querySelector(activeSelector);

            if (activeCard) {
                const height = activeCard.getBoundingClientRect().height;

                if (height > 10) {
                    return Math.ceil(height);
                }
            }

            const interactiveCard = document.querySelector(interactiveSelector);

            if (interactiveCard) {
                const height = interactiveCard.getBoundingClientRect().height;

                if (height > 10) {
                    return Math.ceil(height * 1.2);
                }
            }

            return null;
        }

        // Device cards now only ever get laid out for real inside the
        // combined grid (`.tv-combined-grid`) — the original per-
        // section `.device-card-grid` is permanently hidden in TV
        // Mode since sections stopped being toggled individually (see
        // tvBuildSlides()/tvCombinedUnits()), so that old selector
        // would find nothing but display:none ancestors from here on.
        const measuredDevice = measure(
            '.tv-combined-grid > .device-card.tv-active-card',
            '.device-card-grid > .monitor-device.device-card'
        );

        if (measuredDevice) {
            tvMeasuredDeviceCardHeight = measuredDevice;
        }

        const measuredLocation = measure(
            '[data-location-card].tv-active-card',
            '[data-location-card]'
        );

        if (measuredLocation) {
            tvMeasuredLocationCardHeight = measuredLocation;
        }
    }

    // TV Mode has no scrollbar a viewer can use, so a slide with more
    // cards than fit the screen would silently hide the rest. The
    // number of cards per slide is derived from the actual viewport
    // against the grid's own minimum card width (see the
    // `minmax(340px, …)` TV grid rule) and the real measured card
    // height for whichever unit (device card vs. location card) this
    // section paginates — re-derived on every rotation tick so a
    // browser resize/zoom, or the cards simply getting taller/shorter
    // as real device data changes, is picked up on the next slide.
    function tvEstimateChunkSize(measuredCardHeight) {
        const cardWidth = 340;
        const cardHeight = measuredCardHeight || 220;
        const gap = 8;
        const chromeWidth = 56;

        // Real chrome height (header, TV status banner, Priority
        // Attention panel, section header — everything above the card
        // grid) measured directly from the DOM via the bottom edge of
        // a real section header, instead of a guessed constant.
        let chromeHeight = 230;
        const sectionHeader = document.querySelector('.infra-section-header');

        if (sectionHeader) {
            const rect = sectionHeader.getBoundingClientRect();

            if (rect.bottom > 0) {
                chromeHeight = rect.bottom;
            }
        }

        const availableWidth = Math.max(cardWidth, window.innerWidth - chromeWidth);
        const availableHeight = Math.max(cardHeight, window.innerHeight - chromeHeight);

        const cols = Math.max(1, Math.floor((availableWidth + gap) / (cardWidth + gap)));
        const rows = Math.max(1, Math.floor((availableHeight + gap) / (cardHeight + gap)));

        return Math.max(1, cols * rows);
    }

    function tvChunk(items, chunkSize) {
        const chunks = [];

        for (let i = 0; i < items.length; i += chunkSize) {
            chunks.push(items.slice(i, i + chunkSize));
        }

        return chunks;
    }

    // Critical=0, Warning=1, Unknown=2 — a Healthy unit only ever
    // reaches the combined pool at all if Settings' `default_severity_
    // healthy` is on, in which case it sorts last, same idea as
    // Ranking is emitted by Support/Severity.php with the refreshed
    // dashboard markup. JavaScript only consumes it; it never defines
    // a second severity order that could drift from PHP.
    function tvSeverityRank(health) {
        const definition = severityDefinitions[health];

        return definition && Number.isFinite(Number(definition.rank))
            ? Number(definition.rank)
            : 999;
    }

    // One merged pool across every enabled section instead of one
    // slide per section — a device (MDF Servers/Power/Infrastructure)
    // and a location card (IDF/Other Locations, ranked by the worst
    // health among its own matching devices) are both valid "units."
    // Section visibility (`defaults[name]`) and matching
    // (`tvDeviceMatches()`) are unchanged from before; only the
    // grouping changed. Reported directly: "poner todo en una sola
    // pantalla sin slide... ordenada por gravedad... cuando ya sea
    // mucho que empiece a mostrar lo que falta."
    function tvCombinedUnits() {
        const units = [];
        const locationDevicesPerCard = 2;

        ['mdfServers', 'mdfPower', 'mdfInfrastructure'].forEach(function (name) {
            if (!defaults[name]) {
                return;
            }

            const section = document.querySelector(
                '[data-dashboard-section="' + name + '"]'
            );

            if (!section) {
                return;
            }

            Array.from(section.querySelectorAll('.monitor-device')).forEach(function (device) {
                if (!tvDeviceMatches(device)) {
                    return;
                }

                units.push({ el: device, rank: tvSeverityRank(device.dataset.health) });
            });
        });

        ['idf', 'otherLocations'].forEach(function (name) {
            if (!defaults[name]) {
                return;
            }

            const section = document.querySelector(
                '[data-dashboard-section="' + name + '"]'
            );

            if (!section) {
                return;
            }

            Array.from(section.querySelectorAll('[data-location-card]')).forEach(function (card) {
                const matchingDevices = Array.from(
                    card.querySelectorAll('.monitor-device')
                ).filter(tvDeviceMatches);

                if (matchingDevices.length === 0) {
                    return;
                }

                const worstRank = matchingDevices.reduce(function (best, device) {
                    return Math.min(best, tvSeverityRank(device.dataset.health));
                }, 3);

                for (let start = 0; start < matchingDevices.length; start += locationDevicesPerCard) {
                    units.push({
                        el: card,
                        rank: worstRank,
                        deviceStart: start,
                        deviceEnd: Math.min(start + locationDevicesPerCard, matchingDevices.length),
                        deviceTotal: matchingDevices.length
                    });
                }
            });
        });

        return units;
    }

    function tvBuildSlides() {
        tvMeasureCardHeights();

        // This slide mixes device cards (shorter) and location cards
        // (taller — they nest several devices each), so there's no
        // single accurate "cards per screen" number the way a single-
        // type section had. Using whichever measured height yields
        // the SMALLER capacity is the conservative choice: a slide
        // never overflows even if it happens to be all location
        // cards, at the cost of sometimes under-filling a slide that
        // is mostly small device cards. TV Mode has no scrollbar to
        // fall back on, so under-filling is the safe direction to
        // round in.
        const combinedChunkSize = Math.min(
            tvEstimateChunkSize(tvMeasuredDeviceCardHeight),
            tvEstimateChunkSize(tvMeasuredLocationCardHeight)
        );

        const units = tvCombinedUnits();

        if (units.length === 0) {
            const totalDevices = document.querySelectorAll('.monitor-device').length;

            return [{ type: 'clear', section: 'fleet', total: totalDevices }];
        }

        units.sort(function (a, b) {
            if (a.rank !== b.rank) {
                return a.rank - b.rank;
            }

            return (a.el.textContent || '').trim().localeCompare((b.el.textContent || '').trim());
        });

        return tvChunk(units, combinedChunkSize).map(function (chunk) {
            return { type: 'combined', units: chunk, total: units.length };
        });
    }

    // tvBuildSlides() only ever produces a 'clear' slide for the one
    // fleet-wide case (nothing anywhere matches Settings' criteria) —
    // now that every section feeds one combined pool instead of its
    // own slide, there's no longer a single section to blame an empty
    // slide on individually.
    function tvShowClearSlide(slide) {
        const clearSlide = document.querySelector('.tv-clear-slide');

        if (!clearSlide) {
            return;
        }

        const title = clearSlide.querySelector('.tv-clear-title');
        const subtitle = clearSlide.querySelector('.tv-clear-subtitle');

        if (title) {
            title.textContent = 'All Clear';
        }

        if (subtitle) {
            subtitle.textContent = 'No devices currently match the Settings-configured criteria ('
                + slide.total
                + (slide.total === 1 ? ' device monitored)' : ' devices monitored)');
        }

        clearSlide.classList.add('tv-active-slide');
    }

    // The combined slide's units are CLONES of the real device/
    // location-card elements — cloned (not moved) so the originals
    // stay exactly where normalizeDevice()/the Blade template put
    // them, untouched, for the interactive view and for the next
    // tvBuildSlides() call to re-read fresh. Cloning after
    // tvSyncDeviceClasses()/tvSyncCardBadges() have already run (see
    // tvShowSlide()) means every clone already carries the right
    // `.tv-problem-device` class and issue-badge text baked in.
    function tvShowCombinedSlide(slide) {
        const wrapper = document.querySelector('[data-tv-combined-slide]');
        const grid = document.querySelector('[data-tv-combined-grid]');

        if (!wrapper || !grid) {
            return;
        }

        const fragment = document.createDocumentFragment();

        slide.units.forEach(function (unit) {
            const clone = unit.el.cloneNode(true);

            if (Number.isInteger(unit.deviceStart)) {
                const matchingDevices = Array.from(
                    clone.querySelectorAll('.monitor-device')
                ).filter(tvDeviceMatches);

                Array.from(clone.querySelectorAll('.monitor-device')).forEach(function (device) {
                    if (!matchingDevices.includes(device)) {
                        device.remove();
                    }
                });

                matchingDevices.forEach(function (device, index) {
                    if (index < unit.deviceStart || index >= unit.deviceEnd) {
                        device.remove();
                    }
                });

                const badge = clone.querySelector('[data-tv-issue-badge]');

                if (badge) {
                    badge.textContent = (unit.deviceStart + 1) + '–' + unit.deviceEnd
                        + ' of ' + unit.deviceTotal + ' shown';
                }
            }

            clone.classList.add('tv-active-card');
            fragment.appendChild(clone);
        });

        grid.appendChild(fragment);

        const meta = document.querySelector('[data-tv-combined-meta]');

        if (meta) {
            meta.textContent = slide.units.length === slide.total
                ? slide.total + (slide.total === 1 ? ' issue' : ' issues')
                : slide.units.length + ' of ' + slide.total + ' shown';
        }

        wrapper.classList.add('tv-active-slide');
    }

    function tvShowSlide() {
        tvSyncDeviceClasses();
        tvSyncCardBadges();

        const slides = tvBuildSlides();

        document.querySelectorAll('[data-dashboard-section]').forEach(function (section) {
            section.classList.remove('tv-active-slide');
        });

        const clearSlideEl = document.querySelector('.tv-clear-slide');

        if (clearSlideEl) {
            clearSlideEl.classList.remove('tv-active-slide');
        }

        const combinedSlideEl = document.querySelector('[data-tv-combined-slide]');

        if (combinedSlideEl) {
            combinedSlideEl.classList.remove('tv-active-slide');
        }

        const combinedGrid = document.querySelector('[data-tv-combined-grid]');

        if (combinedGrid) {
            combinedGrid.innerHTML = '';
        }

        document.querySelectorAll('[data-location-card], .monitor-device').forEach(function (card) {
            card.classList.remove('tv-active-card');
        });

        if (slides.length === 0) {
            return;
        }

        if (tvSlideIndex >= slides.length) {
            tvSlideIndex = 0;
        }

        const slide = slides[tvSlideIndex];

        if (slide.type === 'clear') {
            tvShowClearSlide(slide);
            return;
        }

        tvShowCombinedSlide(slide);
    }

    function tvAdvanceSlide() {
        tvSlideIndex += 1;
        tvShowSlide();
    }

    function updateClock() {
        const clock = document.querySelector('.tv-clock');

        if (!clock) {
            return;
        }

        const now = new Date();

        if (
            dashboardConnectionState === 'refreshing'
            && dashboardRefreshStartedAt !== null
            && Date.now() - dashboardRefreshStartedAt >= 30000
        ) {
            dashboardConnectionState = 'disconnected';
        }

        const currentTime = now.toLocaleDateString(undefined, {
            weekday: 'short',
            month: 'short',
            day: 'numeric'
        }) + ' · ' + now.toLocaleTimeString();
        const updatedAt = clock.dataset.updatedAt || '';
        const updatedEpoch = Number(clock.dataset.updatedAtEpoch || 0);
        const hasValidUpdate = updatedAt !== '' && Number.isFinite(updatedEpoch) && updatedEpoch > 0;
        const ageSeconds = hasValidUpdate
            ? Math.max(0, Math.floor(Date.now() / 1000) - updatedEpoch)
            : null;
        const ageLabel = ageSeconds === null
            ? ''
            : (ageSeconds < 60
                ? ageSeconds + 's ago'
                : Math.floor(ageSeconds / 60) + 'm ago');
        const connectionLabel = dashboardConnectionState === 'connected'
            ? 'Connected'
            : (dashboardConnectionState === 'refreshing' ? 'Refreshing' : 'Connection issue');
        const updatedLabel = hasValidUpdate
            ? 'Last updated: ' + updatedAt + ' (' + ageLabel + ')'
            : 'Last updated: unavailable';

        clock.textContent = connectionLabel + ' · ' + currentTime + ' · ' + updatedLabel;
    }

    dashboardUpdateClock = updateClock;

    // Counts the devices currently passing Settings' criteria (the
    // same set the slides rotate through — `tvDeviceMatches()`/
    // `defaults`, not `deviceMatches()`/`state`; this banner is TV-
    // Mode-only, see applyTvMode()) instead of the fixed
    // `data-critical`/`data-warning`/`data-down`/`data-total` counts
    // rendered from the server-side global `$summary` — those never
    // moved with the filter, so the banner and the cards on screen
    // could show different numbers for the same moment. Was still
    // using `deviceMatches()`/`state` until this pass, which is why a
    // screenshot could show e.g. "4 WARNING OF 4 SHOWN" in the banner
    // while the slide underneath it (already fixed to use
    // `tvDeviceMatches()`) correctly said a section had nothing to
    // show — two different sources of truth disagreeing on screen at
    // the same time.
    function updateStatusBanner() {
        const banner = document.querySelector('.tv-status-banner');

        if (!banner) {
            return;
        }

        // Excludes `.tv-combined-grid`'s own contents deliberately —
        // those are `cloneNode()`s of whichever devices the current
        // slide happens to be showing (see tvShowCombinedSlide()), so
        // counting them here on top of their originals would double-
        // count exactly the devices currently on screen.
        const devices = Array.from(
            document.querySelectorAll('.monitor-device')
        ).filter(function (device) {
            return !device.closest('[data-tv-combined-grid]');
        }).filter(tvDeviceMatches);

        let critical = 0;
        let warning = 0;
        let unknown = 0;
        let down = 0;

        devices.forEach(function (device) {
            const health = device.dataset.health || 'healthy';

            if (health === 'critical') {
                critical += 1;
            } else if (health === 'warning') {
                warning += 1;
            } else if (health === 'unknown') {
                unknown += 1;
            }

            if (device.querySelector('.status-down')) {
                down += 1;
            }
        });

        const total = devices.length;

        let level = 'healthy';
        let text = total === 0
            ? 'NO DEVICES MATCH THE CURRENT FILTERS'
            : 'ALL ' + total + ' SHOWN ARE HEALTHY';

        if (critical > 0 || down > 0) {
            level = 'critical';
            text = critical + ' CRITICAL · ' + warning + ' WARNING · ' + down + ' DOWN OF ' + total + ' SHOWN';
        } else if (warning > 0) {
            level = 'warning';
            text = warning + ' WARNING OF ' + total + ' SHOWN';
        } else if (unknown > 0) {
            level = 'unknown';
            text = unknown + ' NEEDS REVIEW OF ' + total + ' SHOWN';
        }

        banner.className = 'tv-status-banner tv-status-' + level;
        banner.textContent = text;
    }

    function tvStart() {
        // tvSlideIndex is reset by the caller (applyTvMode) only when
        // TV Mode is actually being turned on, not on every periodic
        // data refresh — see the module-scope comment on tvSlideIndex.
        tvShowSlide();

        if (tvRotationTimer) {
            window.clearInterval(tvRotationTimer);
        }

        tvRotationTimer = window.setInterval(tvAdvanceSlide, tvSlideMs);

        updateStatusBanner();

        if (tvClockTimer) {
            window.clearInterval(tvClockTimer);
        }

        tvClockTimer = window.setInterval(updateClock, 1000);
        updateClock();
    }

    function tvStop() {
        if (tvRotationTimer) {
            window.clearInterval(tvRotationTimer);
            tvRotationTimer = null;
        }

        if (tvClockTimer) {
            window.clearInterval(tvClockTimer);
            tvClockTimer = null;
        }

        document.querySelectorAll('[data-dashboard-section]').forEach(function (section) {
            section.classList.remove('tv-active-slide');
        });

        const clearSlideEl = document.querySelector('.tv-clear-slide');

        if (clearSlideEl) {
            clearSlideEl.classList.remove('tv-active-slide');
        }

        const combinedSlideEl = document.querySelector('[data-tv-combined-slide]');

        if (combinedSlideEl) {
            combinedSlideEl.classList.remove('tv-active-slide');
        }

        const combinedGrid = document.querySelector('[data-tv-combined-grid]');

        if (combinedGrid) {
            combinedGrid.innerHTML = '';
        }

        document.querySelectorAll('[data-location-card], .monitor-device').forEach(function (card) {
            card.classList.remove('tv-active-card');
        });
    }

    function applyTvMode() {
        // Real bug, reported directly: the very first combined slide
        // of every TV Mode session showed far more cards (15) than
        // every slide after it (5) — because tvMeasureCardHeights()'s
        // real-card selectors only ever find something once a
        // combined slide has already rendered once; on a true cold
        // start it fell back to a hardcoded 220px guess, badly
        // underestimating a location card's real height (they nest
        // several devices) and so badly overestimating how many fit.
        // Measuring here — one line earlier, while sections are still
        // in their normal (non-hidden) interactive layout, before
        // `tv-mode-active` hides them — gives tvMeasureCardHeights()'s
        // existing "interactive card" fallback a real element to
        // measure on the very first activation instead of a guess, so
        // the first slide is sized consistently with every slide
        // after it. Only on a genuine off→on transition, matching the
        // tvSlideIndex reset below — a periodic refresh re-running
        // this while TV Mode stays on doesn't need it re-measured from
        // the interactive view, it already has real TV-mode cards to
        // measure from.
        if (tvMode && !tvWasActive) {
            tvMeasureCardHeights();
        }

        document.body.classList.toggle('tv-mode-active', tvMode);

        const exitBtn = document.querySelector('.tv-exit-button');

        if (exitBtn) {
            exitBtn.style.display = tvMode ? '' : 'none';
        }

        const clock = document.querySelector('.tv-clock');

        if (clock) {
            clock.style.display = tvMode ? '' : 'none';
        }

        const banner = document.querySelector('.tv-status-banner');

        if (banner) {
            banner.style.display = tvMode ? 'flex' : 'none';
        }

        try {
            window.localStorage.setItem(tvStorageKey, tvMode ? '1' : '0');
        } catch (error) {
            // Ignore — TV mode still applies for this page view.
        }

        if (tvMode) {
            // Only jump back to the first slide on a real off→on
            // transition (the button/Escape/first load with TV Mode
            // already on). A periodic data refresh re-runs
            // initDashboard() → applyTvMode() while TV Mode stays on
            // the whole time, and must resume the rotation in place —
            // otherwise it never advances past whatever slide fits
            // inside one refresh interval.
            if (!tvWasActive) {
                tvSlideIndex = 0;
            }

            tvStart();
        } else {
            tvStop();
        }

        tvWasActive = tvMode;
    }

    const tvButton = document.querySelector('[data-action="tv"]');

    if (tvButton) {
        tvButton.addEventListener('click', function () {
            tvMode = true;
            applyTvMode();
        });
    }

    const tvExitButton = document.querySelector('.tv-exit-button');

    if (tvExitButton) {
        tvExitButton.addEventListener('click', function () {
            tvMode = false;
            applyTvMode();
        });
    }

    // `document` itself is never replaced by refreshDashboardData(), so
    // (unlike listeners on elements inside `.infra-dashboard`) a fresh
    // one bound here on every initDashboard() call would keep stacking
    // up. Drop the previous cycle's handler first.
    if (dashboardKeydownHandler) {
        document.removeEventListener('keydown', dashboardKeydownHandler);
    }

    dashboardKeydownHandler = function (event) {
        if (event.key === 'Escape' && tvMode) {
            tvMode = false;
            applyTvMode();
        }
    };

    document.addEventListener('keydown', dashboardKeydownHandler);

    applyFilters();
    applyTvMode();

    scheduleRefresh();
}

// Swaps in freshly-rendered markup without a full page navigation.
// TV mode, the current slide, and the filter checkboxes all survive
// the refresh because they're read back from `localStorage`/the DOM
// by initDashboard() itself, not reset here.
function refreshDashboardData() {
    if (dashboardRefreshController) {
        dashboardRefreshController.abort();
    }

    const controller = new AbortController();
    const timeout = window.setTimeout(function () {
        controller.abort();
    }, 30000);

    dashboardRefreshController = controller;
    dashboardConnectionState = 'refreshing';
    dashboardRefreshStartedAt = Date.now();

    if (dashboardUpdateClock) {
        dashboardUpdateClock();
    }

    fetch(window.location.href, {
        cache: 'no-store',
        credentials: 'same-origin',
        signal: controller.signal
    })
        .then(function (response) {
            if (!response.ok) {
                throw new Error('Dashboard refresh failed: ' + response.status);
            }

            return response.text();
        })
        .then(function (html) {
            const nextDoc = new DOMParser().parseFromString(html, 'text/html');
            const nextDashboard = nextDoc.querySelector('.infra-dashboard');
            const currentDashboard = document.querySelector('.infra-dashboard');

            if (!nextDashboard || !currentDashboard) {
                throw new Error('Dashboard refresh markup missing .infra-dashboard');
            }

            currentDashboard.replaceWith(nextDashboard);
            dashboardRefreshFailures = 0;
            dashboardConnectionState = 'connected';
            dashboardRefreshStartedAt = null;
            initDashboard();
        })
        .catch(function () {
            dashboardRefreshFailures += 1;
            dashboardConnectionState = 'disconnected';
            dashboardRefreshStartedAt = null;

            if (dashboardUpdateClock) {
                dashboardUpdateClock();
            }

            // A handful of consecutive failures (network blip, plugin
            // briefly unreachable) falls back to a real navigation
            // reload rather than leaving the dashboard silently stale
            // forever.
            if (dashboardRefreshFailures >= 3) {
                window.location.reload();
                return;
            }

            scheduleRefresh();
        })
        .finally(function () {
            window.clearTimeout(timeout);

            if (dashboardRefreshController === controller) {
                dashboardRefreshController = null;
            }
        });
}

function scheduleRefresh() {
    if (dashboardRefreshTimer) {
        window.clearTimeout(dashboardRefreshTimer);
    }

    dashboardRefreshTimer = window.setTimeout(refreshDashboardData, refreshSeconds * 1000);
}

document.addEventListener('DOMContentLoaded', initDashboard);
</script>
