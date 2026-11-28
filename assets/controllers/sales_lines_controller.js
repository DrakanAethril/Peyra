import { Controller } from '@hotwired/stimulus';

/*
 * The lines of a commercial document's fiche (step 2.3): « Ajouter une ligne » and Inser add one
 * from the template row (its fields named lines[<n>][…], read by the server in the page's order),
 * the cross and Ctrl Suppr remove the current one. An article's reference typed in its cell fills
 * the line's designation, unit, price and rate from the dossier's articles (the page's catalogue),
 * every one still changeable. Nothing is computed here: the totals are the server's, after F10.
 */
export default class extends Controller {
    static targets = ['body', 'template', 'row'];

    static values = { catalogue: Object, next: Number };

    connect() {
        this.next = this.nextValue;
        this.catalogue = this.catalogueValue;
        if (this.rowTargets.length === 0) {
            this.add();
        }
    }

    add(event) {
        event?.preventDefault();
        const html = this.templateTarget.innerHTML.replaceAll('__INDEX__', String(this.next));
        this.next += 1;
        this.bodyTarget.insertAdjacentHTML('beforeend', html);
        if (event) {
            this.rowTargets.at(-1)?.querySelector('input')?.focus();
        }
    }

    remove(event) {
        event.preventDefault();
        this.drop(event.target.closest('tr'));
    }

    drop(row) {
        if (!row) {
            return;
        }
        const at = this.rowTargets.indexOf(row);
        row.remove();
        const neighbour = this.rowTargets[Math.min(at, this.rowTargets.length - 1)];
        if (neighbour) {
            neighbour.querySelector('input')?.focus();
        } else {
            this.add(new Event('add'));
        }
    }

    keydown(event) {
        const ctrl = event.ctrlKey || event.metaKey;
        if (event.key === 'Insert' && !ctrl && !event.altKey) {
            event.preventDefault();
            event.stopPropagation();
            this.add(event);
        } else if (event.key === 'Delete' && ctrl && event.target.closest('tr')) {
            event.preventDefault();
            event.stopPropagation();
            this.drop(event.target.closest('tr'));
        }
    }

    /** The article's reference fills the line from the catalogue. */
    fill(event) {
        const input = event.target;
        const code = input.value.trim().toUpperCase();
        input.value = code;
        const article = this.catalogue[code];
        const row = input.closest('tr');
        if (!article || !row) {
            return;
        }
        for (const [field, value] of Object.entries(article)) {
            const target = row.querySelector(`input[name$="[${field}]"]`);
            if (target) {
                target.value = value;
            }
        }
        const quantity = row.querySelector('input[name$="[quantity]"]');
        if (quantity && quantity.value === '') {
            quantity.value = '1';
        }
    }
}
