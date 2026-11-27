import { Controller } from '@hotwired/stimulus';
import {
    ALIASES,
    GLOBAL_SHORTCUTS,
    OUTSIDE_FIELDS,
    comboOf,
    isNever,
    isTextField,
    labelOf,
} from '../shortcuts/registry.js';

/*
 * Global keyboard shortcuts, on <body> (design-ux.md § 5). One listener for the whole page:
 * - global gestures: rail entries (Alt 1…0), rail folding (Ctrl B), module tabs (Ctrl Alt ← →),
 *   the shortcut sheet (? or F1), Escape for an open drawer;
 * - every element that declares data-px-shortcut="F10 Ctrl+S" is clicked (or focused, for a field)
 *   when its combination is pressed - the screen says which element answers, the registry knows how;
 * - [data-px-open="id"] opens the dialog or drawer of that id (a px:open event it listens to).
 * Inside an open modal dialog, only the dialog's own elements answer.
 */
export default class extends Controller {
    static values = { sheet: { type: String, default: 'px-shortcuts' } };

    connect() {
        this.onKeydown = this.onKeydown.bind(this);
        this.onClick = this.onClick.bind(this);
        window.addEventListener('keydown', this.onKeydown);
        document.addEventListener('click', this.onClick);
    }

    disconnect() {
        window.removeEventListener('keydown', this.onKeydown);
        document.removeEventListener('click', this.onClick);
    }

    onClick(event) {
        const opener = event.target instanceof Element ? event.target.closest('[data-px-open]') : null;
        if (!opener) {
            return;
        }
        const target = document.getElementById(opener.dataset.pxOpen);
        if (target) {
            event.preventDefault();
            target.dispatchEvent(new CustomEvent('px:open', { detail: { opener } }));
        }
    }

    onKeydown(event) {
        if (event.defaultPrevented || event.isComposing) {
            return;
        }
        const combo = comboOf(event);
        if (combo === null || isNever(combo)) {
            return;
        }
        const inField = isTextField(event.target);
        if (inField && OUTSIDE_FIELDS.has(combo)) {
            return;
        }
        if (this.handle(combo, event)) {
            event.preventDefault();
        }
    }

    handle(combo, event) {
        const scope = this.shortcutScope();
        const target = ALIASES[combo] ?? combo;

        // An element of the screen that answers this combination wins over a global gesture.
        if (this.trigger(target, scope)) {
            return true;
        }
        if (scope !== document) {
            return false;
        }

        if (target === '?') {
            this.openSheet();

            return true;
        }
        if (combo === 'Ctrl+B') {
            document.dispatchEvent(new CustomEvent('px:rail-toggle'));

            return true;
        }
        const altDigit = /^Alt\+([0-9])$/.exec(combo);
        if (altDigit) {
            const index = altDigit[1] === '0' ? 9 : Number(altDigit[1]) - 1;

            return this.follow(document.querySelectorAll('.px-rail [data-px-rail-entry]')[index]);
        }
        if (combo === 'Ctrl+Alt+ArrowLeft' || combo === 'Ctrl+Alt+ArrowRight') {
            return this.neighbourTab(combo.endsWith('Right') ? 1 : -1);
        }
        if (combo === 'Escape') {
            return this.closeTopDrawer(event);
        }

        return false;
    }

    /** The modal dialog that is open, if any: shortcuts stay inside it. */
    shortcutScope() {
        const dialogs = [...document.querySelectorAll('dialog[open]')].filter((dialog) => dialog.matches(':modal'));

        return dialogs.at(-1) ?? document;
    }

    trigger(combo, scope) {
        const candidates = [...scope.querySelectorAll('[data-px-shortcut]')]
            .filter((element) => element.dataset.pxShortcut.split(' ').includes(combo))
            .filter((element) => this.isUsable(element));
        const element = candidates[0];
        if (!element) {
            return false;
        }
        if (isTextField(element)) {
            element.focus();
            if (element instanceof HTMLInputElement) {
                element.select();
            }
        } else {
            element.click();
        }

        return true;
    }

    isUsable(element) {
        if (element.closest('[hidden], [inert]')) {
            return false;
        }
        if (element.disabled || element.getAttribute('aria-disabled') === 'true') {
            return false;
        }

        return element.getClientRects().length > 0;
    }

    follow(link) {
        if (!(link instanceof HTMLElement)) {
            return false;
        }
        link.click();

        return true;
    }

    neighbourTab(step) {
        const tabs = [...document.querySelectorAll('.px-moduletabs a')];
        const current = tabs.findIndex((tab) => tab.getAttribute('aria-current') === 'page');
        if (current === -1) {
            return false;
        }

        return this.follow(tabs[current + step]);
    }

    closeTopDrawer(event) {
        const drawers = [...document.querySelectorAll('.px-drawer.is-open')];
        const drawer = drawers.at(-1);
        if (!drawer) {
            return false;
        }
        drawer.dispatchEvent(new CustomEvent('px:close', { detail: { originalEvent: event } }));

        return true;
    }

    /** Fills the « Raccourcis de cet écran » drawer from the page, then opens it. */
    openSheet() {
        const sheet = document.getElementById(this.sheetValue);
        if (!sheet) {
            return;
        }
        const list = sheet.querySelector('[data-px-shortcut-list="screen"]');
        if (list) {
            list.replaceChildren(...this.screenShortcuts().flatMap(({ combo, label }) => this.row(combo, label)));
            list.closest('section').hidden = list.childElementCount === 0;
        }
        const globals = sheet.querySelector('[data-px-shortcut-list="global"]');
        if (globals && globals.childElementCount === 0) {
            globals.replaceChildren(...GLOBAL_SHORTCUTS.flatMap(({ combo, label }) => this.row(combo, label)));
        }
        sheet.dispatchEvent(new CustomEvent('px:open'));
    }

    screenShortcuts() {
        const seen = new Set();
        const rows = [];
        for (const element of document.querySelectorAll('main [data-px-shortcut], .px-actionfoot [data-px-shortcut]')) {
            if (!this.isUsable(element)) {
                continue;
            }
            const combo = element.dataset.pxShortcut.split(' ')[0];
            if (seen.has(combo)) {
                continue;
            }
            seen.add(combo);
            rows.push({ combo, label: this.describe(element) });
        }

        return rows;
    }

    describe(element) {
        if (element.dataset.pxShortcutLabel) {
            return element.dataset.pxShortcutLabel;
        }
        if (element.getAttribute('aria-label')) {
            return element.getAttribute('aria-label');
        }
        if (element.labels?.length) {
            return element.labels[0].textContent.replace(/\s+/g, ' ').trim();
        }
        const clone = element.cloneNode(true);
        clone.querySelectorAll('kbd, svg, .px-u-sr-only').forEach((node) => node.remove());

        return clone.textContent.replace(/\s+/g, ' ').trim();
    }

    row(combo, label) {
        const term = document.createElement('dt');
        const key = document.createElement('kbd');
        key.className = 'px-kbd';
        key.textContent = labelOf(combo);
        term.append(key);
        const description = document.createElement('dd');
        description.textContent = label;

        return [term, description];
    }
}
