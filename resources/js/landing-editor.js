/**
 * منصة أثر — the landing-page content editor ("Landing page content" tab, D-114).
 *
 * A port of the approved Sajaya editor to Alpine. Three layers for every text:
 *   1. the ORIGINAL   — lang/{ar,en}/landing.php, sent in the payload;
 *   2. the PUBLISHED  — a row in landing_contents that replaces the original;
 *   3. the DRAFT      — edits in this browser, seen only by the preview until
 *                       "publish" sends them in one request.
 *
 * The cohort's settings and its questions ride in the same draft, so one
 * publish carries everything and the preview always shows the whole picture.
 *
 * WHAT THIS FILE DOES NOT DO
 * It decides nothing. Every rule it shows at a field (a live value such as
 * :count removed, a counted form broken, a text too long) is enforced again
 * by PublishLandingRequest, and the server's answer is what counts (Art. 5).
 * Not one sentence lives here: every label comes from lang/*\/admin.php through
 * the JSON payload (Art. 15). The draft is kept in localStorage as a
 * convenience only — losing it loses nothing published.
 *
 * THE PREVIEW
 * The real page, rendered by the server with the draft (never stored), shown
 * in a frame sized as a 1280px desktop or a 390px phone and scaled to fit.
 * Two frames take turns: the next render loads in the hidden one and is shown
 * only once it has loaded and scrolled to where the visible one was, so the
 * page never blanks while typing.
 *
 * @see BR-31, BR-33 · PRD §9.1, §9.18 · CONSTITUTION Art. 5, 15, 16, 18
 */

const STORAGE_KEY = 'athar:landing-draft:v1';

/** The viewport inside the frame, not the size of the column around it. */
const FRAMES = {
    desktop: { w: 1280, h: 860 },
    mobile: { w: 390, h: 760 },
};

/** Quiet time after the last keystroke before the preview is re-rendered. */
const PREVIEW_DELAY = 450;

const SETTING_KEYS = ['is_registration_open', 'countdown_enabled', 'seats_override', 'hero_title', 'hero_subtitle', 'about_body'];

/** Which section each cohort setting belongs to, for the tab counters. */
const SETTING_SECTION = {
    is_registration_open: 'registration',
    countdown_enabled: 'registration',
    seats_override: 'registration',
    hero_title: 'hero',
    hero_subtitle: 'hero',
    about_body: 'about',
};

const PLACEHOLDER = /:([A-Za-z_]+)/g;
const MARKER = /^\s*(\{[^}]*\}|\[[^\]]*\])\s*([\s\S]*)$/;

function readPayload(root) {
    const node = document.querySelector(root.getAttribute('data-payload') || '#landing-editor-data');
    try {
        return JSON.parse(node ? node.textContent : '{}') || {};
    } catch (e) {
        return {};
    }
}

function placeholders(text) {
    return Array.from(new Set(String(text || '').match(PLACEHOLDER) || []));
}

/** `{1} one|[2,*] :count many` → [{marker, text}] — null for a plain text. */
function splitForms(text) {
    const value = String(text || '');
    if (value.indexOf('|') === -1) return null;
    return value.split('|').map((segment) => {
        const match = segment.match(MARKER);
        return match ? { marker: match[1], text: match[2] } : { marker: '', text: segment.trim() };
    });
}

/** Arabic counting: one · two · 3–10 · 11 and above (Art. 15). */
function pluralForm(forms, n) {
    if (!forms || typeof forms !== 'object') return '';
    const form = n === 1 ? forms.one : n === 2 ? forms.two : n >= 3 && n <= 10 ? forms.few : forms.many;
    return String(form || '').replace(/:count/g, String(n));
}

function fill(template, values) {
    return String(template || '').replace(/:([a-z_]+)/g, (match, key) =>
        Object.prototype.hasOwnProperty.call(values, key) ? String(values[key]) : match,
    );
}

function sameJson(a, b) {
    return JSON.stringify(a) === JSON.stringify(b);
}

function storage() {
    try {
        return window.localStorage;
    } catch (e) {
        return null;
    }
}

export default function landingEditor() {
    return {
        ready: false,
        busy: false,

        sections: [],
        published: {},
        settings: null,
        faq: null,
        seatsComputed: 0,
        programName: '',
        cohortId: null,
        max: 4000,
        urls: {},
        i18n: {},

        draft: {},
        settingsDraft: null,
        faqDraft: null,
        serverIssues: {},

        active: '',
        search: '',
        mode: 'ar',
        openGroups: {},

        showPreview: true,
        device: 'desktop',
        scale: 1,
        activeFrame: 'a',
        pendingFrame: null,
        pendingFocus: null,
        pendingScroll: 0,
        previewReady: false,
        previewTimer: null,

        confirm: { open: false, title: '', body: '', label: '', action: null, opener: null },

        uid: 0,
        fieldIndex: {},
        sectionOfKey: {},

        /* ------------------------------------------------------------ boot */

        init() {
            const payload = readPayload(this.$el);

            this.sections = Array.isArray(payload.sections) ? payload.sections : [];
            this.published = payload.published || {};
            this.settings = payload.settings || null;
            this.faq = Array.isArray(payload.faq) ? payload.faq.map((row) => this.withUid(row)) : null;
            this.seatsComputed = payload.seatsComputed || 0;
            this.programName = payload.programName || '';
            this.cohortId = payload.cohortId || null;
            this.max = payload.max || 4000;
            this.urls = payload.urls || {};
            this.i18n = payload.i18n || {};

            this.sections.forEach((section) => {
                section.groups.forEach((group) => {
                    group.fields.forEach((field) => {
                        this.fieldIndex[field.key] = field;
                        this.sectionOfKey[field.key] = section.key;
                    });
                });
            });

            this.active = this.sections.length ? this.sections[0].key : '';
            this.restoreDraft();

            this.$watch('device', () => this.fit());
            this.$watch('showPreview', (shown) => {
                if (shown) this.$nextTick(() => this.fit());
            });

            this.bindWindow();
            this.ready = true;

            this.$nextTick(() => {
                this.observeStage();
                this.sendPreview(true);
            });
        },

        bindWindow() {
            window.addEventListener('beforeunload', (event) => {
                if (this.dirtyCount() === 0) return;
                event.preventDefault();
                event.returnValue = this.t('leave_warning');
            });

            window.addEventListener('keydown', (event) => {
                if (!(event.ctrlKey || event.metaKey) || String(event.key).toLowerCase() !== 's') return;
                event.preventDefault();
                this.publish();
            });
        },

        /* ------------------------------------------------------- messages */

        t(key, values) {
            const value = this.i18n[key];
            return typeof value === 'string' ? fill(value, values || {}) : '';
        },

        counted(key, n) {
            return pluralForm(this.i18n[key], n);
        },

        fieldsLabel(n) {
            return this.counted('fields', n);
        },

        langName(lang) {
            return this.t('lang_' + lang);
        },

        /* --------------------------------------------------------- values */

        def(key, lang) {
            const field = this.fieldIndex[key];
            return field ? String(field[lang] || '') : '';
        },

        publishedValue(key, lang) {
            const row = this.published[key];
            return (row && row[lang]) || this.def(key, lang);
        },

        value(key, lang) {
            const row = this.draft[key];
            return row ? row[lang] : this.publishedValue(key, lang);
        },

        isDraft(key) {
            const row = this.draft[key];
            if (!row) return false;
            return row.ar !== this.publishedValue(key, 'ar') || row.en !== this.publishedValue(key, 'en');
        },

        isPublished(key) {
            const row = this.published[key];
            return !!(row && (row.ar || row.en));
        },

        fieldState(key) {
            if (this.isDraft(key)) return 'draft';
            return this.isPublished(key) ? 'published' : 'original';
        },

        showOriginal(field, lang) {
            const original = String(field[lang] || '');
            return this.fieldState(field.key) !== 'original' && original !== '' && original !== this.value(field.key, lang);
        },

        edit(key, lang, text) {
            const next = {
                ar: lang === 'ar' ? text : this.value(key, 'ar'),
                en: lang === 'en' ? text : this.value(key, 'en'),
            };

            if (next.ar === this.publishedValue(key, 'ar') && next.en === this.publishedValue(key, 'en')) {
                delete this.draft[key];
            } else {
                this.draft[key] = next;
            }

            delete this.serverIssues[key + '|' + lang];
            this.changed();
        },

        /** Back to the file's text in both languages; publishing stores NULL. */
        revert(key) {
            const original = { ar: this.def(key, 'ar'), en: this.def(key, 'en') };

            if (!this.isPublished(key)) {
                delete this.draft[key];
            } else {
                this.draft[key] = original;
            }

            delete this.serverIssues[key + '|ar'];
            delete this.serverIssues[key + '|en'];
            this.changed();
        },

        /* One counted form of a plural text. The markers are the original's. */
        formsOf(field, lang) {
            const original = field.forms[lang] || [];
            const current = splitForms(this.value(field.key, lang));
            if (current && current.length === original.length) return current;
            return splitForms(field[lang]) || [];
        },

        formValue(field, lang, index) {
            const forms = this.formsOf(field, lang);
            return forms[index] ? forms[index].text : '';
        },

        editForm(field, lang, index, text) {
            const markers = (field.forms[lang] || []).map((form) => form.marker);
            const forms = this.formsOf(field, lang).map((form) => form.text);
            forms[index] = text;
            const joined = markers.map((marker, i) => (marker ? marker + ' ' : '') + (forms[i] || '')).join('|');
            this.edit(field.key, lang, joined);
        },

        /* ------------------------------------------------------ validation */

        issuesOf(key, lang) {
            const text = String(this.value(key, lang) || '').trim();
            if (text === '' || !this.isDraft(key)) return [];

            const issues = [];
            const original = this.def(key, lang);

            if (text.length > this.max) issues.push('too_long');

            const missing = placeholders(original).filter((name) => text.indexOf(name) === -1);
            if (missing.length) issues.push('placeholders');

            const expected = splitForms(original);
            if (expected) {
                const given = splitForms(text);
                const markers = (forms) => forms.map((form) => form.marker).join('|');
                if (!given || markers(given) !== markers(expected)) issues.push('plural');
            }

            return issues;
        },

        issueText(key, lang) {
            const server = this.serverIssues[key + '|' + lang];
            if (server) return server;

            const errors = this.i18n.errors || {};
            return this.issuesOf(key, lang)
                .map((code) => fill(errors[code] || '', {
                    max: this.max,
                    vars: placeholders(this.def(key, lang))
                        .filter((name) => String(this.value(key, lang)).indexOf(name) === -1)
                        .join(' '),
                }))
                .join(' ');
        },

        fieldHasIssue(key) {
            return this.issueText(key, 'ar') !== '' || this.issueText(key, 'en') !== '';
        },

        /* --------------------------------------------------- the settings */

        setting(name) {
            const source = this.settingsDraft || this.settings || {};
            return source[name];
        },

        setSetting(name, value) {
            if (!this.settings) return;
            const next = Object.assign({}, this.settingsDraft || this.settings);
            next[name] = value;
            this.settingsDraft = sameJson(this.normalSettings(next), this.normalSettings(this.settings)) ? null : next;
            this.changed();
        },

        normalSettings(source) {
            const out = {};
            SETTING_KEYS.forEach((key) => {
                let value = source ? source[key] : null;
                if (typeof value === 'string') value = value.trim();
                if (value === '' || value === undefined || Number.isNaN(value)) value = null;
                out[key] = value;
            });
            out.is_registration_open = !!out.is_registration_open;
            out.countdown_enabled = !!out.countdown_enabled;
            return out;
        },

        changedSettings() {
            if (!this.settingsDraft || !this.settings) return [];
            const now = this.normalSettings(this.settingsDraft);
            const was = this.normalSettings(this.settings);
            return SETTING_KEYS.filter((key) => now[key] !== was[key]);
        },

        /* -------------------------------------------------------- the FAQ */

        withUid(row) {
            this.uid += 1;
            return { key: row.key || null, question: String(row.question || ''), answer: String(row.answer || ''), uid: this.uid };
        },

        faqItems() {
            return this.faqDraft || this.faq || [];
        },

        faqNumber(index) {
            return this.t('faq_number', { n: index + 1 }) || String(index + 1);
        },

        editableFaq() {
            if (!this.faqDraft) this.faqDraft = (this.faq || []).map((row) => Object.assign({}, row));
            return this.faqDraft;
        },

        setFaq(index, name, value) {
            const list = this.editableFaq();
            if (!list[index]) return;
            list[index][name] = value;
            this.settleFaq();
        },

        addFaq() {
            if (!this.faq) return;
            this.editableFaq().push(this.withUid({}));
            this.settleFaq();
        },

        removeFaq(index) {
            this.editableFaq().splice(index, 1);
            this.settleFaq();
        },

        moveFaq(index, step) {
            const list = this.editableFaq();
            const target = index + step;
            if (target < 0 || target >= list.length) return;
            const moved = list.splice(index, 1)[0];
            list.splice(target, 0, moved);
            this.settleFaq();
        },

        faqRows(list) {
            return (list || []).map((row) => ({ key: row.key, question: row.question.trim(), answer: row.answer.trim() }));
        },

        /** What is published: the rows, minus any left entirely empty. */
        faqPayload(list) {
            return this.faqRows(list).filter((row) => row.question !== '' || row.answer !== '');
        },

        /** A draft equal to the published list is no draft; an added empty row is. */
        settleFaq() {
            if (this.faqDraft && sameJson(this.faqRows(this.faqDraft), this.faqRows(this.faq))) {
                this.faqDraft = null;
            }
            this.changed();
        },

        faqIssue(item) {
            const q = item.question.trim();
            const a = item.answer.trim();
            return (q === '') !== (a === '');
        },

        faqDirty() {
            return this.faqDraft !== null;
        },

        /* ---------------------------------------------------- the counters */

        dirtyKeys() {
            return Object.keys(this.draft).filter((key) => this.isDraft(key));
        },

        dirtyCount() {
            return this.dirtyKeys().length + this.changedSettings().length + (this.faqDirty() ? 1 : 0);
        },

        invalidCount() {
            let count = this.dirtyKeys().filter((key) => this.fieldHasIssue(key)).length;
            if (this.faqDraft) count += this.faqDraft.filter((item) => this.faqIssue(item)).length;
            return count;
        },

        sectionDrafts(section) {
            let count = this.dirtyKeys().filter((key) => this.sectionOfKey[key] === section).length;
            count += this.changedSettings().filter((key) => SETTING_SECTION[key] === section).length;
            if (section === 'faq' && this.faqDirty()) count += 1;
            return count;
        },

        sectionPublished(section) {
            return Object.keys(this.published).filter((key) => this.sectionOfKey[key] === section && this.isPublished(key)).length;
        },

        groupDrafts(group) {
            let count = group.fields.filter((field) => this.isDraft(field.key)).length;
            if (group.setting === 'registration') count += this.changedSettings().filter((key) => SETTING_SECTION[key] === 'registration').length;
            if (group.setting === 'hero_copy') count += this.changedSettings().filter((key) => SETTING_SECTION[key] === 'hero').length;
            if (group.setting === 'about_copy') count += this.changedSettings().filter((key) => SETTING_SECTION[key] === 'about').length;
            if (group.setting === 'faq_list' && this.faqDirty()) count += 1;
            return count;
        },

        draftNote() {
            const n = this.dirtyCount();
            return n === 0 ? this.t('nothing_to_publish') : this.counted('draft_note', n);
        },

        blockedNote() {
            return this.counted('blocked', this.invalidCount());
        },

        publishLabel() {
            const n = this.dirtyCount();
            return n === 0 ? this.t('publish') : this.t('publish_count', { count: n });
        },

        /* -------------------------------------------------- tabs & groups */

        current() {
            return this.sections.find((section) => section.key === this.active) || this.sections[0] || null;
        },

        openSection(key) {
            this.active = key;
            this.search = '';
            this.focusPreview();
        },

        onTabsKey(event) {
            const rtl = document.documentElement.dir === 'rtl';
            const keys = { ArrowRight: rtl ? -1 : 1, ArrowLeft: rtl ? 1 : -1, Home: -Infinity, End: Infinity };
            if (!(event.key in keys)) return;
            event.preventDefault();

            const at = this.sections.findIndex((section) => section.key === this.active);
            const step = keys[event.key];
            const next = Math.min(this.sections.length - 1, Math.max(0, step === -Infinity ? 0 : step === Infinity ? this.sections.length - 1 : at + step));
            this.openSection(this.sections[next].key);
            this.$nextTick(() => {
                const tab = document.getElementById('le-tab-' + this.sections[next].key);
                if (tab) tab.focus();
            });
        },

        isOpen(group) {
            // `in`, not hasOwnProperty: the reactive proxy tracks the former, so
            // a group opened for the first time redraws.
            return group.id in this.openGroups ? this.openGroups[group.id] : !group.collapsed;
        },

        toggleGroup(group) {
            this.openGroups[group.id] = !this.isOpen(group);
        },

        langs() {
            return this.mode === 'both' ? ['ar', 'en'] : [this.mode];
        },

        fieldId(field, lang) {
            return 'le-f-' + field.key.replace(/[^a-z0-9]+/gi, '-') + '-' + lang;
        },

        setMode(mode) {
            if (this.mode === mode) return;
            const previewLang = this.previewLang();
            this.mode = mode;
            if (this.previewLang() !== previewLang) this.sendPreview(false);
        },

        /* ---------------------------------------------------------- search */

        get query() {
            return String(this.search || '').trim().toLowerCase();
        },

        matches(field) {
            const q = this.query;
            if (q === '') return true;
            return [field.key, field.ar, field.en, field.role || '', this.value(field.key, 'ar'), this.value(field.key, 'en')]
                .some((text) => String(text).toLowerCase().indexOf(q) !== -1);
        },

        results() {
            if (this.query === '') return [];
            return this.sections
                .map((section) => ({
                    section,
                    fields: section.groups.reduce((all, group) => all.concat(group.fields.filter((field) => this.matches(field))), []),
                }))
                .filter((hit) => hit.fields.length > 0);
        },

        searchNote() {
            return this.t('search_results', { q: String(this.search || '').trim() });
        },

        /* --------------------------------------------------- persistence */

        changed() {
            this.persist();
            this.schedulePreview();
        },

        persist() {
            const store = storage();
            if (!store) return;
            try {
                const texts = {};
                this.dirtyKeys().forEach((key) => {
                    texts[key] = this.draft[key];
                });
                const empty = !Object.keys(texts).length && !this.settingsDraft && !this.faqDraft;
                if (empty) {
                    store.removeItem(STORAGE_KEY);
                } else {
                    store.setItem(STORAGE_KEY, JSON.stringify({
                        cohort: this.cohortId,
                        texts,
                        settings: this.settingsDraft,
                        faq: this.faqDraft,
                    }));
                }
            } catch (e) {
                /* No storage: the draft lives in memory for this visit. */
            }
        },

        /**
         * A draft left in this browser. Keys the page no longer has are dropped,
         * and cohort settings are kept only for the cohort they were written for.
         */
        restoreDraft() {
            const store = storage();
            if (!store) return;
            let saved = null;
            try {
                saved = JSON.parse(store.getItem(STORAGE_KEY) || 'null');
            } catch (e) {
                saved = null;
            }
            if (!saved || typeof saved !== 'object') return;

            const texts = saved.texts && typeof saved.texts === 'object' ? saved.texts : {};
            Object.keys(texts).forEach((key) => {
                const row = texts[key];
                if (this.fieldIndex[key] && row && typeof row.ar === 'string' && typeof row.en === 'string') {
                    this.draft[key] = { ar: row.ar, en: row.en };
                }
            });

            if (saved.cohort && saved.cohort === this.cohortId && this.settings) {
                if (saved.settings && typeof saved.settings === 'object') {
                    this.settingsDraft = Object.assign({}, this.settings, saved.settings);
                    if (!this.changedSettings().length) this.settingsDraft = null;
                }
                if (Array.isArray(saved.faq)) {
                    this.faqDraft = saved.faq.map((row) => this.withUid(row || {}));
                    if (sameJson(this.faqRows(this.faqDraft), this.faqRows(this.faq))) this.faqDraft = null;
                }
            }

            if (this.dirtyCount() > 0) this.toast(this.t('draft_restored'), 'info');
            this.persist();
        },

        clearDraft() {
            this.draft = {};
            this.settingsDraft = null;
            this.faqDraft = null;
            this.serverIssues = {};
            this.persist();
        },

        /* -------------------------------------------------------- publish */

        async publish() {
            if (this.busy || this.dirtyCount() === 0) return;
            if (this.invalidCount() > 0) {
                this.toast(this.blockedNote(), 'bad');
                return;
            }

            const keys = this.dirtyKeys();
            const body = {};
            if (keys.length) {
                body.texts = keys.map((key) => ({
                    key,
                    ar: String(this.draft[key].ar || '').trim(),
                    en: String(this.draft[key].en || '').trim(),
                }));
            }
            if (this.changedSettings().length) body.settings = this.normalSettings(this.settingsDraft);
            if (this.faqDirty()) body.faq = this.faqPayload(this.faqDraft);

            const answer = await this.send('PUT', this.urls.publish, body);
            if (!answer) return;

            if (answer.status === 422) {
                this.absorbErrors(answer.data, body.texts || []);
                return;
            }

            this.absorbState(answer.data.state);
            this.clearDraft();
            this.toast(answer.data.message, 'ok');
            this.sendPreview(false);
        },

        absorbErrors(data, sent) {
            const errors = (data && data.errors) || {};
            let mapped = 0;
            Object.keys(errors).forEach((path) => {
                const match = path.match(/^texts\.(\d+)\.(ar|en)$/);
                if (match && sent[Number(match[1])]) {
                    this.serverIssues[sent[Number(match[1])].key + '|' + match[2]] = [].concat(errors[path]).join(' ');
                    mapped += 1;
                }
            });
            const first = Object.keys(errors)[0];
            this.toast(mapped ? this.t('invalid') : (first ? [].concat(errors[first]).join(' ') : this.t('server_error')), 'bad');
        },

        absorbState(state) {
            if (!state) return;
            this.published = state.published || {};
            this.settings = state.settings || null;
            this.faq = Array.isArray(state.faq) ? state.faq.map((row) => this.withUid(row)) : null;
            this.seatsComputed = state.seatsComputed || 0;
        },

        async send(method, url, body) {
            this.busy = true;
            try {
                const response = await fetch(url, {
                    method,
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': this.csrf(),
                    },
                    body: JSON.stringify(body || {}),
                });
                let data = {};
                try {
                    data = await response.json();
                } catch (e) {
                    data = {};
                }
                if (response.ok || response.status === 422) return { status: response.status, data };
                this.toast(this.t(response.status === 419 || response.status === 401 ? 'session_expired' : 'server_error'), 'bad');
                return null;
            } catch (e) {
                this.toast(this.t('network_error'), 'bad');
                return null;
            } finally {
                this.busy = false;
            }
        },

        csrf() {
            const meta = document.querySelector('meta[name="csrf-token"]');
            return meta ? meta.getAttribute('content') : '';
        },

        toast(message, tone) {
            if (message && window.Athar && window.Athar.toast) window.Athar.toast(message, tone);
        },

        /* ---------------------------------------------- discard and reset */

        askDiscard() {
            this.ask(this.t('discard_title'), this.t('discard_body'), this.t('discard_confirm'), () => {
                this.clearDraft();
                this.toast(this.t('discard_done'), 'info');
                this.sendPreview(false);
            });
        },

        askResetSection(section) {
            this.ask(
                this.t('reset_section_title', { section: section.label }),
                this.t('reset_section_body'),
                this.t('reset_section_confirm'),
                () => this.reset(section.key),
            );
        },

        askResetAll() {
            this.ask(this.t('reset_all_title'), this.t('reset_all_body'), this.t('reset_all_confirm'), () => this.reset(null));
        },

        async reset(section) {
            const answer = await this.send('DELETE', this.urls.reset, section ? { section } : {});
            if (!answer || answer.status === 422) {
                if (answer) this.absorbErrors(answer.data, []);
                return;
            }

            // The section's texts go back to the file, so their drafts go too.
            Object.keys(this.draft).forEach((key) => {
                if (section === null || this.sectionOfKey[key] === section) delete this.draft[key];
            });
            this.absorbState(answer.data.state);
            this.persist();
            this.toast(answer.data.message, 'ok');
            this.sendPreview(false);
        },

        ask(title, body, label, action) {
            this.confirm = { open: true, title, body, label, action, opener: document.activeElement };
            this.$nextTick(() => {
                if (this.$refs.confirmGo) this.$refs.confirmGo.focus();
            });
        },

        closeConfirm() {
            if (!this.confirm.open) return;
            const opener = this.confirm.opener;
            this.confirm = { open: false, title: '', body: '', label: '', action: null, opener: null };
            if (opener && typeof opener.focus === 'function') opener.focus();
        },

        runConfirm() {
            const action = this.confirm.action;
            this.closeConfirm();
            if (typeof action === 'function') action();
        },

        /** Tab and Shift+Tab stay inside the dialog while it is open (Art. 18). */
        trapConfirm(event) {
            if (!this.confirm.open || event.key !== 'Tab' || !this.$refs.confirmPanel) return;
            const items = Array.from(this.$refs.confirmPanel.querySelectorAll('button'));
            if (!items.length) return;
            const first = items[0];
            const last = items[items.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        },

        /* ---------------------------------------------------------- preview */

        previewLang() {
            return this.mode === 'en' ? 'en' : 'ar';
        },

        /** The whole state the preview renders: published, with the draft on top. */
        previewState() {
            const texts = {};
            Object.keys(this.published).forEach((key) => {
                texts[key] = { ar: this.published[key].ar || null, en: this.published[key].en || null };
            });
            Object.keys(this.draft).forEach((key) => {
                texts[key] = { ar: this.draft[key].ar, en: this.draft[key].en };
            });

            const state = { texts };
            if (this.settingsDraft) state.settings = this.normalSettings(this.settingsDraft);
            if (this.faqDraft) state.faq = this.faqPayload(this.faqDraft);
            return state;
        },

        schedulePreview() {
            window.clearTimeout(this.previewTimer);
            this.previewTimer = window.setTimeout(() => this.sendPreview(false), PREVIEW_DELAY);
        },

        refreshPreview() {
            this.previewReady = false;
            this.sendPreview(false);
        },

        frameNamed(name) {
            return name === 'a' ? this.$refs.frameA : this.$refs.frameB;
        },

        /** Render the state into the hidden frame; it is shown once it has loaded. */
        sendPreview(focus) {
            const form = this.$refs.previewForm;
            if (!form) return;
            window.clearTimeout(this.previewTimer);

            const target = this.activeFrame === 'a' ? 'b' : 'a';
            try {
                const win = this.frameNamed(this.activeFrame).contentWindow;
                this.pendingScroll = win ? win.scrollY : 0;
            } catch (e) {
                this.pendingScroll = 0;
            }

            this.pendingFrame = target;
            this.pendingFocus = focus ? (this.current() || {}).anchor || 'top' : null;
            this.$refs.previewState.value = JSON.stringify(this.previewState());
            this.$refs.previewLang.value = this.previewLang();
            form.setAttribute('target', 'le-frame-' + target);
            form.submit();
        },

        frameLoaded(name) {
            if (name !== this.pendingFrame) return;
            const frame = this.frameNamed(name);
            let win = null;
            try {
                win = frame.contentWindow;
                // The empty document a frame starts with also fires `load`;
                // only the page the form asked for counts.
                if (win && win.location.href === 'about:blank') return;
            } catch (e) {
                win = null;
            }

            if (win) {
                try {
                    if (this.pendingFocus && win.AtharPreview) {
                        win.AtharPreview.focus(this.pendingFocus, false);
                    } else {
                        win.scrollTo(0, this.pendingScroll);
                    }
                } catch (e) {
                    /* A frame that is not the preview page is shown as it is. */
                }
            }

            this.activeFrame = name;
            this.pendingFrame = null;
            this.previewReady = true;
        },

        /** Jump the visible frame to the section being edited, and flash it. */
        focusPreview() {
            if (!this.showPreview) return;
            const anchor = (this.current() || {}).anchor || 'top';
            try {
                const win = this.frameNamed(this.activeFrame).contentWindow;
                if (win && win.AtharPreview) {
                    win.AtharPreview.focus(anchor, true);
                    return;
                }
            } catch (e) {
                /* fall through to a fresh render */
            }
            this.sendPreview(true);
        },

        setDevice(device) {
            if (this.device === device) return;
            this.device = device;
            this.$nextTick(() => {
                this.fit();
                // The page's own scripts measure the viewport once; a fresh
                // render lets them measure the new one.
                this.sendPreview(false);
            });
        },

        observeStage() {
            const stage = this.$refs.stage;
            if (!stage) return;
            this.fit();
            if (typeof window.ResizeObserver === 'function') {
                new window.ResizeObserver(() => this.fit()).observe(stage);
            } else {
                window.addEventListener('resize', () => this.fit());
            }
        },

        /** Scale the fixed-size frame down to the column, never up. */
        fit() {
            const stage = this.$refs.stage;
            if (!stage) return;
            const frame = FRAMES[this.device];
            const width = stage.clientWidth;
            if (width > 0) this.scale = Math.min(1, width / frame.w);
        },

        stageStyle() {
            const frame = FRAMES[this.device];
            return 'width:' + Math.round(frame.w * this.scale) + 'px;height:' + Math.round(frame.h * this.scale) + 'px;';
        },

        frameStyle() {
            const frame = FRAMES[this.device];
            return 'width:' + frame.w + 'px;height:' + frame.h + 'px;transform:scale(' + this.scale + ');';
        },
    };
}
