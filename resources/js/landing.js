/**
 * منصة أثر — حزمة صفحة الهبوط وحدها
 *
 * The living neural canvas and the two interactive demos (the classifier lab
 * and the certificate simulator).  Loaded ONLY by layouts/public.blade.php, so
 * the dashboard bundle never pays for it (Constitution Article 19: landing JS
 * ≤ 40 KB gzipped).
 *
 * @see docs/04-design/ref-js-core.html · ref-js-interactions.html
 * @see PRD §9.1 · BR-11 · BR-26
 *
 * Not one Arabic string lives here.  Every label, template and plural form is
 * rendered by Blade through __() into a data-* attribute and read back at run
 * time (Constitution Article 15).  Every animation is skipped entirely when
 * prefers-reduced-motion is set (Article 18).
 */

const A = window.Athar || {};
const reduced = A.reduced || (() => window.matchMedia('(prefers-reduced-motion: reduce)').matches);
const finePointer = A.finePointer || (() => window.matchMedia('(hover: hover) and (pointer: fine)').matches);

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.prototype.slice.call(root.querySelectorAll(sel));
const clamp = (v, a, b) => (v < a ? a : v > b ? b : v);
const num = (el, name, fallback) => {
    const raw = el.getAttribute(name);
    const parsed = raw === null ? NaN : Number(raw);
    return Number.isFinite(parsed) ? parsed : fallback;
};

/**
 * A percentage that survives an empty cohort.
 *
 * `Math.round((0 / 0) * 100)` is NaN, and NaN reached the live page: the
 * certificate simulator printed "NaN%" on a cohort whose sessions had not been
 * created yet. A denominator of zero is not an error to propagate — there is
 * simply nothing to be a percentage OF, and zero is the honest answer.
 */
function pct(part, whole) {
    return whole > 0 ? Math.round((part / whole) * 100) : 0;
}

/**
 * Replaces :name placeholders in a Blade-provided template.
 *
 * A placeholder the caller did not supply is dropped rather than printed. A
 * visitor reading ":count" learns nothing and loses trust in everything else on
 * the page; an absent word at least reads as a sentence. The mismatch itself is
 * caught by SimulatorCopyTest, which is where a bug belongs — not on a landing
 * page.
 */
function fill(template, values) {
    if (!template) return '';
    return template
        .replace(/:([a-z_]+)/g, (match, key) =>
            Object.prototype.hasOwnProperty.call(values, key) ? String(values[key]) : ''
        )
        .replace(/\s{2,}/g, ' ')
        .trim();
}

/**
 * Arabic counting: singular / dual / paucal (3–10) / plural (11+).
 * The four forms come from lang/ar via data attributes — never from here.
 */
function plural(forms, n) {
    const form =
        n === 1 ? forms.one
        : n === 2 ? forms.two
        : n >= 3 && n <= 10 ? forms.few
        : forms.many;

    // The paucal and plural forms carry their own :count — "‏:count جلسات".
    // Returning the form raw meant fill() injected it into the sentence AFTER
    // its single pass had already gone by that position, so a visitor read
    // "ينقصك حضور :count جلسة". The form is completed here, before it is
    // handed to the sentence that will contain it.
    return fill(form, { count: n });
}

/* ==========================================================================
   1 · The living network behind the page
   Chaos at the top of the scroll, order at the bottom: the page is a model
   that trains as the visitor reads it.
   ========================================================================== */

function neuralCanvas() {
    const canvas = $('#netCanvas');
    if (!canvas || reduced()) return;

    const ctx = canvas.getContext('2d');
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    const LAYERS = [5, 8, 8, 6, 3];
    let W = 0;
    let H = 0;
    let nodes = [];
    let edges = [];
    let pulses = [];
    let bursts = [];
    let running = true;
    let progress = 0;
    let pointerX = window.innerWidth / 2;
    let pointerY = window.innerHeight / 2;
    let hasPointer = false;
    let t0 = 0;

    function build() {
        const r = canvas.getBoundingClientRect();
        W = r.width;
        H = r.height;
        canvas.width = W * dpr;
        canvas.height = H * dpr;
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

        nodes = [];
        edges = [];
        const padX = W * 0.07;
        const usable = W * 0.86;
        const layerStart = [];
        let idx = 0;

        for (let L = 0; L < LAYERS.length; L++) {
            layerStart.push(idx);
            for (let k = 0; k < LAYERS[L]; k++) {
                // RTL: layer 0 sits on the RIGHT and the signal flows leftwards.
                const tx = padX + usable * (1 - L / (LAYERS.length - 1));
                const ty = H * (0.5 + ((k - (LAYERS[L] - 1) / 2) * 0.86) / Math.max(LAYERS[L], 5));
                nodes.push({
                    cx: tx + (Math.random() - 0.5) * W * 0.42,
                    cy: ty + (Math.random() - 0.5) * H * 0.62,
                    tx,
                    ty,
                    x: 0,
                    y: 0,
                    ph: Math.random() * Math.PI * 2,
                    e: 0,
                });
                idx++;
            }
        }
        for (let L = 0; L < LAYERS.length - 1; L++) {
            for (let a = 0; a < LAYERS[L]; a++) {
                for (let b = 0; b < LAYERS[L + 1]; b++) {
                    if (Math.random() < 0.42) continue;
                    edges.push({ a: layerStart[L] + a, b: layerStart[L + 1] + b, w: 0.25 + Math.random() * 0.75 });
                }
            }
        }
    }

    // Colours are read from the token layer, never written here (Gate G6).
    const styles = getComputedStyle(document.documentElement);
    const rgbOf = (name, fallback) => {
        const raw = styles.getPropertyValue(name).trim();
        const m = raw.match(/^#([0-9a-f]{6})$/i);
        if (!m) return fallback;
        const int = parseInt(m[1], 16);
        return [(int >> 16) & 255, (int >> 8) & 255, int & 255].join(',');
    };
    const VIOLET = rgbOf('--violet-300', '177,145,217');
    const TEAL = rgbOf('--teal-500', '1,170,173');
    const TEAL_LT = rgbOf('--teal-300', '91,201,203');

    function frame(ts) {
        if (!running) return;
        const dt = Math.min((ts - t0) / 16.7 || 1, 3);
        t0 = ts;
        ctx.clearRect(0, 0, W, H);

        const P = progress;
        const ease = P * P * (3 - 2 * P);

        for (const n of nodes) {
            n.ph += 0.012 * dt;
            const driftX = Math.sin(n.ph) * (16 + 26 * (1 - ease));
            const driftY = Math.cos(n.ph * 0.83) * (12 + 22 * (1 - ease));
            n.x = n.cx + (n.tx - n.cx) * ease + driftX;
            n.y = n.cy + (n.ty - n.cy) * ease + driftY;

            if (hasPointer) {
                const dx = n.x - pointerX;
                const dy = n.y - pointerY;
                const d2 = dx * dx + dy * dy;
                if (d2 < 62000) {
                    const d = Math.sqrt(d2) || 1;
                    n.e = Math.min(1, n.e + 0.075 * dt);
                    const pull = (1 - d / 249) * 26;
                    n.x -= (dx / d) * pull;
                    n.y -= (dy / d) * pull;
                }
            }
            n.e *= Math.pow(0.955, dt);
        }

        for (const e of edges) {
            const a = nodes[e.a];
            const b = nodes[e.b];
            const glow = Math.max(a.e, b.e);
            const len = Math.hypot(b.x - a.x, b.y - a.y);
            const lenFade = clamp(1 - (len - W * 0.16) / (W * 0.2), 0.02, 1);
            const alpha = ((0.028 + 0.105 * ease) * e.w + glow * 0.3) * lenFade;
            ctx.strokeStyle = 'rgba(' + (ease > 0.55 ? TEAL : VIOLET) + ',' + alpha.toFixed(3) + ')';
            ctx.lineWidth = 0.6 + glow * 1.4;
            ctx.beginPath();
            ctx.moveTo(a.x, a.y);
            ctx.lineTo(b.x, b.y);
            ctx.stroke();
        }

        for (let i = pulses.length - 1; i >= 0; i--) {
            const pu = pulses[i];
            pu.t += pu.s * dt;
            if (pu.t >= 1) {
                pulses.splice(i, 1);
                continue;
            }
            const na = nodes[pu.e.a];
            const nb = nodes[pu.e.b];
            const px = na.x + (nb.x - na.x) * pu.t;
            const py = na.y + (nb.y - na.y) * pu.t;
            const fade = Math.sin(pu.t * Math.PI);
            ctx.fillStyle = 'rgba(' + TEAL + ',' + (0.75 * fade).toFixed(3) + ')';
            ctx.beginPath();
            ctx.arc(px, py, 2.1, 0, 6.284);
            ctx.fill();
            ctx.fillStyle = 'rgba(' + TEAL + ',' + (0.16 * fade).toFixed(3) + ')';
            ctx.beginPath();
            ctx.arc(px, py, 7, 0, 6.284);
            ctx.fill();
        }

        for (const m of nodes) {
            const rr = 1.9 + m.e * 3.4 + ease * 0.7;
            if (m.e > 0.04) {
                ctx.fillStyle = 'rgba(' + TEAL + ',' + (m.e * 0.14).toFixed(3) + ')';
                ctx.beginPath();
                ctx.arc(m.x, m.y, rr * 4.6, 0, 6.284);
                ctx.fill();
            }
            ctx.fillStyle =
                m.e > 0.3
                    ? 'rgba(' + TEAL_LT + ',' + (0.55 + m.e * 0.45).toFixed(3) + ')'
                    : 'rgba(' + VIOLET + ',' + (0.24 + ease * 0.24).toFixed(3) + ')';
            ctx.beginPath();
            ctx.arc(m.x, m.y, rr, 0, 6.284);
            ctx.fill();
        }

        for (let i = bursts.length - 1; i >= 0; i--) {
            const bu = bursts[i];
            bu.r += 13 * dt;
            const fade = 1 - bu.r / 620;
            if (fade <= 0) {
                bursts.splice(i, 1);
                continue;
            }
            ctx.strokeStyle = 'rgba(' + TEAL + ',' + (fade * 0.42).toFixed(3) + ')';
            ctx.lineWidth = 2.2 * fade;
            ctx.beginPath();
            ctx.arc(bu.x, bu.y, bu.r, 0, 6.284);
            ctx.stroke();
            for (const nn of nodes) {
                if (Math.abs(Math.hypot(nn.x - bu.x, nn.y - bu.y) - bu.r) < 26) nn.e = Math.min(1, nn.e + 0.5);
            }
        }

        if (Math.random() < 0.05 + ease * 0.14 && edges.length && pulses.length < 22) {
            pulses.push({ e: edges[(Math.random() * edges.length) | 0], t: 0, s: 0.006 + Math.random() * 0.012 });
        }
        requestAnimationFrame(frame);
    }

    document.addEventListener(
        'pointermove',
        (e) => {
            if (e.pointerType !== 'mouse') return;
            pointerX = e.clientX;
            pointerY = e.clientY;
            hasPointer = true;
        },
        { passive: true }
    );

    document.addEventListener(
        'pointerdown',
        (e) => {
            const t = e.target;
            if (t.closest && t.closest('a,button,input,textarea,select,summary,.lab__stage')) return;
            if (bursts.length > 3) return;
            bursts.push({ x: e.clientX, y: e.clientY, r: 0 });
        },
        { passive: true }
    );

    window.addEventListener(
        'scroll',
        () => {
            const max = document.documentElement.scrollHeight - window.innerHeight;
            progress = max > 0 ? clamp(window.scrollY / max, 0, 1) : 0;
        },
        { passive: true }
    );

    document.addEventListener('visibilitychange', () => {
        running = !document.hidden;
        if (running) {
            t0 = performance.now();
            requestAnimationFrame(frame);
        }
    });

    build();
    window.addEventListener('resize', build);
    requestAnimationFrame(frame);
}

/* ==========================================================================
   2 · The classifier lab — a linear model trained in the browser
   The visitor places points, the model separates them.  Nothing is sent
   anywhere and nothing here touches platform data.
   ========================================================================== */

function classifierLab() {
    const stage = $('#labStage');
    const canvas = $('#labCanvas');
    if (!stage || !canvas) return;

    const ctx = canvas.getContext('2d');
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    const hint = $('#labHint');
    let W = 0;
    let H = 0;
    let points = [];
    let cls = 'a';
    let w = [0, 0];
    let bias = 0;
    let anim = null;

    const styles = getComputedStyle(document.documentElement);
    const hexRgb = (name, fallback) => {
        const raw = styles.getPropertyValue(name).trim();
        const m = raw.match(/^#([0-9a-f]{6})$/i);
        if (!m) return fallback;
        const int = parseInt(m[1], 16);
        return [(int >> 16) & 255, (int >> 8) & 255, int & 255].join(',');
    };
    const COL_A = hexRgb('--violet-300', '177,145,217');
    const COL_B = hexRgb('--teal-500', '1,170,173');
    const COL_LINE = hexRgb('--teal-300', '91,201,203');
    const COL_INK = hexRgb('--ink', '10,7,19');

    function size() {
        const r = stage.getBoundingClientRect();
        W = r.width;
        H = r.height;
        canvas.width = W * dpr;
        canvas.height = H * dpr;
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        draw();
    }

    /** Gradient descent on hinge loss with a small L2 term. */
    function train() {
        const hasA = points.some((p) => p.c === 'a');
        const hasB = points.some((p) => p.c === 'b');
        if (!hasA || !hasB) {
            w = [0, 0];
            bias = 0;
            return;
        }
        w = [0, 0];
        bias = 0;
        const lr = 0.06;
        for (let it = 0; it < 340; it++) {
            const gw = [0, 0];
            let gb = 0;
            for (const p of points) {
                const y = p.c === 'a' ? 1 : -1;
                const nx = (p.x / W) * 2 - 1;
                const ny = (p.y / H) * 2 - 1;
                if (y * (w[0] * nx + w[1] * ny + bias) < 1) {
                    gw[0] -= y * nx;
                    gw[1] -= y * ny;
                    gb -= y;
                }
            }
            const n = points.length;
            w[0] -= lr * (gw[0] / n + 0.012 * w[0]);
            w[1] -= lr * (gw[1] / n + 0.012 * w[1]);
            bias -= lr * (gb / n);
        }
    }

    function accuracy() {
        if (!points.length) return null;
        let ok = 0;
        for (const p of points) {
            const nx = (p.x / W) * 2 - 1;
            const ny = (p.y / H) * 2 - 1;
            if ((w[0] * nx + w[1] * ny + bias >= 0 ? 'a' : 'b') === p.c) ok++;
        }
        return ok / points.length;
    }

    function updateStats() {
        const a = points.filter((p) => p.c === 'a').length;
        const counterA = $('#cntA');
        const counterB = $('#cntB');
        const total = $('#labN');
        const accEl = $('#labAcc');
        const accBar = $('#labAccBar');
        if (counterA) counterA.textContent = String(a);
        if (counterB) counterB.textContent = String(points.length - a);
        if (total) total.textContent = String(points.length);
        const acc = accuracy();
        if (accEl) accEl.textContent = acc === null ? '0' : String(Math.round(acc * 100));
        if (accBar) accBar.style.inlineSize = (acc === null ? 0 : acc * 100).toFixed(1) + '%';
    }

    function draw() {
        ctx.clearRect(0, 0, W, H);
        if (w[0] !== 0 || w[1] !== 0) {
            // Decision boundary: w·x + b = 0, drawn in normalised space.
            const pts = [];
            for (const [nx, ny] of boundaryPoints()) {
                pts.push([((nx + 1) / 2) * W, ((ny + 1) / 2) * H]);
            }
            if (pts.length === 2) {
                ctx.save();
                ctx.shadowColor = 'rgba(' + COL_LINE + ',.85)';
                ctx.shadowBlur = 14;
                ctx.strokeStyle = 'rgb(' + COL_LINE + ')';
                ctx.lineWidth = 2.4;
                ctx.lineCap = 'round';
                ctx.beginPath();
                ctx.moveTo(pts[0][0], pts[0][1]);
                ctx.lineTo(pts[1][0], pts[1][1]);
                ctx.stroke();
                ctx.restore();
            }
        }
        for (const p of points) {
            const grow = clamp((performance.now() - p.born) / 260, 0, 1);
            const r = 7 * (reduced() ? 1 : 1 - Math.pow(1 - grow, 3));
            const col = p.c === 'a' ? COL_A : COL_B;
            ctx.fillStyle = 'rgba(' + col + ',.18)';
            ctx.beginPath();
            ctx.arc(p.x, p.y, r * 2.3, 0, 6.284);
            ctx.fill();
            ctx.fillStyle = 'rgb(' + col + ')';
            ctx.beginPath();
            ctx.arc(p.x, p.y, r, 0, 6.284);
            ctx.fill();
            ctx.strokeStyle = 'rgba(' + COL_INK + ',.75)';
            ctx.lineWidth = 1.6;
            ctx.stroke();
        }
    }

    /** Intersections of the boundary line with the normalised [-1,1] square. */
    function boundaryPoints() {
        const out = [];
        const push = (x, y) => {
            if (x >= -1.001 && x <= 1.001 && y >= -1.001 && y <= 1.001) out.push([x, y]);
        };
        // Line: w0·x + w1·y + b = 0
        if (Math.abs(w[1]) > 1e-6) {
            push(-1, (-bias + w[0]) / w[1]);
            push(1, (-bias - w[0]) / w[1]);
        }
        if (Math.abs(w[0]) > 1e-6) {
            push((-bias + w[1]) / w[0], -1);
            push((-bias - w[1]) / w[0], 1);
        }
        return out.slice(0, 2);
    }

    function loop() {
        draw();
        const young = points.some((p) => performance.now() - p.born < 320);
        if (young && !reduced()) anim = requestAnimationFrame(loop);
        else anim = null;
    }

    function add(x, y) {
        points.push({ x, y, c: cls, born: performance.now() });
        if (hint) hint.style.opacity = '0';
        train();
        updateStats();
        if (!anim) loop();
        else draw();
    }

    stage.addEventListener('pointerdown', (e) => {
        e.preventDefault();
        const r = stage.getBoundingClientRect();
        add(e.clientX - r.left, e.clientY - r.top);
    });

    // Keyboard route so the demo is not mouse-only (WCAG 2.1.1).
    stage.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        e.preventDefault();
        add(W * (0.2 + Math.random() * 0.6), H * (0.2 + Math.random() * 0.6));
    });

    $$('.lab__b').forEach((b) => {
        b.addEventListener('click', () => {
            cls = b.dataset.cls;
            $$('.lab__b').forEach((o) => o.setAttribute('aria-pressed', String(o === b)));
        });
    });

    const clearBtn = $('#labClear');
    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            points = [];
            w = [0, 0];
            bias = 0;
            if (hint) hint.style.opacity = '1';
            updateStats();
            draw();
        });
    }

    const seedBtn = $('#labSeed');
    if (seedBtn) {
        seedBtn.addEventListener('click', () => {
            points = [];
            let s = 7717;
            const rnd = () => {
                s = (s * 1103515245 + 12345) & 0x7fffffff;
                return s / 0x7fffffff;
            };
            for (let i = 0; i < 11; i++) {
                points.push({ x: W * (0.6 + rnd() * 0.28), y: H * (0.14 + rnd() * 0.5), c: 'a', born: performance.now() - 400 });
            }
            for (let j = 0; j < 11; j++) {
                points.push({ x: W * (0.1 + rnd() * 0.28), y: H * (0.4 + rnd() * 0.5), c: 'b', born: performance.now() - 400 });
            }
            if (hint) hint.style.opacity = '0';
            train();
            updateStats();
            draw();
        });
    }

    size();
    window.addEventListener('resize', size);
    updateStats();
}

/* ==========================================================================
   3 · The certificate simulator — BR-11 and BR-26 applied literally
   --------------------------------------------------------------------------
   Assignments 50 + final project 50 = 100 (BR-11).
   The certificate needs attendance ≥ min AND score ≥ pass, BOTH, with no
   compensation between them (BR-26).
   The thresholds are NOT hardcoded: they come from the cohort through data
   attributes, because they live in the database and are edited from the admin
   panel (BR-31, BR-36).  This is a marketing illustration — the real decision
   is made by CertificateEligibility on the server and by nothing else.
   ========================================================================== */

function certificateSimulator() {
    const root = $('#sim');
    if (!root) return;

    const sA = $('#simSessions', root);
    const sB = $('#simTasks', root);
    const sC = $('#simProject', root);
    if (!sA || !sB || !sC) return;

    const SESSIONS = num(root, 'data-sessions', 12);
    const TASKS = num(root, 'data-tasks', 4);
    const TASK_POINTS = num(root, 'data-task-points', 50);
    const PROJECT_POINTS = num(root, 'data-project-points', 50);
    const MIN_ATTENDANCE = num(root, 'data-min-attendance', 75);
    const PASS_SCORE = num(root, 'data-pass-score', 60);

    const sessionForms = {
        one: root.getAttribute('data-sessions-one') || '',
        two: root.getAttribute('data-sessions-two') || '',
        few: root.getAttribute('data-sessions-few') || '',
        many: root.getAttribute('data-sessions-many') || '',
    };

    const t = (name) => root.getAttribute('data-t-' + name) || '';

    const setText = (sel, value) => {
        const el = $(sel, root);
        if (el) el.textContent = String(value);
    };
    const setWidth = (sel, pct) => {
        const el = $(sel, root);
        if (el) el.style.inlineSize = clamp(pct, 0, 100) + '%';
    };
    const setIcon = (sel, pass) => {
        const el = $(sel, root);
        if (!el) return;
        el.innerHTML = '<svg aria-hidden="true"><use href="#' + (pass ? 'i-check' : 'i-warn') + '"></use></svg>';
    };

    function compute() {
        const attended = Number(sA.value);
        const tasksDone = Number(sB.value);
        const projectScore = Number(sC.value);

        setText('#simVSessions', attended);
        setText('#simVTasks', tasksDone);
        setText('#simVProject', projectScore);
        setWidth('#simFSessions', pct(attended, SESSIONS));
        setWidth('#simFTasks', pct(tasksDone, TASKS));
        setWidth('#simFProject', pct(projectScore, PROJECT_POINTS));

        const attendance = pct(attended, SESSIONS);
        const taskScore = TASKS > 0 ? Math.round((tasksDone / TASKS) * TASK_POINTS) : 0;
        const total = taskScore + projectScore;

        const passAttendance = attendance >= MIN_ATTENDANCE;
        const passScore = total >= PASS_SCORE;

        /* --- Gate one: attendance --------------------------------------- */
        setText('#simPctA', attendance);
        setWidth('#simBarA', attendance);
        const gateA = $('#simGateA', root);
        if (gateA) {
            gateA.classList.toggle('pass', passAttendance);
            gateA.classList.toggle('fail', !passAttendance);
        }
        setIcon('#simIcA', passAttendance);

        const required = Math.ceil((SESSIONS * MIN_ATTENDANCE) / 100);
        const noteA = $('#simNoteA', root);
        if (noteA) {
            if (passAttendance) {
                const spare = attended - required;
                noteA.textContent =
                    spare > 0
                        ? fill(t('spare'), { count: spare, sessions: plural(sessionForms, spare) })
                        : t('on_edge');
            } else {
                const need = required - attended;
                noteA.textContent = fill(t('need'), {
                    count: need,
                    sessions: plural(sessionForms, need),
                    min: MIN_ATTENDANCE,
                });
            }
        }

        /* --- Gate two: score -------------------------------------------- */
        setText('#simPctB', total);
        setWidth('#simBarB', total);
        const gateB = $('#simGateB', root);
        if (gateB) {
            gateB.classList.toggle('pass', passScore);
            gateB.classList.toggle('fail', !passScore);
        }
        setIcon('#simIcB', passScore);

        const noteB = $('#simNoteB', root);
        if (noteB) {
            noteB.textContent = fill(passScore ? t('score_over') : t('score_under'), {
                tasks: taskScore,
                project: projectScore,
                pass: PASS_SCORE,
                short: Math.max(0, PASS_SCORE - total),
            });
        }

        /* --- Verdict: both conditions, never one --------------------------- */
        const eligible = passAttendance && passScore;
        const verdict = $('#simVerdict', root);
        if (verdict) verdict.classList.toggle('pass', eligible);
        setIcon('#simVerdictIc', eligible);

        let titleKey = 'verdict_none';
        let descKey = 'verdict_none_desc';
        if (eligible) {
            titleKey = 'verdict_pass';
            descKey = 'verdict_pass_desc';
        } else if (!passAttendance && passScore) {
            titleKey = 'verdict_attendance';
            descKey = 'verdict_attendance_desc';
        } else if (passAttendance && !passScore) {
            titleKey = 'verdict_score';
            descKey = 'verdict_score_desc';
        }

        setText('#simVerdictTitle', t(titleKey));
        const desc = $('#simVerdictDesc', root);
        if (desc) {
            desc.textContent = fill(t(descKey), {
                total,
                attendance,
                min: MIN_ATTENDANCE,
                pass: PASS_SCORE,
            });
        }
    }

    [sA, sB, sC].forEach((el) => {
        el.addEventListener('input', compute);
        el.addEventListener('change', compute);
    });
    compute();
}

/* ==========================================================================
   4 · Scroll-reactive decoration
   ========================================================================== */

function parallaxNumbers() {
    if (reduced()) return;
    const nums = $$('.deck__no, .goal__n');
    const heads = $$('.sec__hd');
    if (!nums.length && !heads.length) return;
    let ticking = false;

    const run = () => {
        if (ticking) return;
        ticking = true;
        requestAnimationFrame(() => {
            ticking = false;
            const vh = window.innerHeight;
            nums.forEach((n) => {
                const r = n.getBoundingClientRect();
                if (r.bottom < -200 || r.top > vh + 200) return;
                const p = (r.top + r.height / 2 - vh / 2) / vh;
                n.style.transform = 'translateY(' + (p * -34).toFixed(1) + 'px)';
            });
            heads.forEach((h) => {
                const r = h.getBoundingClientRect();
                if (r.top < vh * 0.82 && r.bottom > 0) h.classList.add('is-seen');
            });
        });
    };

    window.addEventListener('scroll', run, { passive: true });
    run();
}

/** Heading words drift away from the cursor.  Word level only. */
function wordMagnet() {
    if (reduced() || !finePointer()) return;
    const h1 = $('.hero h1');
    if (!h1) return;
    h1.addEventListener('pointermove', (e) => {
        $$('.wd.is-lit', h1).forEach((word) => {
            const r = word.getBoundingClientRect();
            const dx = e.clientX - (r.left + r.width / 2);
            const dy = e.clientY - (r.top + r.height / 2);
            const d = Math.hypot(dx, dy);
            if (d > 190 || d === 0) {
                word.style.transform = '';
                return;
            }
            const f = (1 - d / 190) * 13;
            word.style.transform =
                'translate(' + ((-dx / d) * f).toFixed(1) + 'px,' + ((-dy / d) * f).toFixed(1) + 'px)';
        });
    });
    h1.addEventListener('pointerleave', () => {
        $$('.wd', h1).forEach((word) => {
            word.style.transform = '';
        });
    });
}

/* ==========================================================================
   Scroll and pointer motion — the landing page only
   Every function here returns immediately under prefers-reduced-motion; the
   CSS has already applied the resting end state, so nothing stays invisible
   and nothing keeps moving (Constitution Article 18).
   ========================================================================== */

/**
 * The blueprint grid follows the pointer.  Two custom properties, no layout.
 */
function blueprintGrid() {
    const grid = document.getElementById('bpGrid');
    if (!grid || reduced() || !finePointer()) return;
    let queued = false;
    let x = 50;
    let y = 30;
    const paint = () => {
        queued = false;
        grid.style.setProperty('--sx', x + '%');
        grid.style.setProperty('--sy', y + '%');
    };
    window.addEventListener('pointermove', (event) => {
        const box = grid.getBoundingClientRect();
        if (box.height === 0) return;
        x = ((event.clientX - box.left) / box.width) * 100;
        y = ((event.clientY - box.top) / box.height) * 100;
        if (queued) return;
        queued = true;
        requestAnimationFrame(paint);
    }, { passive: true });
}

/**
 * The custom chevron cursor and its trail canvas.  Both are decorative and
 * exist only for a fine pointer; the real cursor is never hidden on touch.
 */
function pointerChrome() {
    const cur = document.getElementById('cur');
    const canvas = document.getElementById('tracer');
    if (reduced() || !finePointer()) return;

    if (cur) {
        let queued = false;
        let px = 0;
        let py = 0;
        const move = () => {
            queued = false;
            cur.style.transform = 'translate(' + px + 'px,' + py + 'px)';
        };
        window.addEventListener('pointermove', (event) => {
            px = event.clientX;
            py = event.clientY;
            cur.classList.add('on');
            if (queued) return;
            queued = true;
            requestAnimationFrame(move);
        }, { passive: true });
        window.addEventListener('pointerdown', () => cur.classList.add('grab'));
        window.addEventListener('pointerup', () => cur.classList.remove('grab'));
        document.addEventListener('pointerleave', () => cur.classList.remove('on'));
    }

    if (!canvas || !canvas.getContext) return;
    const ctx = canvas.getContext('2d');
    if (!ctx) return;
    const points = [];
    const resize = () => {
        const ratio = Math.min(window.devicePixelRatio || 1, 2);
        canvas.width = Math.floor(window.innerWidth * ratio);
        canvas.height = Math.floor(window.innerHeight * ratio);
        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    };
    resize();
    window.addEventListener('resize', resize);
    window.addEventListener('pointermove', (event) => {
        points.push({ x: event.clientX, y: event.clientY, life: 1 });
        if (points.length > 24) points.shift();
    }, { passive: true });

    const stroke = getComputedStyle(document.documentElement)
        .getPropertyValue('--teal-300').trim() || 'currentColor';

    const frame = () => {
        ctx.clearRect(0, 0, window.innerWidth, window.innerHeight);
        for (let i = 1; i < points.length; i += 1) {
            const a = points[i - 1];
            const b = points[i];
            b.life -= 0.02;
            if (b.life <= 0) continue;
            ctx.beginPath();
            ctx.globalAlpha = Math.max(0, b.life) * 0.35;
            ctx.strokeStyle = stroke;
            ctx.lineWidth = 1.5;
            ctx.lineCap = 'round';
            ctx.moveTo(a.x, a.y);
            ctx.lineTo(b.x, b.y);
            ctx.stroke();
        }
        ctx.globalAlpha = 1;
        requestAnimationFrame(frame);
    };
    requestAnimationFrame(frame);
}

/**
 * The fixed trail on the inline-start edge: fill height tracks scroll depth
 * and each chevron stop lights up when its section is the one on screen.
 * Clicking a stop is a plain anchor, so the keyboard behaves identically.
 */
function traceRail() {
    const fillEl = document.getElementById('traceFill');
    const stops = $$('.trace__stop');
    if (!fillEl && stops.length === 0) return;

    const sections = stops
        .map((stop) => {
            const id = (stop.getAttribute('href') || '').replace('#', '');
            return { stop, target: id ? document.getElementById(id) : null };
        })
        .filter((entry) => entry.target !== null);

    const update = () => {
        const scrollable = Math.max(1, document.body.scrollHeight - window.innerHeight);
        const pct = clamp((window.scrollY / scrollable) * 100, 0, 100);
        if (fillEl) fillEl.style.blockSize = pct + '%';

        let active = null;
        sections.forEach((entry) => {
            const box = entry.target.getBoundingClientRect();
            if (box.top <= window.innerHeight * 0.42) active = entry.stop;
            entry.stop.classList.remove('is-on');
        });
        if (active) active.classList.add('is-on');
    };

    let queued = false;
    const onScroll = () => {
        if (queued) return;
        queued = true;
        requestAnimationFrame(() => {
            queued = false;
            update();
        });
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll);
    update();
}

/**
 * The accuracy readout: one ring whose stroke-dashoffset tracks scroll depth.
 * It is a metaphor for a model that keeps learning, not a real measurement,
 * and a text percentage sits beside it so colour is never the only signal.
 */
function accuracyHud() {
    const ring = document.getElementById('hudFg');
    const pctEl = document.getElementById('hudPct');
    if (!ring && !pctEl) return;
    if (reduced()) {
        if (ring) ring.style.strokeDashoffset = '0';
        if (pctEl) pctEl.textContent = '100';
        return;
    }
    const CIRCUMFERENCE = 88;
    const update = () => {
        const scrollable = Math.max(1, document.body.scrollHeight - window.innerHeight);
        const pct = clamp((window.scrollY / scrollable) * 100, 0, 100);
        const shown = Math.round(60 + pct * 0.39);
        if (ring) ring.style.strokeDashoffset = String(CIRCUMFERENCE * (1 - shown / 100));
        if (pctEl) pctEl.textContent = String(shown);
    };
    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);
    update();
}

/**
 * The four week cards stack and hand over as the deck scrolls past.
 * Below 900px, and under reduced motion, the CSS turns the deck into a plain
 * grid and this function does nothing at all.
 */
function weekDeck() {
    const deck = document.getElementById('deck');
    const cards = $$('#deckCards > .deck__c');
    const dots = $$('#deckProg > i');
    if (!deck || cards.length === 0) return;
    if (reduced() || !window.matchMedia('(min-width: 900px)').matches) return;

    const update = () => {
        const box = deck.getBoundingClientRect();
        const travel = Math.max(1, deck.offsetHeight - window.innerHeight);
        const progress = clamp(-box.top / travel, 0, 1);
        const exact = progress * (cards.length - 1);
        const current = Math.round(exact);

        cards.forEach((card, index) => {
            const delta = index - exact;
            const depth = Math.abs(delta);
            card.style.opacity = String(depth > 1.4 ? 0 : 1 - depth * 0.55);
            card.style.transform =
                'translate3d(0,' + delta * 42 + 'px,' + -depth * 120 + 'px) scale(' +
                (1 - depth * 0.06) + ')';
            card.style.zIndex = String(cards.length - Math.round(depth));
        });

        dots.forEach((dot, index) => dot.classList.toggle('is-on', index === current));
    };

    let queued = false;
    const onScroll = () => {
        if (queued) return;
        queued = true;
        requestAnimationFrame(() => {
            queued = false;
            update();
        });
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll);
    update();
}

/**
 * The programme timeline draws its rail and lights its dots once, when the
 * block first reaches the viewport.  The fill grows from the inline start,
 * which is the RIGHT in this RTL document (Article 16).
 */
function programTimeline() {
    const box = document.getElementById('tlBox');
    const fillEl = document.getElementById('tlFill');
    if (!box) return;

    const light = () => {
        box.classList.add('is-lit');
        if (fillEl) fillEl.style.inlineSize = '100%';
        $$('.tl__i', box).forEach((item, index) => {
            if (reduced()) {
                item.classList.add('is-on');
                return;
            }
            window.setTimeout(() => item.classList.add('is-on'), index * 140);
        });
    };

    if (reduced() || !('IntersectionObserver' in window)) {
        light();
        return;
    }
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            light();
            observer.disconnect();
        });
    }, { threshold: 0.25 });
    observer.observe(box);
}

/**
 * The topic ticker needs its set duplicated so the marquee loops without a
 * visible seam.  Duplicating here rather than in Blade keeps a screen reader
 * from reading the list twice: the copy is aria-hidden.
 */
function topicTicker() {
    const track = $('.tick__track');
    if (!track) return;
    const set = $('.tick__set', track);
    if (!set || track.children.length > 1) return;
    const clone = set.cloneNode(true);
    clone.setAttribute('aria-hidden', 'true');
    track.appendChild(clone);
}

/** Section headings lift their chevron the first time they are seen. */
function sectionHeadings() {
    const heads = $$('.sec__hd');
    if (heads.length === 0) return;
    if (reduced() || !('IntersectionObserver' in window)) {
        heads.forEach((head) => head.classList.add('is-seen'));
        return;
    }
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            entry.target.classList.add('is-seen');
            observer.unobserve(entry.target);
        });
    }, { threshold: 0.2 });
    heads.forEach((head) => observer.observe(head));
}

/**
 * The marketing header collapses into a disclosure panel below 1024px.
 * Both labels arrive from Blade as data attributes, so no Arabic lives here,
 * and the panel is a plain aria-expanded disclosure rather than a dialog.
 */
function publicNav() {
    const burger = document.getElementById('navBurger');
    const links = document.getElementById('navLinks');
    if (!burger || !links) return;

    const setOpen = (open) => {
        burger.setAttribute('aria-expanded', open ? 'true' : 'false');
        links.setAttribute('data-open', open ? 'true' : 'false');
        const label = open
            ? burger.getAttribute('data-label-close')
            : burger.getAttribute('data-label-open');
        if (label) burger.setAttribute('aria-label', label);
    };

    setOpen(false);
    burger.addEventListener('click', () => {
        setOpen(burger.getAttribute('aria-expanded') !== 'true');
    });
    links.addEventListener('click', (event) => {
        if (event.target instanceof HTMLAnchorElement) setOpen(false);
    });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        if (burger.getAttribute('aria-expanded') !== 'true') return;
        setOpen(false);
        burger.focus();
    });
}

/* ==========================================================================
   Boot
   ========================================================================== */

function boot() {
    neuralCanvas();
    classifierLab();
    certificateSimulator();
    parallaxNumbers();
    wordMagnet();
    blueprintGrid();
    pointerChrome();
    traceRail();
    accuracyHud();
    weekDeck();
    programTimeline();
    topicTicker();
    sectionHeadings();
    publicNav();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}
