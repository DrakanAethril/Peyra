import { Controller } from '@hotwired/stimulus';

/*
 * Picks one account by typing part of a name or a login (CLAUDE.md, « Forms »: picking users uses
 * an autocomplete fed by ajax). The list comes from `url` (?q=…, JSON [{id, name, login}]) 150 ms
 * after the last key, from the second character; ↑ ↓ choose, Entrée takes the option, Échap closes.
 * The chosen id goes to the hidden `value` target, which the form submits.
 */
export default class extends Controller {
    static targets = ['input', 'value', 'panel', 'list'];
    static values = { url: String };

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

    search() {
        this.valueTarget.value = '';
        clearTimeout(this.timer);
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
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: this.request.signal });
            if (!response.ok) {
                return;
            }
            this.show(await response.json());
        } catch (error) {
            if (error.name !== 'AbortError') {
                throw error;
            }
        }
    }

    show(options) {
        this.options = Array.isArray(options) ? options : [];
        this.active = this.options.length > 0 ? 0 : -1;
        this.listTarget.replaceChildren(...this.options.map((option, index) => this.optionElement(option, index)));
        if (this.options.length === 0) {
            const empty = document.createElement('li');
            empty.className = 'px-picker__empty';
            empty.setAttribute('role', 'presentation');
            empty.textContent = 'Aucun enseignant ne correspond.';
            this.listTarget.append(empty);
        }
        this.panelTarget.hidden = false;
        this.inputTarget.setAttribute('aria-expanded', 'true');
        this.highlight();
    }

    optionElement(option, index) {
        const item = document.createElement('li');
        item.className = 'px-picker__option';
        item.id = `${this.listTarget.id}-${index}`;
        item.setAttribute('role', 'option');
        const name = document.createElement('span');
        name.textContent = option.name;
        const login = document.createElement('small');
        login.textContent = option.login;
        item.append(name, login);
        item.addEventListener('pointerdown', (event) => {
            event.preventDefault();
            this.choose(index);
        });

        return item;
    }

    keydown(event) {
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
        } else if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            this.close();
        }
    }

    highlight() {
        [...this.listTarget.querySelectorAll('[role="option"]')].forEach((item, index) => {
            item.setAttribute('aria-selected', index === this.active ? 'true' : 'false');
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
        this.inputTarget.value = option.name;
        this.valueTarget.value = String(option.id);
        this.close();
    }

    close() {
        this.panelTarget.hidden = true;
        this.inputTarget.setAttribute('aria-expanded', 'false');
        this.inputTarget.removeAttribute('aria-activedescendant');
    }
}
