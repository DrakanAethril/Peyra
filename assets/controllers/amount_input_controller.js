import { Controller } from '@hotwired/stimulus';

/*
 * The amount field (design-ui.md § 4.5, Px:AmountInput). The visible field is a display: while
 * typing it keeps digits, a comma (a point becomes one), spaces, a leading minus and the operators
 * + − * / ; on leaving it (or on Entrée) the text is evaluated - exactly, in fractions of BigInt -
 * rounded to the cent (half away from zero), written back as « 2 622,00 » and the whole cents go to
 * the hidden field the server reads. A single number follows App\Service\Shared\AmountParser's
 * rules (« 1.234,56 », « 1 234,56 € »); what cannot be read keeps its text, empties the hidden
 * field and is marked invalid, so the form does not leave with a wrong amount.
 *
 * It dispatches `amount-input:change` with `{ cents }` (null when empty or unreadable).
 */
const THIN = ' ';
const NBSP = ' ';
const MINUS = '−';
const SPACES = /[\s   ]/g;

export default class extends Controller {
    static targets = ['visible', 'cents'];

    selectAll() {
        this.visibleTarget.select();
    }

    filter() {
        const field = this.visibleTarget;
        const before = field.value;
        const cleaned = before.replace(/\./g, ',').replace(MINUS, '-').replace(/[^0-9,+\-*/\s  €]/g, '');
        if (cleaned !== before) {
            const caret = field.selectionStart ?? cleaned.length;
            field.value = cleaned;
            const shift = before.length - cleaned.length;
            field.setSelectionRange(Math.max(0, caret - shift), Math.max(0, caret - shift));
        }
        this.mark(null);
    }

    commit(event) {
        if (event?.type === 'keydown') {
            // Entrée evaluates the field without submitting the form it sits in.
            event.preventDefault();
        }
        const text = this.visibleTarget.value;
        if (text.replace(SPACES, '') === '') {
            this.centsTarget.value = '';
            this.mark(null);
            this.announce(null);

            return;
        }
        const cents = evaluate(text);
        if (cents === null) {
            this.centsTarget.value = '';
            this.mark('Ce montant n’est pas lisible. Écrivez par exemple 1 234,56.');
            this.announce(null);

            return;
        }
        this.visibleTarget.value = format(cents);
        this.centsTarget.value = cents.toString();
        this.mark(null);
        this.announce(cents);
    }

    mark(message) {
        this.visibleTarget.setCustomValidity(message ?? '');
        if (message) {
            this.visibleTarget.setAttribute('aria-invalid', 'true');
        } else {
            this.visibleTarget.removeAttribute('aria-invalid');
        }
    }

    announce(cents) {
        this.dispatch('change', { detail: { cents: cents === null ? null : Number(cents) } });
    }
}

/** « 1 234,56 » from whole cents (a BigInt): U+202F between thousands, « − » and U+00A0 before. */
export function format(cents) {
    const negative = cents < 0n;
    const absolute = negative ? -cents : cents;
    const euros = (absolute / 100n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, THIN);
    const text = `${euros},${(absolute % 100n).toString().padStart(2, '0')}`;

    return negative ? `${MINUS}${NBSP}${text}` : text;
}

/** Whole cents (BigInt) of a typed amount or expression, or null when it cannot be read. */
export function evaluate(input) {
    const text = input.replace(SPACES, '').replace(/€$/, '').replace(new RegExp(MINUS, 'g'), '-');
    if (/^[+-]?[0-9.,]+$/.test(text)) {
        return single(text);
    }
    if (!/^[0-9,+\-*/().]+$/.test(text)) {
        return null;
    }
    try {
        const parser = new Expression(text.replace(/\./g, ','));
        const value = parser.parse();

        return value === null ? null : toCents(value);
    } catch {
        return null;
    }
}

/** One number, by AmountParser's rules: a lone separator is the decimal one, both = thousands. */
function single(text) {
    let sign = 1n;
    let body = text;
    if (body[0] === '-' || body[0] === '+') {
        sign = body[0] === '-' ? -1n : 1n;
        body = body.slice(1);
    }
    if (!/[0-9]/.test(body)) {
        return null;
    }
    const lastComma = body.lastIndexOf(',');
    const lastPoint = body.lastIndexOf('.');
    let integer = body;
    let decimals = '';
    if (lastComma >= 0 && lastPoint >= 0) {
        const decimal = lastComma > lastPoint ? ',' : '.';
        const at = body.lastIndexOf(decimal);
        integer = grouped(body.slice(0, at), decimal === ',' ? '.' : ',');
        decimals = body.slice(at + 1);
    } else if (lastComma >= 0 || lastPoint >= 0) {
        const separator = lastComma >= 0 ? ',' : '.';
        if (body.split(separator).length > 2) {
            integer = grouped(body, separator);
        } else {
            [integer, decimals] = body.split(separator);
        }
    }
    if (integer === null || !/^[0-9]*$/.test(integer) || !/^[0-9]*$/.test(decimals) || decimals.length > 2) {
        return null;
    }
    const digits = integer.replace(/^0+/, '');
    if (digits.length > 12) {
        return null;
    }

    return sign * (BigInt(digits || '0') * 100n + BigInt(decimals.padEnd(2, '0')));
}

function grouped(text, separator) {
    const groups = text.split(separator);
    const head = groups.shift();
    if (!/^[0-9]{1,3}$/.test(head) || groups.some((group) => !/^[0-9]{3}$/.test(group))) {
        return null;
    }

    return head + groups.join('');
}

/** An exact fraction rounded to whole cents, half away from zero; null beyond 12 integer digits. */
function toCents({ n, d }) {
    let num = n * 100n;
    let den = d;
    if (den < 0n) {
        num = -num;
        den = -den;
    }
    const negative = num < 0n;
    const absolute = negative ? -num : num;
    let cents = absolute / den;
    if ((absolute % den) * 2n >= den) {
        cents += 1n;
    }
    if (cents >= 100000000000000n) {
        return null;
    }

    return negative ? -cents : cents;
}

/** + − * / and parentheses over decimal numbers, with the usual precedence, as exact fractions. */
class Expression {
    constructor(text) {
        this.text = text;
        this.at = 0;
    }

    parse() {
        const value = this.sum();
        if (this.at !== this.text.length) {
            throw new Error('trailing');
        }

        return value;
    }

    sum() {
        let value = this.product();
        while (this.peek() === '+' || this.peek() === '-') {
            const operator = this.text[this.at++];
            const right = this.product();
            value = operator === '+' ? add(value, right) : add(value, neg(right));
        }

        return value;
    }

    product() {
        let value = this.unary();
        while (this.peek() === '*' || this.peek() === '/') {
            const operator = this.text[this.at++];
            const right = this.unary();
            if (operator === '/' && right.n === 0n) {
                throw new Error('division by zero');
            }
            value = operator === '*' ? { n: value.n * right.n, d: value.d * right.d } : { n: value.n * right.d, d: value.d * right.n };
        }

        return value;
    }

    unary() {
        if (this.peek() === '-') {
            this.at++;

            return neg(this.unary());
        }
        if (this.peek() === '+') {
            this.at++;

            return this.unary();
        }
        if (this.peek() === '(') {
            this.at++;
            const value = this.sum();
            if (this.text[this.at++] !== ')') {
                throw new Error('parenthesis');
            }

            return value;
        }

        return this.number();
    }

    number() {
        const match = /^([0-9]+)(?:,([0-9]+))?/.exec(this.text.slice(this.at));
        if (!match) {
            throw new Error('number');
        }
        this.at += match[0].length;
        const decimals = match[2] ?? '';

        return { n: BigInt(match[1] + decimals), d: 10n ** BigInt(decimals.length) };
    }

    peek() {
        return this.text[this.at];
    }
}

function add(a, b) {
    return { n: a.n * b.d + b.n * a.d, d: a.d * b.d };
}

function neg(a) {
    return { n: -a.n, d: a.d };
}
