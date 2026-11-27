import { Controller } from '@hotwired/stimulus';

/*
 * The account picker (design-ui.md § 4.6, Px:AccountPicker): the field holds an account number, or a
 * third party's auxiliary code (« 401SCIE ») where the search lists third parties. From
 * the second character typed, or at once on F4, the dossier's accounts that start with the digits
 * (or whose label holds the words) are listed under the field, grouped under their heading, the
 * typed part in <mark>. ↑ ↓ choose, Entrée takes the line and moves to the next field, Échap closes
 * without changing the value. `url` answers ?q=… with {groups: [{label, options: [{value, label,
 * balance}]}], more, empty} - `balance` is the account's or third party's balance at the dossier's
 * date (« 3 248,40 C »), empty when nothing moved it; `nature` narrows the search (a bank
 * journal's counterpart).
 */
export default class extends Controller {
    static targets = ['input', 'panel', 'list', 'chosen'];
    static values = { url: String, nature: String };

    connect() {
        this.options = [];
        this.active = -1;
        this.onOutside = (event) => {
            if (!this.element.contains(event.target)) {
                this.close();
            }
        };
        document.addEventListener('pointerdown', this.onOutside);
    }

    disconnect() {
        document.removeEventListener('pointerdown', this.onOutside);
        clearTimeout(this.timer);
        this.request?.abort();
    }

    /** Entering the field selects its number: typing replaces it (design-ux.md § 5.2). */
    select() {
        this.inputTarget.select();
    }

    search() {
        clearTimeout(this.timer);
        this.chosenTarget.textContent = '';
        const text = this.inputTarget.value.trim();
        if (text.length < 2) {
            this.close();

            return;
        }
        this.timer = setTimeout(() => this.fetch(text), 150);
    }

    async fetch(text) {
        this.request?.abort();
        this.request = new AbortController();
        const url = new URL(this.urlValue, window.location.href);
        url.searchParams.set('q', text);
        if (this.natureValue) {
            url.searchParams.set('nature', this.natureValue);
        }
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: this.request.signal });
            if (response.ok) {
                this.show(await response.json(), text);
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                throw error;
            }
        }
    }

    show(result, text) {
        this.options = [];
        const items = [];
        for (const group of result.groups ?? []) {
            const heading = document.createElement('li');
            heading.className = 'px-picker__group';
            heading.setAttribute('role', 'presentation');
            heading.textContent = group.label;
            items.push(heading);
            for (const option of group.options) {
                items.push(this.optionElement(option, this.options.length, text));
                this.options.push(option);
            }
        }
        if (this.options.length === 0) {
            const empty = document.createElement('li');
            empty.className = 'px-picker__empty';
            empty.setAttribute('role', 'presentation');
            empty.textContent = result.empty ?? 'Aucun compte ne correspond.';
            items.push(empty);
        } else if (result.more) {
            const more = document.createElement('li');
            more.className = 'px-picker__empty';
            more.setAttribute('role', 'presentation');
            more.textContent = 'Tapez davantage pour voir les autres comptes.';
            items.push(more);
        }
        this.listTarget.replaceChildren(...items);
        this.active = this.options.length > 0 ? 0 : -1;
        this.panelTarget.hidden = false;
        this.inputTarget.setAttribute('aria-expanded', 'true');
        this.highlight();
    }

    optionElement(option, index, text) {
        const item = document.createElement('li');
        item.className = 'px-picker__option';
        item.id = `${this.listTarget.id}-${index}`;
        item.setAttribute('role', 'option');
        const number = document.createElement('span');
        number.className = 'px-u-code';
        const typed = text.replace(/\s+/g, '').toUpperCase();
        if (/^[0-9A-Z]+$/.test(typed) && option.value.startsWith(typed)) {
            const mark = document.createElement('mark');
            mark.textContent = option.value.slice(0, typed.length);
            number.append(mark, option.value.slice(typed.length));
        } else {
            number.textContent = option.value;
        }
        const label = document.createElement('span');
        label.className = 'px-picker__label';
        label.textContent = option.label;
        label.title = option.label;
        const balance = document.createElement('span');
        balance.className = 'px-picker__balance';
        balance.textContent = option.balance ?? '';
        item.append(number, label, balance);
        item.addEventListener('pointerdown', (event) => {
            event.preventDefault();
            this.choose(index);
        });

        return item;
    }

    keydown(event) {
        if (event.key === 'F4') {
            event.preventDefault();
            clearTimeout(this.timer);
            this.fetch(this.inputTarget.value.trim());

            return;
        }
        if (this.panelTarget.hidden) {
            return;
        }
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (this.options.length > 0) {
                const step = event.key === 'ArrowDown' ? 1 : -1;
                this.active = (this.active + step + this.options.length) % this.options.length;
                this.highlight();
            }
        } else if (event.key === 'Enter' && this.active >= 0) {
            event.preventDefault();
            this.choose(this.active);
            this.focusNext();
        } else if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            this.close();
        }
    }

    highlight() {
        [...this.listTarget.querySelectorAll('[role="option"]')].forEach((item, index) => {
            const selected = index === this.active;
            item.setAttribute('aria-selected', selected ? 'true' : 'false');
            if (selected) {
                item.scrollIntoView({ block: 'nearest' });
            }
        });
        if (this.active >= 0) {
            this.inputTarget.setAttribute('aria-activedescendant', `${this.listTarget.id}-${this.active}`);
        } else {
            this.inputTarget.removeAttribute('aria-activedescendant');
        }
    }

    choose(index) {
        const option = this.options[index];
        if (!option) {
            return;
        }
        this.inputTarget.value = option.value;
        this.chosenTarget.textContent = option.label;
        this.close();
    }

    focusNext() {
        const form = this.inputTarget.form;
        if (!form) {
            return;
        }
        const fields = [...form.querySelectorAll('input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), button:not([disabled])')];
        const next = fields[fields.indexOf(this.inputTarget) + 1];
        next?.focus();
    }

    close() {
        this.panelTarget.hidden = true;
        this.inputTarget.setAttribute('aria-expanded', 'false');
        this.inputTarget.removeAttribute('aria-activedescendant');
    }
}
