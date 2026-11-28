import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';

/*
 * The waiting page of a dossier going back to a restore point (design-ux.md § 4.9, step 3): it
 * asks the server every two seconds whether the return is over - and at once when the dossier's
 * live topic says so -, then reloads itself, and the server sends it on (the dashboard, or the
 * points' list when the return failed).
 */
export default class extends Controller {
    static values = { state: String, page: String };

    connect() {
        this.heard = (event) => {
            if (event.detail?.event === 'restored') {
                this.leave();
            }
        };
        document.addEventListener('peyra:live', this.heard);
        this.schedule();
    }

    disconnect() {
        document.removeEventListener('peyra:live', this.heard);
        window.clearTimeout(this.timer);
        this.left = true;
    }

    schedule() {
        this.timer = window.setTimeout(() => this.ask(), 2000);
    }

    async ask() {
        try {
            const response = await fetch(this.stateValue, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const state = response.ok ? await response.json() : { running: true };
            if (!state.running) {
                this.leave();

                return;
            }
        } catch {
            // The network came back empty: ask again.
        }
        if (!this.left) {
            this.schedule();
        }
    }

    leave() {
        if (this.left) {
            return;
        }
        this.left = true;
        Turbo.visit(this.pageValue, { action: 'replace' });
    }
}
