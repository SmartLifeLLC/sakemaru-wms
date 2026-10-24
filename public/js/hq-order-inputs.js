(function (root) {
    function toHalfWidthDigits(input) {
        return String(input ?? '').replace(/[０-９]/g, char => String.fromCharCode(char.charCodeAt(0) - 0xFEE0));
    }

    function normalizeDateInput(input, basis = new Date()) {
        const text = toHalfWidthDigits(input).replaceAll('／', '/').replaceAll('－', '-').trim();
        if (!text) return '';
        let year = basis.getFullYear(), month = basis.getMonth() + 1, day;
        const full = text.match(/^(\d{4})[/-](\d{1,2})[/-](\d{1,2})$/);
        if (full) {
            [, year, month, day] = full.map(Number);
        } else if (/^\d{1,8}$/.test(text)) {
            if (text.length <= 2) day = Number(text);
            else if (text.length === 3 || text.length === 4) {
                month = Number(text.slice(0, -2)); day = Number(text.slice(-2));
            } else if (text.length === 6 || text.length === 8) {
                year = text.length === 6 ? 2000 + Number(text.slice(0, 2)) : Number(text.slice(0, 4));
                month = Number(text.slice(-4, -2)); day = Number(text.slice(-2));
            }
        }
        if (day === undefined) {
            throw new Error('入荷予定日に存在しない日付が入力されています。年月日を確認してください。');
        }
        return `${String(year).padStart(4, '0')}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    }

    function parseDate(input, basis = new Date()) {
        const normalized = normalizeDateInput(input, basis);
        if (!normalized) return '';
        const [year, month, day] = normalized.split('-').map(Number);
        const date = new Date(year, month - 1, day);
        if (!day || date.getFullYear() !== year || date.getMonth() + 1 !== month || date.getDate() !== day || year < 1000 || year > 9999) {
            throw new Error('入荷予定日に存在しない日付が入力されています。年月日を確認してください。');
        }
        return normalized;
    }

    function totals(lines) {
        const groups = new Map();
        let cases = 0, pieces = 0;
        for (const line of lines) {
            const c = Math.max(0, Math.trunc(Number(line.case_qty) || 0));
            const p = Math.max(0, Math.trunc(Number(line.piece_qty) || 0));
            if (!c && !p) continue;
            const key = String(line.contractor_id || '');
            if (!groups.has(key)) groups.set(key, { id: key, label: `[${line.contractor_code || '-'}]${line.contractor_name || '-'}`, cases: 0, pieces: 0 });
            const group = groups.get(key);
            group.cases += c; group.pieces += p; cases += c; pieces += p;
        }
        return { cases, pieces, groups: [...groups.values()] };
    }

    function dateInput(value, min = '', max = '') {
        return {
            value, min, max, display: '', error: '', isEditing: false, composing: false, justComposed: false, commitAfterComposition: false, lastExternal: value, lastReset: 0,
            init() {
                this.display = String(this.value || '').replaceAll('-', '/');
                this.$watch('value', value => {
                    if (value) { this.error = ''; this.isEditing = false; }
                    if (!this.error && !this.isEditing) this.display = String(value || '').replaceAll('-', '/');
                });
            },
            syncExternal(external, reset = 0) {
                if (external !== this.lastExternal || reset !== this.lastReset) {
                    this.lastExternal = external; this.lastReset = reset;
                    this.error = ''; this.isEditing = false; this.value = external;
                    this.display = String(external || '').replaceAll('-', '/');
                }
            },
            edit(event) {
                this.isEditing = true; this.error = ''; this.value = '';
                if (!this.composing && !event?.isComposing) {
                    const target = event?.target;
                    this.display = toHalfWidthDigits(target?.value ?? this.display);
                    if (target && target.value !== this.display) {
                        const { selectionStart, selectionEnd } = target;
                        target.value = this.display;
                        target.setSelectionRange?.(selectionStart, selectionEnd);
                    }
                }
                this.$dispatch('hq-date-invalid');
            },
            startComposition() {
                this.composing = true;
                this.justComposed = false;
                this.edit();
            },
            endComposition(event) {
                this.composing = false;
                this.justComposed = true;
                this.edit(event);
                setTimeout(() => { this.justComposed = false; }, 100);
                if (this.commitAfterComposition) {
                    this.commitAfterComposition = false;
                    this.commit();
                }
            },
            commitOnEnter(event) {
                event.stopPropagation();
                if (this.composing || event.isComposing || event.keyCode === 229) return false;
                event.preventDefault();
                if (this.justComposed) return false;
                return this.commit(event);
            },
            commit(event) {
                if (this.composing || event?.isComposing || event?.keyCode === 229) {
                    if (event?.type === 'blur') this.commitAfterComposition = true;
                    return false;
                }
                this.isEditing = false;
                try {
                    const normalized = normalizeDateInput(this.display);
                    this.display = normalized.replaceAll('-', '/');
                    const parsed = parseDate(normalized);
                    if (parsed && this.min && parsed < this.min) throw new Error('入荷予定日に過去日は指定できません。');
                    if (parsed && this.max && parsed > this.max) throw new Error('入荷予定日は6か月以上先にできません。');
                    this.error = ''; this.value = parsed;
                    this.$dispatch('hq-date-valid', { value: parsed });
                    return true;
                } catch (error) {
                    this.error = error.message;
                    this.value = '';
                    this.$dispatch('hq-date-invalid');
                    return false;
                }
            },
            pick(event) { this.display = event.target.value; this.commit(); },
            openPicker() {
                if (typeof this.$refs.picker.showPicker === 'function') this.$refs.picker.showPicker();
                else this.$refs.picker.click();
            },
        };
    }
    root.HqOrderInputs = { parseDate, totals, dateInput };
})(globalThis);
