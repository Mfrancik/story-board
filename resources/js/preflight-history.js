/** A day in ms: the trend windows and the charts' 30-day mark count in days. */
const DAY = 86400000;

/** Where the Columns picker (SB-19) saves a project's hidden columns: this prefix + the project's name. */
const COLUMNS_KEY = 'board.preflight.hidden-columns.';

/**
 * The hidden columns saved for `key`, keeping only keys that can still be hidden. Storage is a
 * per-viewer convenience: private windows and blocked site data make it throw, and an old or
 * hand-edited value may not parse, so any failure means "nothing saved" and every column shows.
 *
 * @param {string} key
 * @param {string[]} hideable
 * @returns {string[]}
 */
function readHiddenColumns(key, hideable) {
    try {
        const saved = JSON.parse(window.localStorage.getItem(key) ?? '[]');
        return Array.isArray(saved) ? hideable.filter((c) => saved.includes(c)) : [];
    } catch {
        return [];
    }
}

/**
 * Save the hidden columns for `key`, or drop the key when none are hidden, so "all shown" and
 * "never chosen" are the same state. A failed write is ignored: the choice still applies on this
 * page, it just won't survive a reload.
 *
 * @param {string} key
 * @param {string[]} hidden
 */
function writeHiddenColumns(key, hidden) {
    try {
        if (hidden.length) window.localStorage.setItem(key, JSON.stringify(hidden));
        else window.localStorage.removeItem(key);
    } catch {
        // Storage unavailable: nothing to recover, and nothing the server needs to know (no log by design).
    }
}

/**
 * The Preflight tab's client side (SB-16, option A): filters, trend figures, two inline-SVG line
 * charts and the row/chart hover link, all over the runs the server put in the page, plus the
 * Columns picker (SB-19) that hides ledger columns and remembers them per project in this browser.
 * Pure UI: the CSVs are read once on page load, and nothing here calls the server.
 *
 * The figures and formats mirror App\Actions\Board\ReadPreflightHistory, which renders the same
 * values into the page first; this recomputes them only when a filter narrows the runs.
 *
 * @param {Array<{id:number, ts:number, branch:?string, where:string, mode:?string, wall:?number, tokens:?number, flagged:boolean}>} runs newest first
 * @param {number} now the server's "now" in ms, so the 30-day windows match the server-rendered figures
 * @param {string} main the `where` of a run in the project's own checkout
 * @param {string} project the project's name, which keys its saved columns so each project keeps its own
 * @param {string[]} hideable the `data-col` keys the picker may hide, in table order (every column but When)
 */
export function preflightHistory(runs, now, main, project, hideable) {
    // One series colour per chart; literal class names so Tailwind generates them.
    const GRID = 'stroke-zinc-100 dark:stroke-zinc-800';
    const BASE = 'stroke-zinc-300 dark:stroke-zinc-700';
    const LABEL = 'fill-zinc-400';
    const LINE = 'stroke-series';
    const DOT = 'fill-series stroke-white dark:stroke-zinc-900';
    const MARK = 'stroke-zinc-400 dark:stroke-zinc-500';

    // The x domain spans every run, not just the visible ones, so a filter never rescales time.
    const first = runs.length ? Math.min(...runs.map((r) => r.ts)) : now - 60 * DAY;
    const domain0 = Math.min(first, now - 30 * DAY) - DAY / 2;
    const columnsKey = COLUMNS_KEY + project;

    return {
        runs,
        fMode: 'all',
        fBranch: '',
        fWhere: 'all',
        hoverId: null,
        hoverChart: null,
        w: { wall: 0, tokens: 0 },
        colsOpen: false,
        hiddenCols: readHiddenColumns(columnsKey, hideable),

        /** Watch each chart's width: the SVGs are drawn at their real size so the text never stretches. */
        init() {
            this.$nextTick(() => this.$root.querySelectorAll('[data-chart]').forEach((el) => {
                new ResizeObserver(() => { this.w[el.dataset.chart] = el.clientWidth; }).observe(el);
            }));
        },

        // ---- filtering ----
        /** Whether run `id` passes the filters. */
        keep(id) {
            const r = this.runs[id];
            if (this.fMode !== 'all' && r.mode !== this.fMode) return false;
            if (this.fBranch && !(r.branch ?? '').toLowerCase().includes(this.fBranch.toLowerCase())) return false;
            if (this.fWhere === 'main' && r.where !== main) return false;
            if (this.fWhere === 'worktrees' && r.where === main) return false;
            return true;
        },
        get visible() { return this.runs.filter((r) => this.keep(r.id)); },
        get filtered() { return this.fMode !== 'all' || this.fBranch !== '' || this.fWhere !== 'all'; },
        reset() { this.fMode = 'all'; this.fBranch = ''; this.fWhere = 'all'; },
        /** Visible runs whose audit tier is not the pinned one (the "check the tier" flags). */
        get flaggedCount() { return this.visible.filter((r) => r.flagged).length; },
        modeCount(m) { return m === 'all' ? this.runs.length : this.runs.filter((r) => r.mode === m).length; },

        // ---- columns picker (SB-19): independent of the filters, which scope rows, not columns ----
        /** Whether column `col` is shown. A key outside `hideable` (When) always is. */
        shown(col) { return !this.hiddenCols.includes(col); },
        get hiddenCount() { return this.hiddenCols.length; },
        get columnsLabel() { return this.hiddenCount ? `Columns · ${this.hiddenCount} hidden` : 'Columns'; },
        /** Flip one column and save the choice. Rebuilt from `hideable` so the saved list stays in table order. */
        toggleColumn(col) {
            if (!hideable.includes(col)) return;
            const hide = this.shown(col);
            this.hiddenCols = hideable.filter((c) => (c === col ? hide : this.hiddenCols.includes(c)));
            writeHiddenColumns(columnsKey, this.hiddenCols);
        },
        /** Show every column and forget the saved choice for this project. */
        resetColumns() {
            this.hiddenCols = [];
            writeHiddenColumns(columnsKey, []);
        },

        // ---- formatting (the same rules as ReadPreflightHistory::wall/tokens) ----
        fmtWall(s) {
            if (s === null) return '—';
            s = Math.round(s);
            return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
        },
        fmtTok(n) {
            if (n === null) return '—';
            if (n >= 1e6) return (n / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
            if (n >= 1e3) return Math.round(n / 1e3) + 'k';
            return String(Math.round(n));
        },
        /** A run's date and time in the viewer's own zone (the server's text is UTC until this runs). */
        day(id) { return new Date(this.runs[id].ts).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }); },
        time(id) { return new Date(this.runs[id].ts).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }); },

        // ---- trend figures: the last 30 days vs the 30 before, over the visible runs ----
        median(xs) {
            xs = xs.filter((v) => v !== null).sort((a, b) => a - b);
            if (!xs.length) return null;
            const m = Math.floor(xs.length / 2);
            return xs.length % 2 ? xs[m] : (xs[m - 1] + xs[m]) / 2;
        },
        win(from, to) { return this.visible.filter((r) => r.ts > now - from * DAY && r.ts <= now - to * DAY); },
        figure(name, which) {
            const rows = which === 'current' ? this.win(30, 0) : this.win(60, 30);
            if (name === 'runs') return String(rows.length);
            if (name === 'wall') return this.fmtWall(this.median(rows.map((r) => r.wall)));
            return this.fmtTok(this.median(rows.map((r) => r.tokens)));
        },

        // ---- charts: one series each, two charts rather than a dual axis ----
        niceCeil(v) {
            if (v <= 0) return 1;
            const p = Math.pow(10, Math.floor(Math.log10(v)));
            for (const m of [1, 2, 2.5, 3, 4, 5, 6, 8, 10]) if (m * p >= v) return m * p;
            return 10 * p;
        },
        val(r, metric) { return metric === 'wall' ? r.wall : r.tokens; },
        geom(metric, W, H) {
            const L = 38, R = 8, T = 8, B = 20, iw = Math.max(W - L - R, 10), ih = H - T - B;
            const raw = Math.max(1, ...this.visible.map((r) => this.val(r, metric)).filter((v) => v !== null));
            const max = metric === 'wall' ? this.niceCeil(raw / 60) * 60 : this.niceCeil(raw);
            return { L, R, T, iw, ih, max, X: (t) => L + (t - domain0) / (now - domain0) * iw, Y: (v) => T + ih - v / max * ih };
        },
        axis(metric, v) { return metric === 'wall' ? Math.round(v / 60) + 'm' : this.fmtTok(v); },
        /** The chart for `metric` as SVG markup, `W` px wide. */
        chart(metric, W, H) {
            if (!W) return '';
            const g = this.geom(metric, W, H);
            const pts = this.visible.filter((r) => this.val(r, metric) !== null).reverse();
            let s = `<svg width="${W}" height="${H}" viewBox="0 0 ${W} ${H}" class="block overflow-visible" aria-hidden="true">`;
            for (const f of [0, 0.5, 1]) {
                const y = g.Y(g.max * f).toFixed(1);
                s += `<line x1="${g.L}" x2="${W - g.R}" y1="${y}" y2="${y}" class="${f === 0 ? BASE : GRID}" stroke-width="1"/>`;
                s += `<text x="${g.L - 6}" y="${y}" dy="0.32em" text-anchor="end" class="${LABEL}" font-size="10">${this.axis(metric, g.max * f)}</text>`;
            }
            const ticks = g.iw < 300 ? 3 : 5;
            for (let i = 0; i < ticks; i++) {
                const t = domain0 + (now - domain0) * (i + 0.5) / ticks;
                const label = new Date(t).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                s += `<text x="${g.X(t).toFixed(1)}" y="${H - 4}" text-anchor="middle" class="${LABEL}" font-size="10">${label}</text>`;
            }
            // The boundary the trend figures compare across.
            const bx = g.X(now - 30 * DAY).toFixed(1);
            s += `<line x1="${bx}" x2="${bx}" y1="${g.T}" y2="${g.T + g.ih}" class="${BASE}" stroke-dasharray="3 3"/>`;
            s += `<text x="${+bx + 4}" y="${g.T + 2}" dy="0.7em" class="${LABEL}" font-size="10">last 30 days</text>`;
            const h = pts.find((r) => r.id === this.hoverId);
            if (h) s += `<line x1="${g.X(h.ts).toFixed(1)}" x2="${g.X(h.ts).toFixed(1)}" y1="${g.T}" y2="${g.T + g.ih}" class="${MARK}" stroke-width="1"/>`;
            if (pts.length > 1) {
                const d = pts.map((r, i) => (i ? 'L' : 'M') + g.X(r.ts).toFixed(1) + ' ' + g.Y(this.val(r, metric)).toFixed(1)).join(' ');
                s += `<path d="${d}" fill="none" class="${LINE}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>`;
            }
            for (const r of pts) {
                const on = h && r.id === h.id;
                s += `<circle cx="${g.X(r.ts).toFixed(1)}" cy="${g.Y(this.val(r, metric)).toFixed(1)}" r="${on ? 5 : 3}" class="${DOT}" stroke-width="${on ? 2 : 1.5}"/>`;
            }
            return s + '</svg>';
        },
        /** Pointer over a chart: mark the nearest visible run on both charts and the table. */
        onMove(e, metric) {
            const W = this.w[metric];
            if (!W || !this.visible.length) return;
            const g = this.geom(metric, W, 120), mx = e.clientX - e.currentTarget.getBoundingClientRect().left;
            let best = null, bd = Infinity;
            for (const r of this.visible) {
                const d = Math.abs(g.X(r.ts) - mx);
                if (d < bd) { bd = d; best = r; }
            }
            this.hoverId = best.id;
            this.hoverChart = metric;
        },
        leave() { this.hoverChart = null; this.hoverId = null; },
        get hovered() { return this.visible.find((r) => r.id === this.hoverId) || null; },
        tipStyle(metric) {
            const W = this.w[metric], r = this.hovered;
            // An object, never a string, so x-show's display stays intact.
            if (!W || !r) return {};
            return { left: Math.min(Math.max(this.geom(metric, W, 120).X(r.ts), 70), W - 70) + 'px' };
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('preflightHistory', preflightHistory);
});
