import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';
import { labelOf } from '../shortcuts/registry.js';

/*
 * The global search (Ctrl K, design-ux.md § 2.1; Px:CommandPalette). Opened by the top bar's
 * trigger (data-px-open, or Ctrl K through the shortcut registry) on an empty field: the recent
 * screens. Each key typed asks the server again 150 ms after the last one, through the Turbo Frame
 * `px-cmdk-results` (q, and here: the screen the search is made from). ↑ ↓ choose, Entrée opens
 * the chosen line, Ctrl Entrée opens it in a new tab, Échap closes (the native dialog's cancel).
 * Entrée typed before the results came waits for them: « Ctrl K, ach, Entrée » opens the purchases'
 * grid however fast it is typed (design-ux.md § 4.4). A line that is also a rail entry shows its
 * Alt key, read from the rail itself. Closed without opening anything, the focus goes back where it
 * was - or, when the screen redrew that element, the screen is asked (px:focus-return).
 */
export default class extends Controller {
    static targets = ['input', 'frame'];
    static values = { src: String };

    connect() {
        this.options = [];
        this.active = -1;
        this.loading = null;
        this.pending = null;
        this.beforeCache = () => this.close();
        document.addEventListener('turbo:before-cache', this.beforeCache);
        // Closed without opening anything (Échap, the backdrop): the focus goes back where it was.
        // A screen that redrew the element (the entry grid leaves its line) is asked to put it back.
        this.element.addEventListener('close', () => {
            if (this.leaving || !(this.opener instanceof HTMLElement)) {
                return;
            }
            if (this.opener.isConnected) {
                this.opener.focus();
            } else if (this.openerHost?.isConnected) {
                this.openerHost.dispatchEvent(new CustomEvent('px:focus-return'));
            }
        });
    }

    disconnect() {
        clearTimeout(this.timer);
        document.removeEventListener('turbo:before-cache', this.beforeCache);
    }

    open() {
        this.opener = document.activeElement;
        this.openerHost = this.opener instanceof Element ? this.opener.closest('[data-controller]') : null;
        this.leaving = false;
        this.inputTarget.value = '';
        this.pending = null;
        if (!this.element.open) {
            this.element.showModal();
        }
        this.inputTarget.focus();
        this.load();
    }

    close() {
        clearTimeout(this.timer);
        this.timer = null;
        this.pending = null;
        if (this.element.open) {
            this.element.close();
        }
    }

    /** A click on the backdrop (the dialog element itself, outside its box) closes it. */
    backdrop(event) {
        if (event.target === this.element) {
            this.close();
        }
    }

    type() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.load(), 150);
    }

    load() {
        clearTimeout(this.timer);
        this.timer = null;
        const url = new URL(this.srcValue, window.location.href);
        url.searchParams.set('q', this.inputTarget.value.trim());
        url.searchParams.set('here', window.location.pathname + window.location.search);
        this.loading = url.toString();
        this.frameTarget.setAttribute('aria-busy', 'true');
        if (this.frameTarget.getAttribute('src') === this.loading) {
            this.frameTarget.reload();
        } else {
            this.frameTarget.setAttribute('src', this.loading);
        }
    }

    loaded() {
        if (this.frameTarget.getAttribute('src') !== this.loading) {
            return;
        }
        this.loading = null;
        this.frameTarget.removeAttribute('aria-busy');
        this.options = [...this.frameTarget.querySelectorAll('[role="option"]')];
        this.railKeys();
        this.choose(0);
        if (this.pending !== null) {
            const { newTab } = this.pending;
            this.pending = null;
            this.go(newTab);
        }
    }

    key(event) {
        const modified = event.ctrlKey || event.metaKey;
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            this.choose(this.active + (event.key === 'ArrowDown' ? 1 : -1));
        } else if (event.key === 'Enter' && !event.altKey && !event.shiftKey) {
            event.preventDefault();
            this.go(modified);
        } else if (modified && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            this.inputTarget.select();
        }
    }

    /** A click on a line: the page follows it (the frame's target is _top), the palette closes. */
    follow(event) {
        const option = event.target instanceof Element ? event.target.closest('[role="option"]') : null;
        if (option && !event.ctrlKey && !event.metaKey && !event.shiftKey) {
            this.leaving = true;
            this.close();
        }
    }

    go(newTab) {
        if (this.timer !== null && this.timer !== undefined) {
            this.load();
        }
        if (this.loading !== null) {
            this.pending = { newTab };

            return;
        }
        const option = this.options[this.active];
        if (!option) {
            return;
        }
        const href = option.getAttribute('href');
        this.leaving = !newTab;
        this.close();
        if (newTab) {
            window.open(href, '_blank', 'noopener');
        } else {
            Turbo.visit(href);
        }
    }

    choose(index) {
        if (this.options.length === 0) {
            this.active = -1;
            this.inputTarget.removeAttribute('aria-activedescendant');

            return;
        }
        this.active = Math.max(0, Math.min(index, this.options.length - 1));
        this.options.forEach((option, i) => option.setAttribute('aria-selected', String(i === this.active)));
        const option = this.options[this.active];
        this.inputTarget.setAttribute('aria-activedescendant', option.id);
        option.scrollIntoView({ block: 'nearest' });
    }

    /** « Alt 2 » beside a line the rail also opens (the rail's order decides the key). */
    railKeys() {
        const entries = [...document.querySelectorAll('.px-rail [data-px-rail-entry]')].slice(0, 10);
        const keys = new Map(entries.map((link, index) => [this.address(link.getAttribute('href')), `Alt+${(index + 1) % 10}`]));
        for (const option of this.options) {
            const combo = keys.get(this.address(option.getAttribute('href')));
            const aside = option.querySelector('[data-command-palette-aside]');
            if (combo && aside && aside.textContent.trim() === '') {
                const key = document.createElement('kbd');
                key.className = 'px-kbd';
                key.textContent = labelOf(combo);
                aside.replaceChildren(key);
            }
        }
    }

    address(href) {
        if (!href) {
            return '';
        }
        const url = new URL(href, window.location.href);

        return url.pathname + url.search;
    }
}
