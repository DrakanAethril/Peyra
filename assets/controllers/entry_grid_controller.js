import { Controller } from '@hotwired/stimulus';
import {
    FIELDS,
    Lookup,
    amount,
    balance,
    blankLine,
    cell,
    dayLabel,
    frenchDate,
    isBlank,
    nextDocument,
    parseAmount,
    parseDay,
    totals,
} from '../entry_grid/model.js';

/*
 * The keyboard entry grid of a journal and a month (design-ux.md §§ 4.4 and 5.2, design-ui.md
 * § 4.8). Everything a keystroke needs is in the page - the month's entries, the person's pending
 * work, the plan and the third parties - so that typing never waits for the server, as in Sage:
 *
 * - one cell in edit at a time, entering it selects its text; Tab and Maj Tab go through every
 *   cell, Entrée to the next cell still to fill (a carried day, document or label is skipped), and
 *   from a complete line to the next one - a new entry once the entry balances;
 * - Compte: digits filter by number, letters by auxiliary code or name, the list (8 lines) opens
 *   under the cell, Entrée takes the line lit (the first), F4 lists the whole plan by class,
 *   Alt Inser creates the account typed; an auxiliary code (« 401SCIE ») names its third party, a
 *   collective account typed by number asks for its third party in the Intitulé cell;
 * - Débit, Crédit: « 2622 » is 2 622,00, typing one side empties the other, « + » puts the amount
 *   that balances the entry, Entrée on an empty debit goes to the credit;
 * - each line left is kept on the server as pending work (« saisie en attente »), F10 saves what
 *   balances to the brouillard, Ctrl Entrée validates after the confirmation bar; Inser and
 *   Ctrl Suppr insert and delete a line, Ctrl D copies the cell above, Ctrl ↑ ↓ move by entry,
 *   Échap closes the list, then undoes the cell, then offers to drop the entry's changes;
 * - in a bank or cash journal the counterpart line is automatic, drawn read only.
 *
 * The server checks each kept entry (errors, controls of the assistance level, due dates of the
 * third party's terms) and the grid shows the answer; nothing is ever lost when a request fails -
 * the line is marked « non enregistrée » and sent again at the next keystroke.
 */
export default class extends Controller {
    static targets = [
        'table', 'scroll', 'balance', 'currentLabel', 'currentDebit', 'currentCredit', 'monthLabel', 'monthDebit', 'monthCredit',
        'checks', 'checkList', 'picker', 'pickerList', 'classes', 'create', 'createNumber', 'createLabel', 'createError',
        'treasury', 'problem', 'iconLock', 'iconDraft', 'iconAlert',
        'templates', 'templateChoose', 'templateFilter', 'templateList', 'templateAsk', 'templateName', 'templateBase', 'templateAmount', 'templateRate', 'templateError',
        'peek', 'peekBody',
    ];

    static values = {
        grid: Object,
        lookup: Object,
        editable: Boolean,
        period: String,
        revision: Number,
        token: String,
        keepUrl: String,
        saveUrl: String,
        validateUrl: String,
        accountUrl: String,
        entryUrl: String,
        templateUrl: String,
        templatesHref: String,
        peekUrl: String,
    };

    connect() {
        const grid = this.gridValue;
        this.journal = grid.journal;
        this.period = grid.period;
        this.exercise = grid.exercise;
        this.dossierDate = grid.dossierDate;
        this.counterpart = grid.counterpart;
        this.lookup = new Lookup(this.lookupValue);
        this.revision = this.revisionValue;
        this.groups = grid.groups.map((group) => this.adopt(group));
        this.pos = null;
        this.picker = null;
        this.sending = null;
        this.again = false;
        this.failed = false;
        this.confirming = null;
        this.templates = grid.templates ?? [];
        this.calling = null;

        this.head = {
            template: document.getElementById('entry-template'),
            save: document.getElementById('entry-save'),
            validate: document.getElementById('entry-validate'),
            balance: document.getElementById('entry-balance'),
            actions: document.getElementById('entry-actions'),
            confirm: document.getElementById('entry-confirm'),
            confirmText: document.getElementById('entry-confirm-text'),
            confirmOk: document.getElementById('entry-confirm-ok'),
            confirmCancel: document.getElementById('entry-confirm-cancel'),
            leave: document.getElementById('entry-leave'),
            leaveKeep: document.getElementById('entry-leave-keep'),
            leaveDrop: document.getElementById('entry-leave-drop'),
            leaveStay: document.getElementById('entry-leave-stay'),
        };
        this.status = {
            message: document.querySelector('[data-entry-message]'),
            drafts: document.querySelector('[data-entry-drafts]'),
            account: document.querySelector('[data-entry-account]'),
        };
        this.listeners = [];
        this.listen(this.head.template, 'click', () => this.openTemplates());
        this.listen(this.head.save, 'click', () => this.save());
        this.listen(this.head.validate, 'click', () => this.askValidation());
        this.listen(this.head.balance, 'click', () => this.balanceCurrent());
        this.listen(this.head.confirmOk, 'click', () => this.validate());
        this.listen(this.head.confirmCancel, 'click', () => this.closeConfirm());
        this.listen(this.head.confirm, 'keydown', (event) => this.confirmKeydown(event));
        this.listen(this.head.leave, 'keydown', (event) => this.leaveKeydown(event));
        this.listen(this.head.leaveKeep, 'click', () => this.leave('keep'));
        this.listen(this.head.leaveDrop, 'click', () => this.leave('drop'));
        this.listen(this.head.leaveStay, 'click', () => this.leave('stay'));
        this.listen(document, 'turbo:before-visit', (event) => this.beforeVisit(event));
        this.listen(window, 'beforeunload', (event) => this.beforeUnload(event));
        this.listen(document, 'pointerdown', (event) => {
            if (this.picker && !this.pickerTarget.contains(event.target) && event.target !== this.inputAt()) {
                this.closePicker();
            }
            if (this.calling && !this.templatesTarget.contains(event.target) && event.target !== this.head.template) {
                this.closeTemplates(false);
            }
        });

        if (this.editableValue) {
            this.ensureTail();
        }
        this.render();
        this.place(grid.focus);
    }

    disconnect() {
        for (const [target, type, handler] of this.listeners) {
            target?.removeEventListener(type, handler);
        }
        clearTimeout(this.keepTimer);
    }

    listen(target, type, handler) {
        if (!target) {
            return;
        }
        target.addEventListener(type, handler);
        this.listeners.push([target, type, handler]);
    }

    /* ------------------------------------------------------------------ Model */

    adopt(group) {
        return {
            ...group,
            lines: group.lines.map((line) => ({ ...line })),
            original: group.original ?? [],
            errors: Array.isArray(group.errors) ? {} : (group.errors ?? {}),
            controls: group.controls ?? [],
            dues: group.dues ?? [],
            local: {},
            dirty: false,
            tried: false,
        };
    }

    editable(group) {
        return this.editableValue && group.state !== 'validated';
    }

    newGroup(after) {
        const previous = after ?? [...this.groups].reverse().find((group) => group.lines.length > 0);
        let date = previous?.date ?? null;
        if (!date) {
            date = this.dossierDate >= this.period.start && this.dossierDate <= this.period.end ? this.dossierDate : this.period.start;
        }

        return this.adopt({
            key: `n${Date.now().toString(36)}${Math.random().toString(36).slice(2, 7)}`,
            entry: null,
            state: 'new',
            number: null,
            modified: true,
            date,
            document: previous ? nextDocument(previous.document) : '',
            lines: [blankLine()],
            original: [],
        });
    }

    /** The grid always ends on an empty line: of the last entry while it does not balance, else of a new one. */
    ensureTail() {
        const last = this.groups.at(-1);
        if (last && this.editable(last) && last.lines.length > 0 && !this.isBalanced(last)) {
            if (!isBlank(last.lines.at(-1))) {
                last.lines.push(blankLine(last.lines.at(-1).label));
            }

            return;
        }
        if (last && this.editable(last) && last.state === 'new' && last.lines.length === 1 && isBlank(last.lines[0])) {
            return;
        }
        this.groups.push(this.newGroup());
    }

    /** The lines that count (blank ones apart), with their index in the group. */
    typed(group) {
        return group.lines.map((line, index) => ({ line, index })).filter(({ line }) => !isBlank(line));
    }

    /** The automatic line of a bank or cash journal: what balances the lines typed. */
    counterpartOf(lines) {
        if (!this.counterpart) {
            return null;
        }
        const { debit, credit } = totals(lines);
        const gap = debit - credit;
        if (gap === 0) {
            return null;
        }

        return { account: this.counterpart.number, debit: gap < 0 ? -gap : 0, credit: gap > 0 ? gap : 0 };
    }

    groupTotals(group) {
        const all = totals(group.lines);
        const automatic = this.counterpartOf(group.lines);
        if (automatic) {
            all.debit += automatic.debit;
            all.credit += automatic.credit;
        }

        return all;
    }

    isBalanced(group) {
        const typed = this.typed(group).map(({ line }) => line);
        if (typed.length === 0) {
            return false;
        }
        const { debit, credit } = this.groupTotals(group);

        return debit === credit && debit > 0 && typed.every((line) => line.account && (line.debit || line.credit));
    }

    /** What the Compte cell resolves to, and what the line's account is. */
    resolved(line) {
        const found = this.lookup.resolve(line.account ?? '');
        if (!found) {
            return null;
        }
        if (!found.auxiliary && found.account.c && line.thirdParty) {
            return { ...found, thirdParty: this.lookup.thirdParty(line.thirdParty.toUpperCase()) };
        }

        return found;
    }

    fieldEditable(group, index, field) {
        if (!this.editable(group)) {
            return false;
        }
        const line = group.lines[index];
        if (field === 'day' || field === 'document') {
            return index === 0;
        }
        if (field === 'thirdParty') {
            const found = this.resolved(line);

            return Boolean(found && !found.auxiliary && found.account.c);
        }
        if (field === 'due') {
            const found = this.resolved(line);

            return Boolean(found && found.account.t);
        }

        return true;
    }

    /* ----------------------------------------------------------------- Drawing */

    render() {
        this.rendering = true;
        try {
            this.draw();
        } finally {
            this.rendering = false;
        }
        this.refreshTotals();
    }

    draw() {
        const table = this.tableTarget;
        for (const body of [...table.tBodies]) {
            body.remove();
        }
        const foot = table.tFoot;
        for (const group of this.groups) {
            table.insertBefore(this.groupBody(group), foot);
        }
        if (this.groups.length === 0) {
            const body = document.createElement('tbody');
            const row = body.insertRow();
            const td = row.insertCell();
            td.colSpan = 9;
            td.className = 'px-entry__empty';
            td.textContent = 'Aucune écriture dans ce journal pour ce mois.';
            table.insertBefore(body, foot);
        }
    }

    renderGroup(group) {
        const old = this.tableTarget.querySelector(`tbody[data-key="${CSS.escape(group.key)}"]`);
        if (old) {
            this.rendering = true;
            try {
                old.replaceWith(this.groupBody(group));
            } finally {
                this.rendering = false;
            }
        }
        this.refreshTotals();
    }

    groupBody(group) {
        const body = document.createElement('tbody');
        body.className = 'px-entry__group';
        body.dataset.key = group.key;
        const state = this.groupState(group);
        body.classList.add(`is-${state}`);
        const editable = this.editable(group);
        if (group.lines.length === 0) {
            const row = body.insertRow();
            row.className = 'px-entry__line is-dim';
            row.append(this.markCell(group, state, 1));
            const td = row.insertCell();
            td.colSpan = 8;
            td.className = 'px-entry__note';
            td.textContent = `Écriture ${group.document} supprimée : F10 l’efface du brouillard, Échap la rétablit.`;
            td.tabIndex = -1;
            td.dataset.field = 'deleted';
            row.dataset.line = '0';

            return body;
        }
        const automatic = this.counterpartOf(group.lines);
        const rows = group.lines.length + (automatic ? 1 : 0);
        group.lines.forEach((line, index) => {
            const row = body.insertRow();
            row.className = 'px-entry__line';
            row.dataset.line = String(index);
            if (index === 0) {
                row.append(this.markCell(group, state, rows));
            }
            for (const field of FIELDS) {
                row.append(this.fieldCell(group, index, field, editable));
            }
            const messages = this.lineMessages(group, index);
            if (messages.length > 0) {
                const error = body.insertRow();
                error.className = 'px-entry__lineerror';
                const td = error.insertCell();
                td.colSpan = 8;
                td.textContent = messages.join(' ');
                td.id = `entry-error-${group.key}-${index}`;
                row.querySelectorAll('input[aria-invalid="true"]').forEach((input) => input.setAttribute('aria-describedby', td.id));
            }
        });
        if (automatic) {
            const row = body.insertRow();
            row.className = 'px-entry__line is-automatic';
            const account = this.lookup.account(automatic.account);
            const label = group.lines.find((line) => line.label)?.label ?? '';
            const cells = ['', '', automatic.account, account?.l ?? '', label, cell(automatic.debit), cell(automatic.credit), ''];
            cells.forEach((text, i) => {
                const td = row.insertCell();
                td.textContent = text;
                if (i === 2) {
                    td.className = 'px-u-code';
                }
                if (i === 5 || i === 6) {
                    td.className = 'px-u-num';
                }
                if (i === 3) {
                    td.title = 'Contrepartie automatique du journal';
                }
            });
        }
        const mark = body.querySelector('.px-entry__mark');
        if (mark) {
            mark.rowSpan = body.rows.length;
        }

        return body;
    }

    /** draft, validated, new, modified, incomplete (pending and not the current entry), error. */
    groupState(group) {
        if (group.state === 'validated') {
            return 'validated';
        }
        const hasError = Object.keys(group.errors).length > 0 || Object.keys(group.local).length > 0;
        const current = this.pos?.key === group.key;
        if (group.lines.length > 0 && this.typed(group).length > 0 && !this.isBalanced(group) && !current) {
            return 'incomplete';
        }
        if (hasError && (!current || group.tried)) {
            return 'error';
        }
        if (group.modified || group.dirty) {
            return group.state === 'new' ? 'new' : 'modified';
        }

        return 'draft';
    }

    markCell(group, state, rows) {
        const td = document.createElement('td');
        td.rowSpan = rows;
        td.className = 'px-entry__mark';
        const words = {
            validated: group.number ? `${group.number}, validée${group.reversed ? ` · ${group.reversed}` : ''}` : 'Validée',
            draft: 'Au brouillard',
            modified: 'Modifiée, pas encore enregistrée au brouillard (F10)',
            new: 'Nouvelle, pas encore enregistrée au brouillard (F10)',
            incomplete: 'Brouillon incomplet : elle ne s’équilibre pas, elle reste en attente',
            error: 'À corriger avant le brouillard',
        };
        const icon = { validated: this.iconLockTarget, draft: this.iconDraftTarget, incomplete: this.iconAlertTarget, error: this.iconAlertTarget }[state];
        const holder = group.entry && group.state !== 'new'
            ? Object.assign(document.createElement('a'), { href: this.entryUrlValue.replace('__ID__', String(group.entry)) })
            : document.createElement('span');
        holder.className = 'px-entry__markin';
        holder.title = words[state];
        holder.tabIndex = -1;
        if (icon) {
            holder.append(icon.content.cloneNode(true));
        } else {
            holder.append(Object.assign(document.createElement('i'), { className: 'px-entry__dot' }));
        }
        holder.append(Object.assign(document.createElement('span'), { className: 'px-u-sr-only', textContent: words[state] }));
        td.append(holder);

        return td;
    }

    fieldCell(group, index, field, editable) {
        const line = group.lines[index];
        const td = document.createElement('td');
        td.dataset.field = field;
        const numeric = field === 'debit' || field === 'credit';
        if (numeric) {
            td.className = 'px-u-num';
        }
        if (field === 'account' || field === 'document') {
            td.classList.add('px-u-code');
        }
        const value = this.display(group, index, field);
        if (editable && this.fieldEditable(group, index, field)) {
            const input = document.createElement('input');
            input.className = 'px-entry__cell';
            input.type = 'text';
            input.value = value;
            input.tabIndex = -1;
            input.autocomplete = 'off';
            input.spellcheck = false;
            input.dataset.field = field;
            input.setAttribute('aria-label', `${LABELS[field]}, ligne ${this.lineNumber(group, index)}`);
            if (field === 'account' || field === 'thirdParty') {
                input.setAttribute('role', 'combobox');
                input.setAttribute('aria-autocomplete', 'list');
                input.setAttribute('aria-expanded', 'false');
                input.setAttribute('aria-controls', 'entry-picker-list');
                input.maxLength = 17;
            }
            if (numeric) {
                input.inputMode = 'decimal';
                input.classList.add('px-u-num');
            }
            if (field === 'day' || field === 'due') {
                input.inputMode = 'numeric';
                input.maxLength = 10;
            }
            if (field === 'document') {
                input.maxLength = 40;
            }
            if (field === 'label') {
                input.maxLength = 200;
            }
            if (field === 'due' && !line.due && this.dueHint(group, index)) {
                input.placeholder = frenchDate(this.dueHint(group, index));
            }
            if (this.fieldError(group, index, field)) {
                input.setAttribute('aria-invalid', 'true');
            }
            td.append(input);

            return td;
        }
        if ((field === 'day' || field === 'document') && index > 0) {
            td.classList.add('is-carried');
        }
        td.textContent = value;
        if (field === 'thirdParty' || field === 'label') {
            td.title = value;
        }

        return td;
    }

    /** What a cell shows. */
    display(group, index, field) {
        const line = group.lines[index];
        switch (field) {
            case 'day':
                return dayLabel(group.date, this.period.start);
            case 'document':
                return group.document ?? '';
            case 'account':
                return line.account ?? '';
            case 'thirdParty': {
                const found = this.resolved(line);
                if (found && !found.auxiliary && found.account.c) {
                    return line.thirdParty ?? '';
                }
                if (!found) {
                    return '';
                }
                const due = line.due ?? this.dueHint(group, index);

                return found.thirdParty ? `${found.thirdParty.n}${due ? ` · échéance ${frenchDate(due)}` : ''}` : found.account.l;
            }
            case 'label':
                return line.label ?? '';
            case 'debit':
                return cell(line.debit);
            case 'credit':
                return cell(line.credit);
            case 'due':
                return frenchDate(line.due);
            default:
                return '';
        }
    }

    dueHint(group, index) {
        const typedIndex = this.typed(group).findIndex((entry) => entry.index === index);

        return group.dues.find((due) => due.index === typedIndex)?.date ?? null;
    }

    lineNumber(group, index) {
        let count = 0;
        for (const other of this.groups) {
            if (other === group) {
                return count + index + 1;
            }
            count += other.lines.length;
        }

        return index + 1;
    }

    /** The server's and the grid's faults for a cell. */
    fieldError(group, index, field) {
        const local = group.local[`${index}.${field}`];
        if (local) {
            return local;
        }
        if (this.pos?.key === group.key && !group.tried) {
            return null;
        }
        const typedIndex = this.typed(group).findIndex((entry) => entry.index === index);
        const keys = {
            day: index === 0 ? ['date'] : [],
            document: index === 0 ? ['document'] : [],
            label: index === 0 ? ['label'] : [],
            account: [`line.${typedIndex}.account`],
            thirdParty: [`line.${typedIndex}.thirdParty`],
            debit: group.lines[index].credit ? [] : [`line.${typedIndex}.amount`],
            credit: group.lines[index].credit ? [`line.${typedIndex}.amount`] : [],
            due: [`line.${typedIndex}.dueDate`],
        }[field];
        if (field === 'account' && !this.fieldEditable(group, index, 'thirdParty') && group.errors[`line.${typedIndex}.thirdParty`]) {
            return group.errors[`line.${typedIndex}.thirdParty`];
        }
        if (typedIndex < 0 && !['day', 'document', 'label'].includes(field)) {
            return null;
        }
        for (const key of keys) {
            if (group.errors[key]) {
                return group.errors[key];
            }
        }

        return null;
    }

    lineMessages(group, index) {
        const messages = [];
        for (const field of FIELDS) {
            const message = this.fieldError(group, index, field);
            if (message && !messages.includes(message)) {
                messages.push(message);
            }
        }

        return messages;
    }

    refreshTotals() {
        const group = this.currentGroup() ?? this.groups.findLast((one) => this.editable(one)) ?? this.groups.at(-1);
        if (group) {
            const { debit, credit } = this.groupTotals(group);
            this.currentDebitTarget.textContent = debit || credit ? amount(debit) : '';
            this.currentCreditTarget.textContent = debit || credit ? amount(credit) : '';
            this.currentDebitTarget.classList.toggle('is-short', debit < credit);
            this.currentCreditTarget.classList.toggle('is-short', credit < debit);
            this.currentLabelTarget.textContent = group.number ? `Écriture ${group.number}` : (group.document ? `Écriture ${group.document}` : 'Écriture en cours');
            this.balanceTarget.replaceChildren(this.balanceBadge(debit, credit));
            this.head.balance?.toggleAttribute('disabled', !this.editable(group) || debit === credit || Boolean(this.counterpart));
            this.head.balance?.classList.toggle('is-wanted', this.editable(group) && debit !== credit);
        }
        let monthDebit = 0;
        let monthCredit = 0;
        let count = 0;
        for (const one of this.groups) {
            const { debit, credit } = this.groupTotals(one);
            monthDebit += debit;
            monthCredit += credit;
            count += debit || credit ? 1 : 0;
        }
        this.monthDebitTarget.textContent = amount(monthDebit);
        this.monthCreditTarget.textContent = amount(monthCredit);
        this.monthLabelTarget.textContent = `Total ${this.journal.code} · ${this.period.label.toLowerCase()} · ${count} écriture${count > 1 ? 's' : ''}`;
        if (this.hasTreasuryTarget && this.counterpart) {
            this.treasuryTarget.textContent = balance(this.counterpart.balance + this.treasuryDelta());
        }
        this.refreshChecks();
        this.refreshAccount();
    }

    balanceBadge(debit, credit) {
        const badge = document.createElement('span');
        if (debit === 0 && credit === 0) {
            return badge;
        }
        if (debit === credit) {
            badge.className = 'px-badge px-badge--success';
            badge.textContent = 'Équilibrée';
        } else {
            badge.className = 'px-badge px-badge--danger';
            badge.textContent = `Écart ${amount(Math.abs(debit - credit))} au ${debit < credit ? 'débit' : 'crédit'}`;
        }

        return badge;
    }

    /** What the entries not yet saved move on the journal's bank or cash account. */
    treasuryDelta() {
        let delta = 0;
        for (const group of this.groups) {
            if (!group.modified && !group.dirty) {
                continue;
            }
            const now = this.counterpartOf(group.lines);
            const before = group.state === 'new' ? null : this.counterpartOf(group.original);
            delta += (now ? now.debit - now.credit : 0) - (before ? before.debit - before.credit : 0);
        }

        return delta;
    }

    refreshChecks() {
        const group = this.currentGroup();
        const items = [];
        if (group && this.editable(group)) {
            const seen = new Set();
            const errors = Object.entries(group.errors).filter(([key]) => group.tried || !(key === 'lines' || key.startsWith('line.')));
            for (const message of [...Object.values(group.local), ...errors.map(([, text]) => text)]) {
                if (!seen.has(message)) {
                    seen.add(message);
                    items.push(['blocking', message]);
                }
            }
            for (const control of group.controls) {
                if (!seen.has(control.message)) {
                    seen.add(control.message);
                    items.push([control.blocking ? 'blocking' : 'warning', control.message]);
                }
            }
            if (this.isBalanced(group) && items.length === 0) {
                items.push(['passed', 'Écriture équilibrée']);
            }
        }
        this.checksTarget.hidden = items.length === 0;
        const marks = { passed: '✓', warning: '!', blocking: '×' };
        const words = { passed: 'Contrôle passé : ', warning: 'À vérifier : ', blocking: 'Bloquant : ' };
        this.checkListTarget.replaceChildren(...items.map(([state, message]) => {
            const li = document.createElement('li');
            li.className = `px-checks__item is-${state}`;
            const mark = Object.assign(document.createElement('span'), { className: 'px-checks__mark', textContent: marks[state] });
            mark.setAttribute('aria-hidden', 'true');
            const text = document.createElement('span');
            text.append(Object.assign(document.createElement('span'), { className: 'px-u-sr-only', textContent: words[state] }), message);
            li.append(mark, text);

            return li;
        }));
    }

    /** The status bar: the balance of the current line's account after the entries typed. */
    refreshAccount() {
        if (!this.status.account) {
            return;
        }
        const group = this.currentGroup();
        const line = group?.lines[this.pos?.line];
        const found = line ? this.resolved(line) : null;
        if (!found) {
            this.status.account.textContent = '';

            return;
        }
        const party = found.auxiliary ? found.thirdParty : null;
        const base = party ? party.b : found.account.b;
        let delta = 0;
        for (const one of this.groups) {
            if (!one.modified && !one.dirty) {
                continue;
            }
            delta += this.sumOn(one.lines, found.account.n, party) - (one.state === 'new' ? 0 : this.sumOn(one.original, found.account.n, party));
        }
        this.status.account.replaceChildren(`Solde ${party ? party.a : found.account.n} · `, Object.assign(document.createElement('b'), { textContent: balance(base + delta) }));
    }

    /** What the lines move on an account, or on a third party, the automatic counterpart included. */
    sumOn(lines, number, party) {
        let sum = 0;
        for (const line of lines) {
            const found = this.resolved(line);
            if (found && (party ? found.thirdParty === party : found.account.n === number)) {
                sum += (line.debit || 0) - (line.credit || 0);
            }
        }
        const automatic = party ? null : this.counterpartOf(lines);
        if (automatic && automatic.account === number) {
            sum += automatic.debit - automatic.credit;
        }

        return sum;
    }

    /* --------------------------------------------------------------- Focus */

    currentGroup() {
        return this.pos ? this.groups.find((group) => group.key === this.pos.key) ?? null : null;
    }

    /** Puts the focus in a cell (the group is drawn if needed); false when it is not editable. */
    focusCell(key, line, field) {
        const input = this.inputAt({ key, line, field });
        if (!input) {
            return false;
        }
        for (const other of this.tableTarget.querySelectorAll('input[tabindex="0"]')) {
            other.tabIndex = -1;
        }
        input.tabIndex = 0;
        input.focus();
        input.select();

        return true;
    }

    /** The first focus: the entry asked (`?entry=`, -1 for the last one), else the empty line at the end. */
    place(focus) {
        if (!this.editableValue) {
            const first = this.tableTarget.querySelector('.px-entry__markin');
            if (first) {
                first.tabIndex = 0;
            }

            return;
        }
        let group = null;
        if (focus === -1) {
            group = this.groups.findLast((one) => one.state === 'new' && this.typed(one).length > 0) ?? null;
        } else if (focus) {
            group = this.groups.find((one) => one.entry === focus) ?? null;
        }
        if (group && this.editable(group) && group.lines.length === 0) {
            this.tableTarget.querySelector(`tbody[data-key="${CSS.escape(group.key)}"] [data-field="deleted"]`)?.focus();

            return;
        }
        if (group && this.editable(group)) {
            this.focusCell(group.key, 0, 'account');
            this.inputAt()?.closest('tr')?.scrollIntoView({ block: 'center' });

            return;
        }
        const tail = this.groups.at(-1);
        if (tail) {
            const line = tail.lines.length - 1;
            this.focusCell(tail.key, line, line === 0 && tail.state === 'new' ? 'day' : 'account');
            this.scrollTarget.scrollTop = this.scrollTarget.scrollHeight;
        }
    }

    focusin(event) {
        const input = event.target;
        if (!(input instanceof HTMLInputElement) || !input.classList.contains('px-entry__cell')) {
            return;
        }
        const body = input.closest('tbody');
        const row = input.closest('tr');
        const next = { key: body.dataset.key, line: Number(row.dataset.line), field: input.dataset.field };
        const previous = this.pos;
        this.pos = next;
        this.entered = input.value;
        for (const other of this.tableTarget.querySelectorAll('input[tabindex="0"]')) {
            if (other !== input) {
                other.tabIndex = -1;
            }
        }
        input.tabIndex = 0;
        this.tableTarget.querySelectorAll('tr.is-current').forEach((tr) => tr.classList.remove('is-current'));
        row.classList.add('is-current');
        input.select();
        if (previous && (previous.key !== next.key || previous.line !== next.line)) {
            this.lineLeft(previous, next);
        }
        this.refreshTotals();
    }

    focusout(event) {
        const input = event.target;
        if (this.rendering || !input.isConnected || !(input instanceof HTMLInputElement) || !input.classList.contains('px-entry__cell')) {
            return;
        }
        if (!this.tableTarget.contains(event.relatedTarget) && !this.pickerTarget.contains(event.relatedTarget) && !this.createTarget.contains(event.relatedTarget) && !this.templatesTarget.contains(event.relatedTarget) && !(this.hasPeekTarget && this.peekTarget.contains(event.relatedTarget))) {
            // Leaving the grid (a click outside, Ctrl F6): the cell is committed, the line left.
            if (this.commit(input, false)) {
                const left = this.pos;
                this.closePicker();
                this.lineLeft(left, null);
            }
        }
    }

    /* ------------------------------------------------------------ Typing */

    inputAt(pos = this.pos) {
        if (!pos) {
            return null;
        }
        const body = this.tableTarget.querySelector(`tbody[data-key="${CSS.escape(pos.key)}"]`);

        return body?.querySelector(`tr[data-line="${pos.line}"] input[data-field="${pos.field}"]`) ?? null;
    }

    typing(event) {
        const input = event.target;
        if (!(input instanceof HTMLInputElement) || !this.pos) {
            return;
        }
        const group = this.currentGroup();
        if (group) {
            group.dirty = true;
            delete group.local[`${this.pos.line}.${this.pos.field}`];
            input.removeAttribute('aria-invalid');
        }
        if (this.failed) {
            this.scheduleKeep(0);
        }
        if (this.pos.field === 'account' || this.pos.field === 'thirdParty') {
            this.openPicker(input.value, false);
        }
        if ((this.pos.field === 'debit' || this.pos.field === 'credit') && input.value.trim() === '+') {
            // « + » arrived as text (a keyboard layout, a paste): the same gesture as the key.
            input.value = '';
            this.balanceCurrent();

            return;
        }
        if ((this.pos.field === 'debit' || this.pos.field === 'credit') && input.value.trim() !== '') {
            // Typing one side empties the other (design-ux.md § 5.2).
            const other = this.inputAt({ ...this.pos, field: this.pos.field === 'debit' ? 'credit' : 'debit' });
            if (other && other.value !== '') {
                other.value = '';
                group.lines[this.pos.line][this.pos.field === 'debit' ? 'credit' : 'debit'] = 0;
            }
        }
    }

    /**
     * The cell's text read into the model (Entrée, Tab, leaving): false when it cannot be read - the
     * cell then stays in edit with its message (design-ux.md § 4.4).
     */
    commit(input = this.inputAt(), takePicked = true) {
        if (!input || !this.pos) {
            return true;
        }
        const group = this.currentGroup();
        if (!group) {
            return true;
        }
        const index = this.pos.line;
        const line = group.lines[index];
        const field = this.pos.field;
        const text = input.value;
        const key = `${index}.${field}`;
        delete group.local[key];
        let changed = false;
        switch (field) {
            case 'day': {
                if (text.trim() === '') {
                    group.local[key] = 'Indiquez le jour de l’écriture.';
                    break;
                }
                const day = parseDay(text, this.period.start, this.exercise);
                if (day === null) {
                    group.local[key] = 'Ce jour n’existe pas : tapez 12, 1211 ou 12/11/2026.';
                    break;
                }
                changed = day !== group.date;
                group.date = day;
                input.value = dayLabel(day, this.period.start);
                break;
            }
            case 'document':
                changed = text.trim() !== (group.document ?? '');
                group.document = text.trim();
                break;
            case 'account': {
                let typed = text.replace(/\s+/g, '').toUpperCase();
                if (typed !== '' && !this.lookup.resolve(typed)) {
                    const picked = takePicked ? this.pickedValue() : null;
                    if (picked) {
                        typed = picked;
                    } else {
                        group.local[key] = /^\d+$/.test(typed)
                            ? `Le compte ${typed} n’existe pas. F4 pour chercher, Alt Inser pour le créer.`
                            : `Ni compte ni tiers ne répond à ${typed}. F4 pour chercher.`;
                    }
                }
                changed = typed !== (line.account ?? '');
                line.account = typed;
                input.value = typed;
                if (changed) {
                    const found = this.lookup.resolve(typed);
                    if (!found || found.auxiliary || !found.account.c) {
                        line.thirdParty = '';
                    }
                    if (!found || !found.account.t) {
                        line.due = null;
                    }
                }
                break;
            }
            case 'thirdParty': {
                let typed = text.replace(/\s+/g, '').toUpperCase();
                if (typed !== '' && !this.lookup.thirdParty(typed)) {
                    const picked = takePicked ? this.pickedValue() : null;
                    if (picked) {
                        typed = picked;
                    } else {
                        group.local[key] = `Le tiers ${typed} n’existe pas dans le dossier. F4 pour chercher.`;
                    }
                }
                changed = typed !== (line.thirdParty ?? '');
                line.thirdParty = typed;
                input.value = typed;
                break;
            }
            case 'label':
                changed = text !== (line.label ?? '');
                line.label = text;
                break;
            case 'debit':
            case 'credit': {
                const cents = parseAmount(text);
                if (cents === null || cents < 0) {
                    group.local[key] = cents === null ? 'Ce montant n’est pas lisible. Écrivez par exemple 1 234,56.' : 'Un montant est positif : portez-le dans l’autre colonne.';
                    break;
                }
                changed = cents !== (line[field] || 0);
                line[field] = cents;
                if (cents > 0) {
                    line[field === 'debit' ? 'credit' : 'debit'] = 0;
                }
                input.value = cell(cents);
                break;
            }
            case 'due': {
                if (text.trim() === '') {
                    changed = line.due !== null;
                    line.due = null;
                    break;
                }
                const day = parseDay(text, this.period.start, this.exercise);
                if (day === null) {
                    group.local[key] = 'La date doit s’écrire jj/mm/aaaa, par exemple 12/02/2026.';
                    break;
                }
                changed = day !== line.due;
                line.due = day;
                input.value = frenchDate(day);
                break;
            }
            default:
                break;
        }
        if (changed) {
            group.dirty = true;
            group.modified = true;
        }
        const ok = !group.local[key];
        if (!ok) {
            input.setAttribute('aria-invalid', 'true');
            this.closePicker();
            this.renderGroup(group);
            this.focusCell(group.key, index, field);
            this.inputAt()?.setSelectionRange(text.length, text.length);
        } else if (changed && ['account', 'thirdParty', 'debit', 'credit', 'day'].includes(field)) {
            this.renderGroup(group);
        } else {
            this.refreshTotals();
        }
        this.entered = this.inputAt()?.value ?? '';

        return ok;
    }

    /* ----------------------------------------------------------- Keyboard */

    keydown(event) {
        const input = event.target;
        if (!(input instanceof HTMLInputElement) || !input.classList.contains('px-entry__cell') || !this.pos) {
            if (event.key === 'Escape' && this.abandonRow) {
                event.preventDefault();
                event.stopPropagation();
                this.closeAbandon(true);
            } else if (event.key === 'Escape' && input instanceof HTMLElement && input.dataset.field === 'deleted') {
                // A draft whose lines were all deleted: Échap gives them back.
                event.preventDefault();
                event.stopPropagation();
                const group = this.groups.find((one) => one.key === input.closest('tbody')?.dataset.key);
                if (group) {
                    this.abandon(group);
                }
            }

            return;
        }
        const key = event.key;
        const field = this.pos.field;
        const ctrl = event.ctrlKey || event.metaKey;

        if (this.picker) {
            if (key === 'ArrowDown' || key === 'ArrowUp') {
                event.preventDefault();
                this.movePicker(key === 'ArrowDown' ? 1 : -1);

                return;
            }
            if (this.picker.full && (key === 'ArrowLeft' || key === 'ArrowRight') && !ctrl) {
                event.preventDefault();
                this.moveClass(key === 'ArrowRight' ? 1 : -1);

                return;
            }
            if (key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                this.closePicker();

                return;
            }
        }

        if (key === 'F4' && (field === 'account' || field === 'thirdParty')) {
            event.preventDefault();
            this.openPicker(input.value, true);

            return;
        }
        if (key === 'Insert' && event.altKey) {
            event.preventDefault();
            this.openCreate();

            return;
        }
        if (key === 'F5') {
            event.preventDefault();
            this.peek();

            return;
        }
        if (key === 'Enter' && !ctrl && !event.altKey) {
            event.preventDefault();
            event.stopPropagation();
            this.enter(event.shiftKey);

            return;
        }
        if (key === 'Tab') {
            event.preventDefault();
            this.tab(event.shiftKey ? -1 : 1);

            return;
        }
        if (key === 'F6' && ctrl) {
            event.preventDefault();
            if (this.commit()) {
                this.leaveGrid();
            }

            return;
        }
        if ((key === '+' || (event.code === 'NumpadAdd' && !ctrl)) && (field === 'debit' || field === 'credit')) {
            event.preventDefault();
            event.stopPropagation();
            this.balanceCurrent();

            return;
        }
        if (key === '+') {
            // A plus sign typed in a text cell stays a character: the Équilibrer button only answers in amounts.
            event.stopPropagation();

            return;
        }
        if (key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            this.escape(input);

            return;
        }
        if (ctrl && (key === 'd' || key === 'D')) {
            event.preventDefault();
            this.copyAbove();

            return;
        }
        if (key === 'Insert' && !ctrl) {
            event.preventDefault();
            this.insertLine();

            return;
        }
        if (ctrl && key === 'Delete') {
            event.preventDefault();
            this.deleteLine();

            return;
        }
        if (ctrl && (key === 'ArrowUp' || key === 'ArrowDown')) {
            event.preventDefault();
            this.jumpEntry(key === 'ArrowDown' ? 1 : -1);

            return;
        }
        if (!ctrl && !event.altKey && (key === 'ArrowUp' || key === 'ArrowDown') && !this.picker) {
            event.preventDefault();
            this.vertical(key === 'ArrowDown' ? 1 : -1);
        }
    }

    /**
     * Entrée (design-ux.md A5): the cell read, then the next cell still to fill on the line; a
     * complete line goes on to the next line - of the same entry while it does not balance, else of
     * a new entry.
     */
    enter(backwards = false) {
        if (backwards) {
            this.tab(-1);

            return;
        }
        if (!this.commit()) {
            return;
        }
        this.closePicker();
        const group = this.currentGroup();
        const index = this.pos.line;
        const line = group.lines[index];
        const from = FIELDS.indexOf(this.pos.field);
        for (const field of FIELDS.slice(from + 1)) {
            if (this.toFill(group, index, field, this.pos.field)) {
                this.focusCell(group.key, index, field);

                return;
            }
        }
        if (!line.account || (!line.debit && !line.credit)) {
            // The line lacks its account or its amount: the cell that says so.
            const missing = !line.account ? 'account' : (this.pos.field === 'credit' ? 'credit' : 'debit');
            if (missing !== this.pos.field || isBlank(line)) {
                if (isBlank(line) && index === group.lines.length - 1 && this.isBalanced(group)) {
                    this.nextEntry(group);

                    return;
                }
                this.focusCell(group.key, index, missing);
            }
            this.say(!line.account ? 'Indiquez le compte.' : 'Indiquez un montant au débit ou au crédit.');

            return;
        }
        this.nextLine(group, index);
    }

    /** Whether Entrée stops on this cell (it is to fill), coming from `after`. */
    toFill(group, index, field, after) {
        if (!this.fieldEditable(group, index, field)) {
            return false;
        }
        const line = group.lines[index];
        switch (field) {
            case 'day':
            case 'document':
                // A new entry's first line visits its day and document (design-ux.md § 4.4).
                return index === 0 && group.state === 'new' && !line.account && (field === 'document' || !group.date);
            case 'account':
                return !line.account;
            case 'thirdParty':
                return !line.thirdParty;
            case 'label':
                return !(line.label ?? '').trim();
            case 'debit':
                return !line.debit && !line.credit;
            case 'credit':
                return after === 'debit' && !line.debit && !line.credit;
            default:
                return false;
        }
    }

    nextLine(group, index) {
        if (index < group.lines.length - 1) {
            const next = group.lines[index + 1];
            this.lineFocusStart(group, index + 1, next);

            return;
        }
        if (this.isBalanced(group)) {
            this.nextEntry(group);

            return;
        }
        group.lines.push(blankLine(group.lines[index].label));
        this.renderGroup(group);
        this.focusCell(group.key, index + 1, 'account');
    }

    lineFocusStart(group, index, line) {
        for (const field of ['account', 'thirdParty', 'label', 'debit']) {
            if (this.toFill(group, index, field, null)) {
                this.focusCell(group.key, index, field);

                return;
            }
        }
        this.focusCell(group.key, index, line.account ? 'debit' : 'account');
    }

    /** The first line of the entry after this one; a new entry when this one is the last. */
    nextEntry(group) {
        // An empty line left at the end of a balanced entry is not one of its lines.
        while (group.lines.length > 1 && isBlank(group.lines.at(-1))) {
            group.lines.pop();
        }
        const at = this.groups.indexOf(group);
        let next = this.groups[at + 1];
        if (!next) {
            next = this.newGroup(group);
            this.groups.push(next);
        } else if (next.state === 'new' && next.lines.length === 1 && isBlank(next.lines[0]) && at + 1 === this.groups.length - 1) {
            next.date = group.date;
            next.document = nextDocument(group.document);
        }
        this.renderGroup(group);
        if (!this.tableTarget.querySelector(`tbody[data-key="${CSS.escape(next.key)}"]`)) {
            this.tableTarget.insertBefore(this.groupBody(next), this.tableTarget.tFoot);
        } else {
            this.renderGroup(next);
        }
        if (this.editable(next)) {
            this.focusCell(next.key, 0, next.state === 'new' && !next.lines[0].account ? 'day' : 'account');
        } else {
            this.jumpEntry(1, next);
        }
    }

    tab(step) {
        if (!this.commit()) {
            return;
        }
        this.closePicker();
        const inputs = [...this.tableTarget.querySelectorAll('input.px-entry__cell')];
        const at = inputs.indexOf(this.inputAt());
        const target = inputs[at + step];
        if (target) {
            target.focus();
        } else if (step > 0) {
            const group = this.currentGroup();
            this.nextLine(group, this.pos.line);
        }
    }

    vertical(step) {
        if (!this.commit()) {
            return;
        }
        const field = this.pos.field;
        const rows = [...this.tableTarget.querySelectorAll('tr.px-entry__line')];
        let at = rows.indexOf(this.inputAt().closest('tr')) + step;
        while (rows[at]) {
            const input = rows[at].querySelector(`input[data-field="${field}"]`) ?? rows[at].querySelector('input[data-field="account"]');
            if (input) {
                input.focus();

                return;
            }
            at += step;
        }
    }

    jumpEntry(step, from = this.currentGroup()) {
        let at = this.groups.indexOf(from) + step;
        while (this.groups[at]) {
            const group = this.groups[at];
            if (this.editable(group) && group.lines.length > 0) {
                if (this.commit()) {
                    this.focusCell(group.key, 0, 'account');
                }

                return;
            }
            at += step;
        }
    }

    leaveGrid() {
        const next = document.querySelector('.px-entry__checks:not([hidden]) , .px-statusbar');
        (next ?? document.getElementById('main'))?.setAttribute('tabindex', '-1');
        (next ?? document.getElementById('main'))?.focus();
    }

    /** « + »: the amount that balances the entry, on the right side of this line (design-ux.md § 5.2). */
    balanceCurrent() {
        const group = this.currentGroup();
        if (!group || !this.editable(group) || !this.pos) {
            return;
        }
        if (this.counterpart) {
            this.say(`Dans ce journal, la contrepartie ${this.counterpart.number} se calcule seule.`);

            return;
        }
        const line = group.lines[this.pos.line];
        const others = totals(group.lines.filter((one) => one !== line));
        const gap = others.debit - others.credit;
        if (gap === 0) {
            this.say('L’écriture est équilibrée sans cette ligne.');

            return;
        }
        line.debit = gap < 0 ? -gap : 0;
        line.credit = gap > 0 ? gap : 0;
        group.dirty = true;
        group.modified = true;
        delete group.local[`${this.pos.line}.debit`];
        delete group.local[`${this.pos.line}.credit`];
        const field = gap > 0 ? 'credit' : 'debit';
        this.renderGroup(group);
        this.focusCell(group.key, this.pos.line, field);
        this.enter();
    }

    copyAbove() {
        const group = this.currentGroup();
        const index = this.pos.line;
        const field = this.pos.field;
        let above = null;
        if (index > 0) {
            above = group.lines[index - 1];
        } else {
            const previous = this.groups[this.groups.indexOf(group) - 1];
            above = previous?.lines.at(-1) ?? null;
            if (field === 'day' && previous) {
                this.inputAt().value = dayLabel(previous.date, this.period.start);

                return;
            }
            if (field === 'document' && previous) {
                this.inputAt().value = previous.document;

                return;
            }
        }
        if (!above) {
            return;
        }
        const value = { account: above.account, thirdParty: above.thirdParty, label: above.label, debit: cell(above.debit), credit: cell(above.credit), due: frenchDate(above.due) }[field];
        if (value !== undefined) {
            const input = this.inputAt();
            input.value = value;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.select();
        }
    }

    insertLine() {
        const group = this.currentGroup();
        if (!group || !this.editable(group)) {
            return;
        }
        if (!this.commit()) {
            return;
        }
        const index = this.pos.line;
        group.lines.splice(index, 0, blankLine(group.lines[index]?.label ?? ''));
        group.dirty = true;
        group.modified = true;
        this.shiftLocal(group, index, 1);
        this.renderGroup(group);
        this.focusCell(group.key, index, 'account');
    }

    deleteLine() {
        const group = this.currentGroup();
        if (!group || !this.editable(group)) {
            return;
        }
        const index = this.pos.line;
        group.lines.splice(index, 1);
        this.shiftLocal(group, index, -1);
        group.dirty = true;
        group.modified = true;
        if (group.lines.length === 0 && group.state === 'new') {
            this.groups.splice(this.groups.indexOf(group), 1);
            this.ensureTail();
            this.render();
            this.pos = null;
            this.place(null);
            this.scheduleKeep(0);

            return;
        }
        this.renderGroup(group);
        this.say('Ligne supprimée.');
        if (group.lines.length === 0) {
            const note = this.tableTarget.querySelector(`tbody[data-key="${CSS.escape(group.key)}"] [data-field="deleted"]`);
            note?.focus();
            this.scheduleKeep(0);

            return;
        }
        this.focusCell(group.key, Math.min(index, group.lines.length - 1), 'account');
    }

    shiftLocal(group, from, step) {
        const local = {};
        for (const [key, message] of Object.entries(group.local)) {
            const [line, field] = key.split('.');
            const at = Number(line);
            if (step < 0 && at === from) {
                continue;
            }
            local[`${at >= from ? at + step : at}.${field}`] = message;
        }
        group.local = local;
    }

    /** Échap: closes the list, else undoes the cell, else offers to drop the entry's changes. */
    escape(input) {
        if (!this.createTarget.hidden) {
            this.closeCreate();

            return;
        }
        if (input.value !== this.entered) {
            input.value = this.entered;
            input.select();
            const group = this.currentGroup();
            delete group.local[`${this.pos.line}.${this.pos.field}`];
            input.removeAttribute('aria-invalid');

            return;
        }
        const group = this.currentGroup();
        if (group && (group.dirty || group.modified) && this.typed(group).length + (group.entry ? 1 : 0) > 0) {
            this.openAbandon(group);
        }
    }

    openAbandon(group) {
        this.closeAbandon(false);
        const body = this.tableTarget.querySelector(`tbody[data-key="${CSS.escape(group.key)}"]`);
        const row = body.insertRow();
        row.className = 'px-entry__abandon';
        const td = row.insertCell();
        td.colSpan = 9;
        td.setAttribute('role', 'alertdialog');
        td.setAttribute('aria-label', 'Abandonner les modifications');
        const text = document.createElement('span');
        text.textContent = group.state === 'new' ? 'Abandonner cette écriture ?' : 'Abandonner les modifications de cette écriture ?';
        const drop = this.button('Abandonner', 'px-btn px-btn--sm px-btn--danger', () => this.abandon(group));
        const keep = this.button('Continuer la saisie', 'px-btn px-btn--sm px-btn--primary', () => this.closeAbandon(true));
        td.append(text, drop, keep);
        this.abandonRow = { row, pos: { ...this.pos } };
        keep.focus({ preventScroll: true });
        row.scrollIntoView({ block: 'nearest' });
    }

    closeAbandon(refocus) {
        if (!this.abandonRow) {
            return;
        }
        const { row, pos } = this.abandonRow;
        this.abandonRow = null;
        row.remove();
        if (refocus) {
            this.focusCell(pos.key, pos.line, pos.field);
        }
    }

    abandon(group) {
        this.abandonRow?.row.remove();
        this.abandonRow = null;
        if (group.state === 'new') {
            this.groups.splice(this.groups.indexOf(group), 1);
        } else {
            group.lines = group.original.map((line) => ({ ...line }));
            group.modified = false;
            group.dirty = false;
            group.local = {};
            group.errors = {};
            group.controls = [];
            group.tried = false;
        }
        this.ensureTail();
        this.pos = null;
        this.render();
        this.place(group.state === 'new' ? null : group.entry);
        this.scheduleKeep(0);
        this.say(group.state === 'new' ? 'Écriture abandonnée.' : 'Modifications abandonnées.');
    }

    button(label, className, action) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = className;
        button.textContent = label;
        button.addEventListener('click', action);

        return button;
    }

    /* ------------------------------------------------------------- The list under Compte */

    openPicker(text, full) {
        const input = this.inputAt();
        if (!input) {
            return;
        }
        const field = this.pos.field;
        const typed = text.trim();
        if (!full && typed === '') {
            this.closePicker();

            return;
        }
        if (!full && this.picker?.full) {
            full = true;
        }
        const cls = full ? (this.picker?.cls ?? null) : null;
        const options = field === 'thirdParty'
            ? this.lookup.searchThirdParties(typed, full ? 200 : 8)
            : this.lookup.search(typed, full ? 200 : 8, cls);
        this.picker = { options, active: options.length > 0 ? 0 : -1, full, cls, field };
        const panel = this.pickerTarget;
        panel.classList.toggle('is-full', full);
        this.classesTarget.hidden = !full || field === 'thirdParty';
        if (full && field !== 'thirdParty') {
            const classes = [[null, 'Tout'], ...['1', '2', '3', '4', '5', '6', '7', '8'].map((c) => [c, `Classe ${c}`]), ['T', 'Tiers']];
            this.classesTarget.replaceChildren(...classes.map(([value, label]) => {
                const li = document.createElement('li');
                li.textContent = label;
                li.className = 'px-entry__class';
                li.setAttribute('aria-current', value === cls ? 'true' : 'false');
                li.addEventListener('pointerdown', (event) => {
                    event.preventDefault();
                    this.picker.cls = value;
                    this.openPicker(this.inputAt().value, true);
                });

                return li;
            }));
        }
        const items = options.map((option, index) => {
            const li = document.createElement('li');
            li.className = 'px-picker__option';
            li.id = `entry-picker-${index}`;
            li.setAttribute('role', 'option');
            const value = document.createElement('span');
            value.className = 'px-u-code';
            const upper = typed.replace(/\s+/g, '').toUpperCase();
            if (upper && option.value.startsWith(upper)) {
                value.append(Object.assign(document.createElement('mark'), { textContent: option.value.slice(0, upper.length) }), option.value.slice(upper.length));
            } else {
                value.textContent = option.value;
            }
            const label = Object.assign(document.createElement('span'), { className: 'px-picker__label', textContent: option.label, title: option.label });
            const money = Object.assign(document.createElement('span'), { className: 'px-picker__balance', textContent: option.balance ? balance(option.balance) : '' });
            li.append(value, label, money);
            li.addEventListener('pointerdown', (event) => {
                event.preventDefault();
                this.picker.active = index;
                this.enter();
            });

            return li;
        });
        if (items.length === 0) {
            items.push(Object.assign(document.createElement('li'), {
                className: 'px-picker__empty',
                textContent: field === 'thirdParty' ? 'Aucun tiers ne correspond.' : (/^\d+$/.test(typed) ? `Aucun compte ne commence par ${typed}. Alt Inser pour le créer.` : 'Aucun compte ni tiers ne correspond.'),
            }));
        }
        this.pickerListTarget.replaceChildren(...items);
        panel.hidden = false;
        this.anchor(panel, input);
        input.setAttribute('aria-expanded', 'true');
        this.highlight();
    }

    /** A panel under its cell, or above it when the window has no room below. */
    anchor(panel, input) {
        const cellBox = input.closest('td').getBoundingClientRect();
        const box = this.element.getBoundingClientRect();
        const height = panel.offsetHeight;
        const below = window.innerHeight - cellBox.bottom - 36;
        const top = below < height && cellBox.top - height > 80 ? cellBox.top - box.top - height - 2 : cellBox.bottom - box.top + 2;
        panel.style.insetBlockStart = `${top}px`;
        panel.style.insetInlineStart = `${Math.min(cellBox.left - box.left, box.width - panel.offsetWidth)}px`;
    }

    movePicker(step) {
        const count = this.picker.options.length;
        if (count === 0) {
            return;
        }
        this.picker.active = (this.picker.active + step + count) % count;
        this.highlight();
    }

    moveClass(step) {
        const order = [null, '1', '2', '3', '4', '5', '6', '7', '8', 'T'];
        const at = order.indexOf(this.picker.cls);
        this.picker.cls = order[(at + step + order.length) % order.length];
        this.openPicker(this.inputAt().value, true);
    }

    highlight() {
        const input = this.inputAt();
        [...this.pickerListTarget.querySelectorAll('[role="option"]')].forEach((li, index) => {
            const on = index === this.picker.active;
            li.setAttribute('aria-selected', on ? 'true' : 'false');
            if (on) {
                li.scrollIntoView({ block: 'nearest' });
                input?.setAttribute('aria-activedescendant', li.id);
            }
        });
    }

    pickedValue() {
        if (!this.picker || this.picker.active < 0) {
            return null;
        }

        return this.picker.options[this.picker.active]?.value ?? null;
    }

    closePicker() {
        if (!this.picker) {
            return;
        }
        this.picker = null;
        this.pickerTarget.hidden = true;
        const input = this.inputAt();
        input?.setAttribute('aria-expanded', 'false');
        input?.removeAttribute('aria-activedescendant');
    }

    /* ------------------------------------------------------------ Alt Inser */

    openCreate() {
        if (this.pos?.field !== 'account') {
            return;
        }
        const typed = this.inputAt().value.replace(/\s+/g, '');
        if (!/^\d+$/.test(typed)) {
            this.say('Alt Inser crée un compte du plan : tapez son numéro. Un tiers se crée dans Paramètres comptables › Tiers.');

            return;
        }
        this.closePicker();
        this.creating = { pos: { ...this.pos }, number: typed };
        this.createNumberTarget.textContent = typed;
        this.createLabelTarget.value = '';
        this.createErrorTarget.hidden = true;
        this.createTarget.hidden = false;
        this.anchor(this.createTarget, this.inputAt());
        this.createLabelTarget.focus();
    }

    async createKeydown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            this.closeCreate();

            return;
        }
        if (event.key !== 'Enter') {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        const { pos, number } = this.creating;
        const answer = await this.post(this.accountUrlValue, { number, label: this.createLabelTarget.value });
        if (!answer) {
            return;
        }
        if (answer.status === 422) {
            const errors = answer.body.errors ?? {};
            this.createErrorTarget.textContent = Object.values(errors).join(' ');
            this.createErrorTarget.hidden = false;

            return;
        }
        this.lookup.add(answer.body.account);
        this.createTarget.hidden = true;
        this.creating = null;
        this.say(answer.body.message);
        const group = this.groups.find((one) => one.key === pos.key);
        if (group) {
            group.lines[pos.line].account = answer.body.account.n;
            delete group.local[`${pos.line}.account`];
            group.dirty = true;
            group.modified = true;
            this.renderGroup(group);
            this.focusCell(pos.key, pos.line, 'account');
            this.enter();
        }
    }

    closeCreate() {
        this.createTarget.hidden = true;
        const pos = this.creating?.pos;
        this.creating = null;
        if (pos) {
            this.focusCell(pos.key, pos.line, pos.field);
        }
    }

    /* ------------------------------------------------ Account detail (F5, design-ux.md § 5.1) */

    /**
     * F5: the current line's account - or third party, when the line names one by its auxiliary
     * code - in the drawer: its balance at the dossier's date and its last movements, served by the
     * consultation (a fragment); « Consulter » opens the whole consultation, which leads back here.
     * The cell is committed first, so what was just typed is what is shown.
     */
    async peek() {
        if (!this.hasPeekTarget || !this.commit()) {
            return;
        }
        const opener = this.inputAt();
        const line = this.currentGroup()?.lines[this.pos?.line];
        const found = line ? this.resolved(line) : null;
        const code = found ? (found.auxiliary ? found.thirdParty.a : found.account.n) : '';
        const url = new URL(this.peekUrlValue, window.location.href);
        url.searchParams.set('account', code);
        url.searchParams.set('return', window.location.pathname + window.location.search);
        this.peekBodyTarget.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(url, { headers: { Accept: 'text/html', 'X-Requested-With': 'fetch' } });
            this.peekBodyTarget.innerHTML = await response.text();
        } catch {
            this.peekBodyTarget.textContent = 'Le détail du compte n’a pas pu être chargé. Réessayez (F5).';
        } finally {
            this.peekBodyTarget.removeAttribute('aria-busy');
        }
        this.peekTarget.dispatchEvent(new CustomEvent('px:open', { detail: { opener } }));
    }

    /* -------------------------------------------- Entry templates (F6, design-ux.md § 5.1) */

    /**
     * F6: the journal's templates listed by the current cell; the one chosen asks the amount and
     * the rate when its lines are in HT, TVA or TTC, then the server computes its lines, laid in the
     * current entry (an entry that cannot change: the new one at the end).
     */
    openTemplates() {
        if (!this.editableValue) {
            return;
        }
        if (this.calling) {
            (this.templateAskTarget.hidden ? this.templateFilterTarget : this.templateAmountTarget).focus();

            return;
        }
        if (this.templates.length === 0) {
            this.say(`Le journal ${this.journal.code} n’a pas de modèle d’écriture : Paramètres comptables › Modèles d’écriture pour en créer un.`);

            return;
        }
        if (this.pos && !this.commit()) {
            return;
        }
        this.closePicker();
        let group = this.currentGroup();
        if (!group || !this.editable(group)) {
            group = this.groups.at(-1);
        }
        if (!group || !this.editable(group)) {
            return;
        }
        this.calling = { key: group.key, back: this.pos ? { ...this.pos } : { key: group.key, line: 0, field: 'account' }, template: null, index: 0 };
        this.templateFilterTarget.value = '';
        this.templateAskTarget.hidden = true;
        this.templateChooseTarget.hidden = false;
        this.listTemplates();
        this.templatesTarget.hidden = false;
        const input = this.inputAt() ?? this.tableTarget.querySelector(`tbody[data-key="${CSS.escape(group.key)}"] input.px-entry__cell`);
        if (input) {
            this.anchor(this.templatesTarget, input);
        }
        this.templateFilterTarget.focus();
    }

    filterTemplates() {
        this.calling.index = 0;
        this.listTemplates();
    }

    /** The templates whose code or label holds the words typed, without accents nor case. */
    shownTemplates() {
        const fold = (text) => text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
        const words = fold(this.templateFilterTarget.value).split(/\s+/).filter(Boolean);

        return this.templates.filter((template) => words.every((word) => fold(`${template.code} ${template.label}`).includes(word)));
    }

    listTemplates() {
        const shown = this.shownTemplates();
        this.calling.index = Math.min(this.calling.index, Math.max(0, shown.length - 1));
        const items = shown.map((template, index) => {
            const item = document.createElement('li');
            item.className = 'px-picker__option';
            item.id = `entry-template-${index}`;
            item.setAttribute('role', 'option');
            item.setAttribute('aria-selected', String(index === this.calling.index));
            const code = document.createElement('span');
            code.className = 'px-u-code';
            code.textContent = template.code;
            const label = document.createElement('span');
            label.className = 'px-picker__label';
            label.textContent = template.label;
            const hint = document.createElement('span');
            hint.className = 'px-picker__balance';
            hint.textContent = template.asks ? `${template.base.replace('Montant ', '')} · ${template.rate} %` : '';
            item.append(code, label, hint);
            item.addEventListener('pointerdown', (event) => {
                event.preventDefault();
                this.chooseTemplate(template);
            });

            return item;
        });
        if (items.length === 0) {
            const empty = document.createElement('li');
            empty.className = 'px-picker__empty';
            empty.textContent = 'Aucun modèle ne correspond.';
            items.push(empty);
        }
        this.templateListTarget.replaceChildren(...items);
        this.templateFilterTarget.setAttribute('aria-activedescendant', shown.length > 0 ? `entry-template-${this.calling.index}` : '');
    }

    templateKeydown(event) {
        const key = event.key;
        if (key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            if (!this.templateAskTarget.hidden && this.templates.length > 1) {
                // Back to the list.
                this.templateAskTarget.hidden = true;
                this.templateChooseTarget.hidden = false;
                this.templateFilterTarget.focus();
            } else {
                this.closeTemplates(true);
            }

            return;
        }
        if (key === 'F10' || ((event.ctrlKey || event.metaKey) && key === 'Enter')) {
            // The grid's keys stay the grid's: nothing is saved while the panel is open.
            event.preventDefault();
            event.stopPropagation();

            return;
        }
        if (!this.templateChooseTarget.hidden) {
            const shown = this.shownTemplates();
            if (key === 'ArrowDown' || key === 'ArrowUp') {
                event.preventDefault();
                if (shown.length > 0) {
                    this.calling.index = (this.calling.index + (key === 'ArrowDown' ? 1 : -1) + shown.length) % shown.length;
                    this.listTemplates();
                }
            } else if (key === 'Enter') {
                event.preventDefault();
                event.stopPropagation();
                if (shown[this.calling.index]) {
                    this.chooseTemplate(shown[this.calling.index]);
                }
            }

            return;
        }
        if (key === 'Enter') {
            event.preventDefault();
            event.stopPropagation();
            if (event.target === this.templateAmountTarget && this.templateRateTarget.value.trim() === '') {
                this.templateRateTarget.focus();

                return;
            }
            this.callTemplate();
        }
    }

    chooseTemplate(template) {
        this.calling.template = template;
        if (!template.asks) {
            this.callTemplate();

            return;
        }
        this.templateChooseTarget.hidden = true;
        this.templateAskTarget.hidden = false;
        this.templateNameTarget.textContent = `${template.code} · ${template.label}`;
        this.templateBaseTarget.textContent = template.base;
        this.templateAmountTarget.value = '';
        this.templateRateTarget.value = template.rate;
        this.templateErrorTarget.hidden = true;
        this.templateAmountTarget.focus();
    }

    async callTemplate() {
        const { template, key } = this.calling;
        const group = this.groups.find((one) => one.key === key);
        if (!template || !group) {
            this.closeTemplates(true);

            return;
        }
        let cents = null;
        if (template.asks) {
            cents = parseAmount(this.templateAmountTarget.value);
            if (cents === null || cents <= 0) {
                this.templateError({ amount: `Indiquez le ${template.base.toLowerCase()} : « 3200 », « 3 840,00 ».` });
                this.templateAmountTarget.focus();

                return;
            }
        }
        const answer = await this.post(this.templateUrlValue, {
            journal: this.journal.code,
            period: this.periodValue,
            template: template.code,
            date: group.date ?? this.period.start,
            amount: cents,
            rate: this.templateRateTarget.value,
        });
        if (!answer) {
            this.templateError({ network: 'Le serveur ne répond pas : rien n’est posé, réessayez.' });

            return;
        }
        if (answer.status === 422) {
            this.templateError(answer.body.errors ?? {});
            (answer.body.errors?.rate ? this.templateRateTarget : this.templateAmountTarget).focus();

            return;
        }
        if (answer.status !== 200) {
            this.templateError({ refused: 'Le modèle n’a pas pu être appelé : rechargez la page.' });

            return;
        }
        this.layTemplate(group, answer.body.lines ?? []);
        this.say(answer.body.message);
    }

    templateError(errors) {
        this.templateErrorTarget.textContent = Object.values(errors).join(' ');
        this.templateErrorTarget.hidden = false;
        if (this.templateAskTarget.hidden) {
            this.say(this.templateErrorTarget.textContent);
        }
    }

    /**
     * The template's lines in the entry: after the lines already typed (its empty lines dropped),
     * then the focus on the first cell still to fill - the third party a collective asks, an
     * amount to type -, or on the next entry when it balances.
     */
    layTemplate(group, lines) {
        this.closeTemplates(false);
        while (group.lines.length > 0 && isBlank(group.lines.at(-1))) {
            group.lines.pop();
        }
        const first = group.lines.length;
        for (const line of lines) {
            group.lines.push({ account: line.account, thirdParty: line.thirdParty, label: line.label, debit: line.debit, credit: line.credit, due: null });
        }
        group.dirty = true;
        group.modified = true;
        if (!this.isBalanced(group)) {
            group.lines.push(blankLine(group.lines.at(-1)?.label ?? ''));
        }
        this.ensureTail();
        this.render();
        for (let index = first; index < group.lines.length; index++) {
            const line = group.lines[index];
            for (const field of ['thirdParty', 'debit']) {
                if (field === 'debit' && isBlank(line)) {
                    continue;
                }
                if (this.toFill(group, index, field, null)) {
                    this.focusCell(group.key, index, field);
                    this.scheduleKeep(0);

                    return;
                }
            }
        }
        if (this.isBalanced(group)) {
            this.focusCell(group.key, group.lines.length - 1, 'debit');
            this.nextEntry(group);
        } else {
            this.focusCell(group.key, group.lines.length - 1, 'account');
        }
        this.scheduleKeep(0);
    }

    closeTemplates(refocus) {
        if (!this.calling) {
            return;
        }
        const back = this.calling.back;
        this.calling = null;
        this.templatesTarget.hidden = true;
        if (refocus && back) {
            this.focusCell(back.key, back.line, back.field);
        }
    }

    /* -------------------------------------------------------- The server */

    /** A line left: the entry it belongs to is kept as pending work, and the grid ends on an empty line. */
    lineLeft(previous, next) {
        const group = this.groups.find((one) => one.key === previous.key);
        if (!group) {
            return;
        }
        const line = group.lines[previous.line];
        const stays = next && next.key === group.key;
        // An empty line in the middle of an entry, left, is not kept.
        if (line && isBlank(line) && !stays && group.lines.length > 1 && previous.line < group.lines.length - 1) {
            group.lines.splice(previous.line, 1);
            this.shiftLocal(group, previous.line, -1);
        }
        if (!stays && group.state === 'new' && this.typed(group).length === 0 && group !== this.groups.at(-1)) {
            this.groups.splice(this.groups.indexOf(group), 1);
            this.render();
            if (next) {
                this.focusCell(next.key, next.line, next.field);
            }
        } else if (!stays) {
            this.renderGroup(group);
            if (next) {
                this.focusCell(next.key, next.line, next.field);
            }
        }
        if (group.dirty) {
            this.scheduleKeep(0);
        }
    }

    scheduleKeep(delay) {
        clearTimeout(this.keepTimer);
        this.keepTimer = setTimeout(() => this.keep(), delay);
    }

    /** The groups to send: new ones with something typed, drafts being changed. */
    pendingGroups() {
        return this.groups
            .filter((group) => this.editable(group) && (group.modified || group.dirty))
            .filter((group) => group.state !== 'new' || this.typed(group).length > 0)
            .map((group) => ({
                key: group.key,
                entry: group.state === 'new' ? null : group.entry,
                date: group.date,
                document: group.document,
                lines: this.typed(group).map(({ line }) => ({
                    account: line.account,
                    thirdParty: line.thirdParty,
                    label: line.label,
                    debit: line.debit || 0,
                    credit: line.credit || 0,
                    due: line.due,
                })),
            }));
    }

    async keep() {
        if (!this.editableValue) {
            return;
        }
        if (this.sending) {
            this.again = true;

            return;
        }
        const sent = this.groups.filter((group) => group.dirty);
        sent.forEach((group) => { group.dirty = false; });
        const payload = this.payload();
        this.sending = this.post(this.keepUrlValue, payload);
        const answer = await this.sending;
        this.sending = null;
        if (!answer || answer.status !== 200) {
            sent.forEach((group) => { group.dirty = true; });
            this.markFailed(answer);
        } else {
            this.failed = false;
            this.revision = answer.body.revision;
            for (const [key, check] of Object.entries(answer.body.checks ?? {})) {
                const group = this.groups.find((one) => one.key === key);
                if (group) {
                    group.errors = Array.isArray(check.errors) ? {} : check.errors;
                    group.controls = check.controls;
                    group.dues = check.dues;
                    if (group.key !== this.pos?.key) {
                        this.renderGroup(group);
                    }
                }
            }
            this.refreshTotals();
            if (payload.groups.length > 0) {
                this.say('Brouillon enregistré.');
            }
        }
        if (this.again) {
            this.again = false;
            this.keep();
        }
    }

    payload(extra = {}) {
        return { journal: this.journal.code, period: this.periodValue, revision: this.revision, groups: this.pendingGroups(), ...extra };
    }

    markFailed(answer) {
        this.failed = true;
        if (answer?.status === 409) {
            this.problemTarget.textContent = answer.body.message ?? 'Cette saisie a été modifiée dans un autre onglet. Rechargez la page pour la reprendre.';
            this.problemTarget.hidden = false;
            this.failed = false;
            this.stale = true;

            return;
        }
        this.say('L’enregistrement n’a pas abouti. Vos saisies sont conservées à l’écran ; elles seront renvoyées à la prochaine frappe.');
        this.tableTarget.classList.add('is-unsaved');
    }

    /** F10: the cell read, then every entry that balances written to the brouillard. */
    async save() {
        if (!this.editableValue || this.busy) {
            return;
        }
        if (this.pos && this.inputAt() && !this.commit()) {
            return;
        }
        this.closePicker();
        await this.flush();
        this.groups.forEach((group) => { group.tried = true; });
        await this.send(this.saveUrlValue, this.head.save, 'Enregistrement…');
    }

    askValidation() {
        if (!this.editableValue || this.busy) {
            return;
        }
        if (this.pos && this.inputAt() && !this.commit()) {
            return;
        }
        this.closePicker();
        const count = this.groups.filter((group) => this.editable(group) && group.lines.length > 0 && this.isBalanced(group) && Object.keys(group.errors).length === 0 && Object.keys(group.local).length === 0).length;
        if (count === 0) {
            this.say('Aucune écriture équilibrée à valider dans ce journal et ce mois.');

            return;
        }
        this.confirming = { focus: { ...(this.pos ?? {}) } };
        this.head.confirmText.textContent = `Valider ${count === 1 ? 'l’écriture' : `${count} écritures`} de ${this.journal.code} · ${this.period.label.toLowerCase()} ? ${count === 1 ? 'Elle ne pourra' : 'Elles ne pourront'} plus être ${count === 1 ? 'modifiée' : 'modifiées'}, seulement ${count === 1 ? 'contrepassée' : 'contrepassées'}.`;
        this.head.confirmOk.querySelector('span').textContent = count === 1 ? 'Valider l’écriture' : `Valider ${count} écritures`;
        this.head.actions.hidden = true;
        this.head.confirm.hidden = false;
        this.head.confirmOk.focus();
    }

    confirmKeydown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            this.closeConfirm();
        } else if (event.key === 'Enter' && event.target === this.head.confirmOk) {
            event.preventDefault();
            event.stopPropagation();
            this.validate();
        }
    }

    closeConfirm() {
        this.head.confirm.hidden = true;
        this.head.actions.hidden = false;
        const pos = this.confirming?.focus;
        this.confirming = null;
        if (pos?.key) {
            this.focusCell(pos.key, pos.line, pos.field);
        } else {
            this.place(null);
        }
    }

    async validate() {
        this.head.confirm.hidden = true;
        this.head.actions.hidden = false;
        this.confirming = null;
        await this.flush();
        this.groups.forEach((group) => { group.tried = true; });
        await this.send(this.validateUrlValue, this.head.validate, 'Validation…');
    }

    /** Waits for a keep in flight. */
    async flush() {
        clearTimeout(this.keepTimer);
        if (this.sending) {
            await this.sending;
        }
    }

    async send(url, button, busyLabel) {
        this.busy = true;
        const label = button?.querySelector('span');
        const before = label?.textContent;
        if (label) {
            label.textContent = busyLabel;
            button.setAttribute('aria-busy', 'true');
        }
        this.groups.forEach((group) => { group.dirty = false; });
        const answer = await this.post(url, this.payload());
        if (label) {
            label.textContent = before;
            button.removeAttribute('aria-busy');
        }
        this.busy = false;
        if (!answer || answer.status !== 200) {
            this.groups.forEach((group) => {
                if (group.modified) {
                    group.dirty = true;
                }
            });
            this.markFailed(answer);

            return;
        }
        this.failed = false;
        this.tableTarget.classList.remove('is-unsaved');
        const body = answer.body;
        this.revision = body.revision;
        this.groups = body.groups.map((group) => ({ ...this.adopt(group), tried: true }));
        if (this.counterpart && typeof body.counterpartBalance === 'number') {
            this.counterpart.balance = body.counterpartBalance;
        }
        if (this.status.drafts) {
            this.status.drafts.textContent = `${body.draftsCount} écriture${body.draftsCount > 1 ? 's' : ''}`;
        }
        this.ensureTail();
        this.pos = null;
        this.render();
        const left = this.groups.find((group) => this.editable(group) && (group.modified) && group.state !== 'validated' && this.typed(group).length > 0);
        if (left) {
            this.focusCell(left.key, 0, 'account');
            this.inputAt()?.closest('tr')?.scrollIntoView({ block: 'center' });
        } else {
            this.place(null);
        }
        this.say(body.message);
    }

    async post(url, data) {
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Peyra-Token': this.tokenValue },
                body: JSON.stringify(data),
            });
            let body = {};
            try {
                body = await response.json();
            } catch {
                body = {};
            }

            return { status: response.status, body };
        } catch {
            return null;
        }
    }

    /* -------------------------------------------------------- Leaving the screen */

    unbalanced() {
        return this.groups.filter((group) => this.editable(group) && group.lines.length > 0 && this.typed(group).length > 0 && !this.isBalanced(group));
    }

    beforeVisit(event) {
        if (!this.editableValue || this.leaving || this.stale) {
            return;
        }
        if (this.pos && this.inputAt()) {
            this.commit(this.inputAt(), false);
        }
        if (this.unbalanced().length === 0) {
            if (this.groups.some((group) => group.dirty)) {
                this.keep();
            }

            return;
        }
        event.preventDefault();
        this.leavingTo = event.detail.url;
        this.head.leave.querySelector('#entry-leave-text').textContent = this.unbalanced().length === 1 ? 'Une écriture n’est pas équilibrée.' : `${this.unbalanced().length} écritures ne sont pas équilibrées.`;
        this.head.actions.hidden = true;
        this.head.leave.hidden = false;
        this.head.leaveStay.focus();
    }

    leaveKeydown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            this.leave('stay');
        }
    }

    async leave(choice) {
        this.head.leave.hidden = true;
        this.head.actions.hidden = false;
        if (choice === 'stay') {
            this.place(null);

            return;
        }
        if (choice === 'drop') {
            for (const group of this.unbalanced()) {
                if (group.state === 'new') {
                    this.groups.splice(this.groups.indexOf(group), 1);
                } else {
                    group.lines = group.original.map((line) => ({ ...line }));
                    group.modified = false;
                }
                group.dirty = true;
            }
        }
        this.groups.forEach((group) => { group.dirty = true; });
        await this.flush();
        await this.keep();
        this.leaving = true;
        window.Turbo?.visit(this.leavingTo);
    }

    beforeUnload(event) {
        if (this.editableValue && (this.failed || this.sending || this.groups.some((group) => group.dirty))) {
            if (!this.failed) {
                this.keep();
            }
            event.preventDefault();
        }
    }

    /** Journal or month changed (Alt J, Alt P): the same screen for them. */
    switch(event) {
        event.target.form.requestSubmit();
    }

    say(message) {
        if (this.status.message) {
            this.status.message.textContent = message;
        }
    }
}

const LABELS = {
    day: 'Jour',
    document: 'Pièce',
    account: 'Compte',
    thirdParty: 'Tiers',
    label: 'Libellé',
    debit: 'Débit',
    credit: 'Crédit',
    due: 'Échéance',
};
