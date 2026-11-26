/*
 * Pola formularzy wspólne dla widoków (Etap 11): filtr tekstowy z operatorami, lista wyboru z filtrowaniem
 * w trakcie pisania oraz pole na plik — wybór z komputera, przeciągnięcie i wklejenie (Ctrl+V).
 */
(function (CertiSub) {
    'use strict';

    const { t } = CertiSub;

    // ── Filtr tekstowy ────────────────────────────────────────────────────────

    /** Tekst do porównań: małe litery bez znaków diakrytycznych („Łódź” → „lodz”). */
    function fold(value) {
        return String(value === null || value === undefined ? '' : value)
            .toLowerCase()
            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '')
            .replace(/ł/g, 'l');
    }

    /**
     * Zapytanie z operatorami: spacja — wszystkie słowa (AND), „|” — którakolwiek z grup (OR),
     * „-słowo” — bez tego słowa (NOT), „"dwa słowa"” — dosłowna fraza.
     */
    function parseQuery(query) {
        return String(query || '').split('|').map((group) => {
            const terms = [];
            const pattern = /(-?)"([^"]+)"|(\S+)/g;
            let match = pattern.exec(group);
            while (match !== null) {
                if (match[2] !== undefined) {
                    terms.push({ text: fold(match[2]), not: match[1] === '-' });
                } else {
                    const negated = match[3].length > 1 && match[3].charAt(0) === '-';
                    terms.push({ text: fold(negated ? match[3].slice(1) : match[3]), not: negated });
                }
                match = pattern.exec(group);
            }
            return terms;
        }).filter((terms) => terms.length > 0);
    }

    /** Zwraca funkcję sprawdzającą, czy wartości rekordu pasują do zapytania. */
    function matcher(query) {
        const groups = parseQuery(query);
        if (groups.length === 0) {
            return () => true;
        }
        return (values) => {
            const haystack = fold([].concat(values).filter((value) => value !== null && value !== undefined && value !== '').join(' \n '));
            return groups.some((terms) => terms.every((term) => (haystack.indexOf(term.text) !== -1) !== term.not));
        };
    }

    CertiSub.textFilter = { fold, matcher };

    // ── Lista wyboru z filtrowaniem ───────────────────────────────────────────

    const LIMIT = 100;
    let selectSequence = 0;

    CertiSub.components.SearchSelect = {
        props: {
            modelValue: { type: [String, Number], default: '' },
            /** [{ value, label, hint?, search? }] — „search” to dodatkowy tekst do filtrowania (np. NIP). */
            options: { type: Array, required: true },
            /** Tekst pustej pozycji na początku listy (np. „— wybierz —”); null — bez pustej pozycji. */
            emptyLabel: { type: String, default: null },
            disabled: { type: Boolean, default: false },
            invalid: { type: Boolean, default: false },
            ariaLabel: { type: String, default: '' },
        },
        emits: ['update:modelValue', 'change'],
        data() {
            selectSequence += 1;
            return { open: false, query: '', active: 0, position: {}, listId: 'search-select-' + selectSequence };
        },
        computed: {
            items() {
                const items = this.options.map((option) => Object.assign({}, option, { value: String(option.value) }));
                if (this.emptyLabel !== null) {
                    items.unshift({ value: '', label: this.emptyLabel, empty: true });
                }
                return items;
            },
            selected() {
                const value = String(this.modelValue === null || this.modelValue === undefined ? '' : this.modelValue);
                return this.items.find((item) => item.value === value) || null;
            },
            filtered() {
                const matches = matcher(this.query);
                const typed = this.query.trim() !== '';
                return this.items.filter((item) => (item.empty ? !typed : matches([item.label, item.hint, item.search])));
            },
            visible() {
                return this.filtered.slice(0, LIMIT);
            },
            total() {
                return this.options.length;
            },
            shown() {
                return this.filtered.filter((item) => !item.empty).length;
            },
            display() {
                if (this.open) {
                    return this.query;
                }
                return this.selected ? this.selected.label : '';
            },
        },
        beforeUnmount() {
            this.unwatch();
        },
        methods: {
            show() {
                if (this.disabled || this.open) {
                    return;
                }
                this.query = '';
                this.open = true;
                this.active = Math.max(0, this.visible.findIndex((item) => this.selected && item.value === this.selected.value));
                this.place();
                window.addEventListener('scroll', this.place, true);
                window.addEventListener('resize', this.place);
                this.$nextTick(this.reveal);
            },
            hide() {
                this.open = false;
                this.query = '';
                this.unwatch();
            },
            unwatch() {
                window.removeEventListener('scroll', this.place, true);
                window.removeEventListener('resize', this.place);
            },
            /** Lista jest pozycjonowana względem okna, więc nie obcina jej przewijany panel ani okno formularza. */
            place() {
                const input = this.$refs.input;
                if (!input) {
                    return;
                }
                const rect = input.getBoundingClientRect();
                const below = window.innerHeight - rect.bottom;
                const above = rect.top;
                const upwards = below < 240 && above > below;
                const height = Math.max(120, Math.min(320, (upwards ? above : below) - 12));
                const width = Math.max(rect.width, 240);
                this.position = {
                    left: Math.max(8, Math.min(rect.left, window.innerWidth - width - 8)) + 'px',
                    width: width + 'px',
                    maxHeight: height + 'px',
                    top: upwards ? 'auto' : (rect.bottom + 4) + 'px',
                    bottom: upwards ? (window.innerHeight - rect.top + 4) + 'px' : 'auto',
                };
            },
            reveal() {
                const list = this.$refs.list;
                const item = list && list.querySelector('[data-active="true"]');
                if (item) {
                    item.scrollIntoView({ block: 'nearest' });
                }
            },
            onInput(event) {
                const typed = event.target.value;
                this.show();
                this.query = typed;
                this.active = 0;
                this.$nextTick(this.place);
            },
            pick(item) {
                this.hide();
                this.$emit('update:modelValue', item.value);
                this.$emit('change', item.value);
            },
            onKey(event) {
                if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                    event.preventDefault();
                    if (!this.open) {
                        this.show();
                        return;
                    }
                    const step = event.key === 'ArrowDown' ? 1 : -1;
                    this.active = (this.active + step + this.visible.length) % Math.max(1, this.visible.length);
                    this.$nextTick(this.reveal);
                } else if (event.key === 'Enter' && this.open) {
                    event.preventDefault();
                    if (this.visible[this.active]) {
                        this.pick(this.visible[this.active]);
                    }
                } else if (event.key === 'Escape' && this.open) {
                    // Esc zamyka tylko listę, a nie całe okno formularza.
                    event.preventDefault();
                    event.stopPropagation();
                    this.hide();
                } else if (event.key === 'Tab') {
                    this.hide();
                }
            },
            onFocus(event) {
                this.show();
                event.target.select();
            },
        },
        template: `
            <span class="block relative w-full min-w-0">
                <input ref="input" type="text" role="combobox" autocomplete="off" :value="display" :disabled="disabled"
                       :class="['input pr-8 truncate', invalid ? 'input-error' : '']" :aria-expanded="open ? 'true' : 'false'" :aria-controls="listId"
                       :aria-label="ariaLabel || null" :placeholder="open ? (selected && !selected.empty ? selected.label : t('select.type_to_filter')) : ''"
                       @focus="onFocus" @click="show" @input="onInput" @keydown="onKey" @blur="hide">
                <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs" aria-hidden="true">▾</span>
                <Teleport to="body">
                    <ul v-if="open" :id="listId" ref="list" role="listbox" class="fixed z-[65] overflow-y-auto bg-white border border-slate-200 rounded-lg shadow-xl text-sm text-slate-800" :style="position" @mousedown.prevent>
                        <li class="sticky top-0 px-3 py-1.5 text-xs text-slate-400 bg-slate-50 border-b border-slate-100" aria-hidden="true">{{ t('select.count', { shown: shown, total: total }) }}</li>
                        <li v-for="(item, index) in visible" :key="item.value" role="option" :aria-selected="selected && item.value === selected.value ? 'true' : 'false'"
                            :data-active="index === active ? 'true' : 'false'"
                            :class="['px-3 py-2 cursor-pointer', index === active ? 'bg-brand-50' : '', selected && item.value === selected.value ? 'font-semibold text-brand-800' : '', item.empty ? 'text-slate-500' : '']"
                            @mousemove="active = index" @click="pick(item)">
                            <span class="block break-words">{{ item.label }}</span>
                            <span v-if="item.hint" class="block text-xs font-normal text-slate-400 break-words">{{ item.hint }}</span>
                        </li>
                        <li v-if="visible.length === 0" class="px-3 py-3 text-slate-400">{{ t('select.none') }}</li>
                        <li v-if="filtered.length > visible.length" class="px-3 py-1.5 text-xs text-slate-400 border-t border-slate-100">{{ t('select.more', { count: filtered.length - visible.length }) }}</li>
                    </ul>
                </Teleport>
            </span>
        `,
    };

    // ── Pole na plik: wybór, przeciągnięcie, wklejenie ────────────────────────

    const zones = [];

    function hasFiles(event) {
        return Boolean(event.dataTransfer) && Array.from(event.dataTransfer.types || []).indexOf('Files') !== -1;
    }

    /** Plik upuszczony obok pola nie może otworzyć się w karcie zamiast panelu. */
    ['dragover', 'drop'].forEach((name) => {
        window.addEventListener(name, (event) => {
            if (hasFiles(event)) {
                event.preventDefault();
            }
        });
    });

    /** Pole docelowe wklejenia: z fokusem, pod kursorem, a gdy jest jedyne na ekranie — to jedyne. */
    function pasteTarget() {
        const visible = zones.filter((zone) => !zone.disabled && zone.$el.offsetParent !== null);
        const pointed = visible.find((zone) => zone.$el.contains(document.activeElement)) || visible.find((zone) => zone.hovered);
        return { zone: pointed || (visible.length === 1 ? visible[0] : null), pointed: Boolean(pointed), any: visible.length > 0 };
    }

    document.addEventListener('paste', (event) => {
        const clipboard = event.clipboardData;
        if (!clipboard) {
            return;
        }
        const files = Array.from(clipboard.files || []);
        const typing = event.target && event.target.closest && event.target.closest('input, textarea, [contenteditable="true"]');
        if (files.length === 0 && typing) {
            return;
        }

        const target = pasteTarget();
        if (!target.zone) {
            if (files.length > 0 && target.any) {
                CertiSub.notify(t('drop.choose_zone'), 'error');
            }
            return;
        }
        if (files.length > 0) {
            event.preventDefault();
            target.zone.take(files);
        } else if (target.pointed && target.zone.takeText(clipboard.getData('text/plain'))) {
            // Wklejony tekst trafia tylko do wskazanego pola — nigdy „przypadkiem”.
            event.preventDefault();
        }
    });

    CertiSub.components.FileDrop = {
        props: {
            /** Dozwolone rozszerzenia i typy, jak w atrybucie accept; pusty napis — dowolny plik. */
            accept: { type: String, default: '' },
            label: { type: String, default: '' },
            hint: { type: String, default: '' },
            fileName: { type: String, default: '' },
            /** Nazwa pliku tworzonego z wklejonego tekstu; pusta — pole przyjmuje tylko pliki. */
            textName: { type: String, default: '' },
            disabled: { type: Boolean, default: false },
        },
        emits: ['file'],
        data() {
            return { depth: 0, hovered: false };
        },
        mounted() {
            zones.push(this);
        },
        beforeUnmount() {
            const index = zones.indexOf(this);
            if (index !== -1) {
                zones.splice(index, 1);
            }
        },
        methods: {
            browse() {
                if (!this.disabled) {
                    this.$refs.input.click();
                }
            },
            allowed(file) {
                const extensions = this.accept.split(',').map((item) => item.trim().toLowerCase()).filter((item) => item.charAt(0) === '.');
                return extensions.length === 0 || extensions.some((extension) => String(file.name).toLowerCase().endsWith(extension));
            },
            take(files) {
                const file = files && files[0];
                if (this.disabled || !file) {
                    return;
                }
                if (!this.allowed(file)) {
                    CertiSub.notify(t('drop.wrong_type', { file: file.name, types: this.accept.split(',').filter((item) => item.trim().charAt(0) === '.').join(', ') }), 'error');
                    return;
                }
                if (files.length > 1) {
                    CertiSub.notify(t('drop.one_file', { file: file.name }), 'error');
                }
                this.$emit('file', file);
            },
            takeText(text) {
                if (this.disabled || !this.textName || !String(text || '').trim()) {
                    return false;
                }
                let content = text;
                // Sama treść wiadomości dostaje nagłówki, żeby serwer odczytał ją jak plik .eml.
                if (this.textName.endsWith('.eml') && !/^(from|subject|date|to|mime-version|content-type|received|return-path):/im.test(text.slice(0, 2000))) {
                    content = 'Subject: ' + this.textName.replace(/\.eml$/, '') + '\r\nMessage-ID: <paste-' + Date.now() + '@certisub.local>\r\n'
                        + 'MIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n' + text;
                }
                this.$emit('file', new File([content], this.textName, { type: 'text/plain' }));
                return true;
            },
            onChange(event) {
                const files = Array.from(event.target.files || []);
                event.target.value = '';
                this.take(files);
            },
            onDrop(event) {
                this.depth = 0;
                this.take(Array.from((event.dataTransfer && event.dataTransfer.files) || []));
            },
        },
        template: `
            <div role="button" :tabindex="disabled ? -1 : 0" :aria-disabled="disabled ? 'true' : 'false'"
                 :class="['drop-zone', depth > 0 ? 'drop-zone-over' : '', disabled ? 'drop-zone-disabled' : '']"
                 @click="browse" @keydown.enter.prevent="browse" @keydown.space.prevent="browse"
                 @dragenter.prevent="depth += 1" @dragover.prevent @dragleave.prevent="depth = Math.max(0, depth - 1)" @drop.prevent="onDrop"
                 @mouseenter="hovered = true" @mouseleave="hovered = false">
                <span class="text-2xl leading-none" aria-hidden="true">{{ depth > 0 ? '📥' : '📎' }}</span>
                <span class="block text-sm font-semibold text-slate-700">{{ depth > 0 ? t('drop.release') : (label || t('drop.title')) }}</span>
                <span class="block text-xs text-slate-500">{{ hint || (textName ? t('drop.hint_text') : t('drop.hint')) }}</span>
                <span v-if="fileName" class="block text-xs font-medium text-brand-700 break-all">📄 {{ fileName }}</span>
                <input ref="input" type="file" :accept="accept" class="hidden" tabindex="-1" @change="onChange" @click.stop>
            </div>
        `,
    };
})(window.CertiSub);
