import { Controller } from '@hotwired/stimulus';

/*
 * The date field (design-ui.md § 4.5, Px:DateInput). Nothing here reads the computer's clock: the
 * reference is the dossier's date, sent by the server.
 *
 * Typing, completed on leaving the field (or on Entrée):
 * - « 12 » → the 12th of the reference's month; « 1211 » or « 12/11 » → 12/11 of its year;
 * - « 121126 », « 12/11/26 » → 2026; « 12112026 », « 12/11/2026 » as written;
 * - « + » and « − » move the date by a day (from the reference when the field is empty).
 * A day that does not exist keeps its text, empties the hidden field and is marked invalid.
 *
 * The calendar (button inside the field): a month grid, « Date du dossier » to go back to the
 * reference; ← → ↑ ↓ move by a day or a week, Page↑ Page↓ by a month, Entrée chooses, Échap closes
 * and gives the focus back to the field.
 */
const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
const DAYS = [['lun.', 'lundi'], ['mar.', 'mardi'], ['mer.', 'mercredi'], ['jeu.', 'jeudi'], ['ven.', 'vendredi'], ['sam.', 'samedi'], ['dim.', 'dimanche']];

export default class extends Controller {
    static targets = ['visible', 'iso', 'button', 'panel'];
    static values = { reference: String };

    connect() {
        this.onOutside = (event) => {
            if (!this.element.contains(event.target)) {
                this.close(false);
            }
        };
        document.addEventListener('pointerdown', this.onOutside);
    }

    disconnect() {
        document.removeEventListener('pointerdown', this.onOutside);
    }

    selectAll() {
        this.visibleTarget.select();
    }

    keydown(event) {
        if (event.key === '+' || event.key === '-' || event.key === '−') {
            event.preventDefault();
            const current = this.current() ?? this.reference();
            this.choose(addDays(current, event.key === '+' ? 1 : -1), false);
            this.visibleTarget.select();

            return;
        }
        if (event.key === 'Enter') {
            event.preventDefault();
            this.commit();

            return;
        }
        if (event.key === 'ArrowDown' && event.altKey) {
            event.preventDefault();
            this.open();
        }
    }

    commit() {
        const text = this.visibleTarget.value.trim();
        if (text === '') {
            this.isoTarget.value = '';
            this.mark(null);

            return;
        }
        const date = complete(text, this.reference());
        if (date === null) {
            this.isoTarget.value = '';
            this.mark('Cette date n’existe pas. Écrivez-la JJ/MM/AAAA.');

            return;
        }
        this.choose(date, false);
    }

    choose(date, refocus) {
        this.visibleTarget.value = french(date);
        this.isoTarget.value = iso(date);
        this.mark(null);
        this.dispatch('change', { detail: { date: iso(date) } });
        if (refocus) {
            this.close(true);
        }
    }

    mark(message) {
        this.visibleTarget.setCustomValidity(message ?? '');
        if (message) {
            this.visibleTarget.setAttribute('aria-invalid', 'true');
        } else {
            this.visibleTarget.removeAttribute('aria-invalid');
        }
    }

    // ------------------------------------------------------------------------------- calendar

    toggle() {
        if (this.panelTarget.hidden) {
            this.open();
        } else {
            this.close(true);
        }
    }

    open() {
        this.focused = this.current() ?? this.reference();
        this.render();
        this.panelTarget.hidden = false;
        this.buttonTarget.setAttribute('aria-expanded', 'true');
        this.focusDay();
    }

    close(refocus) {
        if (this.panelTarget.hidden) {
            return;
        }
        this.panelTarget.hidden = true;
        this.buttonTarget.setAttribute('aria-expanded', 'false');
        if (refocus) {
            this.visibleTarget.focus();
        }
    }

    calendarKeydown(event) {
        const moves = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            this.close(true);
        } else if (event.key in moves && event.target.dataset.day) {
            event.preventDefault();
            this.move(addDays(this.focused, moves[event.key]));
        } else if ((event.key === 'PageUp' || event.key === 'PageDown') && event.target.dataset.day) {
            event.preventDefault();
            this.move(addMonths(this.focused, event.key === 'PageUp' ? -1 : 1));
        }
    }

    pick(event) {
        this.choose(parseIso(event.currentTarget.dataset.day), true);
    }

    previousMonth() {
        this.move(addMonths(this.focused, -1), false);
    }

    nextMonth() {
        this.move(addMonths(this.focused, 1), false);
    }

    today() {
        this.choose(this.reference(), true);
    }

    move(date, focusDay = true) {
        this.focused = date;
        this.render();
        if (focusDay) {
            this.focusDay();
        }
    }

    focusDay() {
        this.panelTarget.querySelector(`[data-day="${iso(this.focused)}"]`)?.focus();
    }

    render() {
        const year = this.focused.getUTCFullYear();
        const month = this.focused.getUTCMonth();
        const first = new Date(Date.UTC(year, month, 1));
        const start = addDays(first, -((first.getUTCDay() + 6) % 7));
        const selected = this.isoTarget.value;
        const reference = this.referenceValue;
        const title = `${MONTHS[month]} ${year}`;

        const head = DAYS.map(([short, long]) => `<th scope="col"><abbr title="${long}">${short}</abbr></th>`).join('');
        let rows = '';
        for (let week = 0; week < 6; week++) {
            rows += '<tr>';
            for (let day = 0; day < 7; day++) {
                const date = addDays(start, week * 7 + day);
                const key = iso(date);
                const outside = date.getUTCMonth() !== month;
                const focused = key === iso(this.focused);
                rows += `<td><button type="button" class="px-calendar__day${outside ? ' px-calendar__day--outside' : ''}${key === reference ? ' px-calendar__day--reference' : ''}"`
                    + ` data-day="${key}" data-action="date-input#pick" tabindex="${focused ? 0 : -1}"`
                    + `${key === selected ? ' aria-pressed="true"' : ' aria-pressed="false"'}`
                    + ` aria-label="${date.getUTCDate()} ${MONTHS[date.getUTCMonth()]} ${date.getUTCFullYear()}${key === reference ? ', date du dossier' : ''}">${date.getUTCDate()}</button></td>`;
            }
            rows += '</tr>';
        }

        this.panelTarget.innerHTML = `
            <div class="px-calendar__head">
                <button type="button" class="px-calendar__nav" data-action="date-input#previousMonth" aria-label="Mois précédent">‹</button>
                <span class="px-calendar__title" aria-live="polite">${title}</span>
                <button type="button" class="px-calendar__nav" data-action="date-input#nextMonth" aria-label="Mois suivant">›</button>
            </div>
            <table class="px-calendar__grid" role="grid" aria-label="${title}"><thead><tr>${head}</tr></thead><tbody>${rows}</tbody></table>
            <div class="px-calendar__foot">
                <button type="button" class="px-calendar__reference" data-action="date-input#today">Date du dossier · ${french(this.reference())}</button>
            </div>`;
    }

    // ------------------------------------------------------------------------------- dates

    reference() {
        return parseIso(this.referenceValue);
    }

    current() {
        return this.isoTarget.value ? parseIso(this.isoTarget.value) : null;
    }
}

/** A typed date completed against the reference (a UTC Date), or null when it is not a real day. */
export function complete(text, reference) {
    let day;
    let month = reference.getUTCMonth() + 1;
    let year = reference.getUTCFullYear();
    const parts = text.split(/[/.\-\s]+/).filter((part) => part !== '');
    if (parts.length > 1) {
        if (parts.length > 3 || parts.some((part) => !/^[0-9]+$/.test(part))) {
            return null;
        }
        [day, month, year] = [Number(parts[0]), Number(parts[1]), parts[2] === undefined ? year : expandYear(parts[2])];
    } else {
        const digits = parts[0] ?? '';
        if (!/^[0-9]+$/.test(digits)) {
            return null;
        }
        if (digits.length <= 2) {
            day = Number(digits);
        } else if (digits.length === 4) {
            [day, month] = [Number(digits.slice(0, 2)), Number(digits.slice(2))];
        } else if (digits.length === 6 || digits.length === 8) {
            [day, month, year] = [Number(digits.slice(0, 2)), Number(digits.slice(2, 4)), expandYear(digits.slice(4))];
        } else {
            return null;
        }
    }
    if (year === null) {
        return null;
    }
    const date = new Date(Date.UTC(year, month - 1, day));

    return date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day ? date : null;
}

function expandYear(text) {
    if (text.length === 4) {
        return Number(text);
    }

    return text.length === 2 ? 2000 + Number(text) : null;
}

function parseIso(text) {
    const [year, month, day] = text.split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, day));
}

function addDays(date, days) {
    return new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), date.getUTCDate() + days));
}

function addMonths(date, months) {
    const target = new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth() + months, 1));
    const last = new Date(Date.UTC(target.getUTCFullYear(), target.getUTCMonth() + 1, 0)).getUTCDate();

    return new Date(Date.UTC(target.getUTCFullYear(), target.getUTCMonth(), Math.min(date.getUTCDate(), last)));
}

function iso(date) {
    return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}-${String(date.getUTCDate()).padStart(2, '0')}`;
}

function french(date) {
    return `${String(date.getUTCDate()).padStart(2, '0')}/${String(date.getUTCMonth() + 1).padStart(2, '0')}/${date.getUTCFullYear()}`;
}
