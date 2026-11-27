/*
 * The entry grid's arithmetic and lookups (design-ux.md § 5.2), apart from the DOM so that the
 * controller only draws and moves the focus. Amounts are whole cents (Numbers: an entry never
 * nears 2^53 cents); days are ISO strings (« 2026-01-12 »), never Date objects with a time zone.
 */
import { evaluate, format } from '../controllers/amount_input_controller.js';
import { complete } from '../controllers/date_input_controller.js';

export const FIELDS = ['day', 'document', 'account', 'thirdParty', 'label', 'debit', 'credit', 'due'];

/** « 2 622,00 », empty for zero (a debit or credit cell). */
export function cell(cents) {
    return cents ? format(BigInt(cents)) : '';
}

/** « 2 622,00 », « 0,00 » for zero (a total). */
export function amount(cents) {
    return format(BigInt(cents));
}

/** « 3 248,40 C », « 3 248,40 D », or « 0,00 ». */
export function balance(cents) {
    if (cents === 0) {
        return '0,00';
    }

    return `${format(BigInt(Math.abs(cents)))} ${cents > 0 ? 'D' : 'C'}`;
}

/** Whole cents of a typed amount (« 2622 », « 1 234,56 », « 100+20 »), or null when unreadable. */
export function parseAmount(text) {
    if (text.replace(/[\s  ]/g, '') === '') {
        return 0;
    }
    const cents = evaluate(text);

    return cents === null ? null : Number(cents);
}

/**
 * The day typed in the Jour cell: « 12 » is the 12th of the month entered, « 1211 » the 12/11 of
 * the exercise, « 12/11/2026 » a full date. Null when it is not a real day.
 */
export function parseDay(text, periodStart, exercise) {
    const reference = new Date(`${periodStart}T00:00:00Z`);
    const date = complete(text.trim(), reference);
    if (date === null) {
        return null;
    }
    let day = isoOf(date);
    const short = /^\d{3,4}$/.test(text.trim()) || /^\d{1,2}[/.\-\s]\d{1,2}$/.test(text.trim());
    if (short && (day < exercise.start || day > exercise.end)) {
        // « 1211 » in an exercise across two years: the 12/11 that falls inside it.
        for (const shift of [1, -1]) {
            const other = `${Number(day.slice(0, 4)) + shift}${day.slice(4)}`;
            if (other >= exercise.start && other <= exercise.end && isoOf(new Date(`${other}T00:00:00Z`)) === other) {
                day = other;
                break;
            }
        }
    }

    return day;
}

/** « 12 » when the day is in the month shown, « 12/02 » otherwise, « 12/02/2027 » in another year. */
export function dayLabel(iso, periodStart) {
    if (!iso) {
        return '';
    }
    if (iso.slice(0, 7) === periodStart.slice(0, 7)) {
        return iso.slice(8, 10);
    }
    if (iso.slice(0, 4) === periodStart.slice(0, 4)) {
        return `${iso.slice(8, 10)}/${iso.slice(5, 7)}`;
    }

    return `${iso.slice(8, 10)}/${iso.slice(5, 7)}/${iso.slice(0, 4)}`;
}

/** « 12/02/2026 ». */
export function frenchDate(iso) {
    return iso ? `${iso.slice(8, 10)}/${iso.slice(5, 7)}/${iso.slice(0, 4)}` : '';
}

export function isoOf(date) {
    return date.toISOString().slice(0, 10);
}

/** The next document of a series: « FA-2601-0012 » → « FA-2601-0013 », the width kept. */
export function nextDocument(document) {
    const match = /^(.*?)(\d+)(\D*)$/.exec(document ?? '');
    if (!match) {
        return document ?? '';
    }
    const next = String(Number(match[2]) + 1).padStart(match[2].length, '0');

    return `${match[1]}${next}${match[3]}`;
}

export function blankLine(label = '') {
    return { account: '', thirdParty: '', label, debit: 0, credit: 0, due: null };
}

/** Nothing typed on the line but, perhaps, the label it carries from the line above. */
export function isBlank(line) {
    return !line.account && !line.thirdParty && !line.debit && !line.credit && !line.due;
}

/** Debit and credit of the lines typed (blank ones count for nothing). */
export function totals(lines) {
    let debit = 0;
    let credit = 0;
    for (const line of lines) {
        debit += line.debit || 0;
        credit += line.credit || 0;
    }

    return { debit, credit };
}

/**
 * The accounts and third parties of the dossier, for the Compte cell (design-ux.md § 5.2): digits
 * filter by the start of the number, letters by auxiliary code, then by words of the label or of
 * the name (no accents, no case).
 */
export class Lookup {
    constructor(data) {
        this.accounts = data.accounts ?? [];
        this.thirdParties = data.thirdParties ?? [];
        this.byNumber = new Map(this.accounts.map((account) => [account.n, account]));
        this.byCode = new Map();
        for (const party of this.thirdParties) {
            this.byCode.set(party.c, party);
        }
        for (const party of this.thirdParties) {
            if (party.a) {
                this.byCode.set(party.a, party);
            }
        }
    }

    add(account) {
        this.accounts.push(account);
        this.accounts.sort((a, b) => (a.n < b.n ? -1 : 1));
        this.byNumber.set(account.n, account);
    }

    account(number) {
        return this.byNumber.get(number) ?? null;
    }

    /** A third party by its auxiliary code or its own code. */
    thirdParty(code) {
        return this.byCode.get(code) ?? null;
    }

    /**
     * What the Compte cell holds, resolved: {account, thirdParty, auxiliary} - the account of an
     * auxiliary code is its third party's collective. Null when nothing answers to the text.
     */
    resolve(text) {
        const typed = text.replace(/\s+/g, '').toUpperCase();
        if (typed === '') {
            return null;
        }
        if (/^\d+$/.test(typed)) {
            const account = this.account(typed);

            return account ? { account, thirdParty: null, auxiliary: false } : null;
        }
        const party = this.byCode.get(typed);
        if (!party || !party.a || party.a !== typed) {
            return null;
        }
        const account = this.account(party.k);

        return account ? { account, thirdParty: party, auxiliary: true } : null;
    }

    /**
     * The options for a text: [{value, label, balance, kind}], accounts first. `cls` narrows to a
     * class of accounts (« 6 ») or the third parties (« T »).
     */
    search(text, limit = 8, cls = null) {
        const typed = text.replace(/\s+/g, '').toUpperCase();
        const words = fold(text).split(/\s+/).filter((word) => word !== '');
        const options = [];
        const isNumber = /^\d+$/.test(typed);
        if (cls !== 'T') {
            for (const account of this.accounts) {
                if (cls && account.n[0] !== cls) {
                    continue;
                }
                const hit = typed === '' || (isNumber ? account.n.startsWith(typed) : words.every((word) => fold(account.l).includes(word)));
                if (hit) {
                    options.push({ value: account.n, label: account.l, balance: account.b, kind: 'account' });
                    if (options.length >= limit) {
                        return options;
                    }
                }
            }
        }
        if (!isNumber && (cls === null || cls === 'T')) {
            const first = [];
            const then = [];
            for (const party of this.thirdParties) {
                if (!party.a) {
                    continue;
                }
                if (typed !== '' && party.a.startsWith(typed)) {
                    first.push(party);
                } else if (typed === '' || words.every((word) => fold(`${party.n} ${party.c}`).includes(word))) {
                    then.push(party);
                }
            }
            for (const party of [...first, ...then]) {
                options.push({ value: party.a, label: `${party.n} · ${party.k}`, balance: party.b, kind: 'thirdParty' });
                if (options.length >= limit) {
                    break;
                }
            }
        }

        return options;
    }

    /** Third parties for the Tiers cell of a collective account typed by number. */
    searchThirdParties(text, limit = 8) {
        return this.search(text, limit, 'T');
    }
}

export function fold(text) {
    return (text ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}
