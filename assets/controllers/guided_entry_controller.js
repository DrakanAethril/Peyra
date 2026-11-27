import { Controller } from '@hotwired/stimulus';

/*
 * The guided entry's form (design-ux.md § 3.2, « Saisie guidée »): as it is filled, the entry it
 * makes is asked again from the server and drawn in the Turbo frame below (a quarter of a second
 * after the last change: the amount and the date fields write their hidden value on leaving);
 * choosing another template reloads the page for its fields.
 */
export default class extends Controller {
    static values = { previewUrl: String };

    connect() {
        this.last = this.query();
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    pick(event) {
        event.stopPropagation();
        event.target.form?.requestSubmit();
    }

    changed(event) {
        if (event.target?.getAttribute('form') === 'guided-pick') {
            return;
        }
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.refresh(), 250);
    }

    refresh() {
        const frame = document.getElementById('guided-preview');
        if (!frame) {
            return;
        }
        const query = this.query();
        if (query === this.last) {
            // A field left unchanged (the focus moving): the entry shown is still the right one.
            return;
        }
        this.last = query;
        frame.setAttribute('src', `${this.previewUrlValue}?${query}`);
    }

    query() {
        const data = new FormData(this.element);
        data.delete('_token');

        return new URLSearchParams(data).toString();
    }
}
