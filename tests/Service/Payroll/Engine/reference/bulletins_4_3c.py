#!/usr/bin/env python3
"""Bulletins de référence de l'étape 4.3c (net, prélèvement à la source, cumuls), calculés à part du
moteur de Peyra : Python, décimal exact, aucun fichier du dépôt lu. Les taux sont recopiés à la main des
données préparées (plan/donnees/reglementaire/social-2025.yaml et social-2026.yaml) ; chaque montant
est base × taux arrondi au centime au demi supérieur, rubrique par rubrique (DECISIONS 4.3b).

Lancer : python3 -I tests/Service/Payroll/Engine/reference/bulletins_4_3c.py
Les montants attendus de PayslipNetTest et PayslipYearToDateTest sont ceux que ce script imprime.
"""
from decimal import Decimal as D, ROUND_HALF_UP, getcontext

getcontext().prec = 40


def r2(x):
    return x.quantize(D('0.01'), rounding=ROUND_HALF_UP)


def r4(x):
    return x.quantize(D('0.0001'), rounding=ROUND_HALF_UP)


def pct(base, rate):
    return r2(base * D(rate) / 100)


def power(x, p):
    # x ** 1.75 = x * sqrt(sqrt(x)) ** 3, en décimal exact (prec 40)
    if x <= 0:
        return D(0)
    q = x.sqrt().sqrt()
    return x * q * q * q if p == D('1.75') else None


YEARS = {
    2026: dict(pss=D('4005'), smic=D('1823.03'), smic_h=D('12.02'), health='13', family='5.25',
               old_uncapped_er='2.11', unemployment='4.00', regime='rgdu'),
    2025: dict(pss=D('3925'), smic=D('1801.80'), smic_h=D('11.88'), health='13', family='5.25',
               old_uncapped_er='2.02', unemployment='4.00', regime='rgcp'),
}
# Grilles mensuelles du taux neutre (BOI-BAREME-000037), métropole : (borne basse incluse, taux).
GRID_MAY_2026 = [(0, '0'), (1635, '0.5'), (1698, '1.3'), (1807, '2.1'), (1928, '2.9'), (2060, '3.5'), (2170, '4.1'),
                 (2315, '5.3'), (2738, '7.5'), (3135, '9.9'), (3571, '11.9'), (4019, '13.8'), (4690, '15.8'),
                 (5624, '17.9'), (7037, '20'), (8789, '24'), (12200, '28'), (16523, '33'), (25937, '38'), (55558, '43')]
GRID_JAN_2026 = [(0, '0'), (1620, '0.5'), (1683, '1.3'), (1791, '2.1'), (1911, '2.9'), (2042, '3.5'), (2151, '4.1'),
                 (2294, '5.3'), (2714, '7.5'), (3107, '9.9'), (3539, '11.9'), (3983, '13.8'), (4648, '15.8'),
                 (5574, '17.9'), (6974, '20'), (8711, '24'), (12091, '28'), (16376, '33'), (25706, '38'), (55062, '43')]


def neutral(grid, base):
    rate = D(0)
    for low, r in grid:
        if base >= low:
            rate = D(r)
    return rate


def bracket(gross, ceiling, lo, hi):
    """Part d'un brut (cumulé) entre lo et hi plafonds (cumulés)."""
    return max(D(0), min(gross, hi * ceiling) - lo * ceiling)


def payslip(case):
    Y = YEARS[case['year']]
    pss = Y['pss']
    salary = D(case['salary'])
    hours = D(case.get('hours', '151.67'))
    hourly = r4(salary / hours)
    rows = {}
    gross_rows = {'1000': salary}
    if 'seniority' in case:
        gross_rows['1010'] = pct(salary, case['seniority'])
    ot = D(0)
    ot_hours = D(0)
    if 'ot25' in case:
        rate = r4(hourly * D('1.25'))
        gross_rows['1100'] = r2(D(case['ot25']) * rate)
        ot += gross_rows['1100']
        ot_hours += D(case['ot25'])
    if 'car' in case:
        gross_rows['1400'] = D(case['car'])
    gross = sum(gross_rows.values(), D(0))
    # Cumuls de l'année avant ce bulletin, puis avec lui.
    before = case.get('before', {})
    g0, c0 = D(before.get('gross', '0')), D(before.get('ceiling', '0'))
    g1, c1 = g0 + gross, c0 + pss

    def tranche(lo, hi):
        return bracket(g1, c1, lo, hi) - bracket(g0, c0, lo, hi)

    t1, t2, t12, t4 = tranche(0, 1), tranche(1, 8), tranche(0, 8), tranche(0, 4)
    executive = case.get('executive', False)

    def add(code, base, ee, er):
        if base == 0:
            return
        rows[code] = (base, ee, pct(base, ee) if ee else D(0), er, pct(base, er) if er else None)

    health, family = Y['health'], Y['family']
    if case['year'] == 2025:   # taux réduits de 2025 : brut ≤ 2,25 (3,3) SMIC de référence du mois
        smic_ref = r2(Y['smic'] * hours / D('151.67')) + r2(Y['smic_h'] * ot_hours)
        health = '7' if gross <= D('2.25') * smic_ref else '13'
        family = '3.45' if gross <= D('3.3') * smic_ref else '5.25'
    add('2000', gross, None, health)
    rows['2010'] = (None, None, D('28.00'), None, D('28.00'))   # complémentaire santé de la démonstration
    if executive:
        add('2050', t1, None, '1.50')
    add('2100', gross, None, '2.10')
    add('2200', t1, '6.90', '8.55')
    add('2210', gross, '0.40', Y['old_uncapped_er'])
    add('2300', t1, '3.15', '4.72')
    add('2310', t1, '0.86', '1.29')
    add('2320', t2, '8.64', '12.95')
    add('2330', t2, '1.08', '1.62')
    if g1 > c1:   # CET : salaire (cumulé) au-dessus du plafond (cumulé)
        add('2340', t12, '0.14', '0.21')
    add('2400', gross, None, family)
    add('2500', t4, None, Y['unemployment'])
    add('2510', t4, None, '0.25')
    if executive:
        add('2520', t4, '0.024', '0.036')
    add('2600', gross, None, '0.30')
    add('2610', t1, None, '0.10')
    add('2630', gross, None, '0.016')
    add('2640', gross, None, '0.55')
    add('2660', gross, None, '0.68')
    # CSG : 98,25 % du brut dans la limite de 4 plafonds du mois + parts patronales santé et prévoyance.
    abated = min(gross, 4 * pss)
    employer_provident = D('28.00') + (rows['2050'][4] if '2050' in rows else D(0))
    csg_base = r2(abated * D('0.9825')) + (gross - abated) + employer_provident
    add('2800', csg_base, '6.80', None)
    add('2810', csg_base, '2.40', None)
    add('2820', csg_base, '0.50', None)
    # Réduction salariale sur HS : taux effectif vieillesse (≤ 11,31 %).
    if ot:
        weighted = sum((rows[c][0] * D(rows[c][1]) for c in ('2200', '2210', '2300', '2310', '2320', '2330', '2340') if c in rows and rows[c][1]), D(0))
        eff = r4(weighted / gross)
        rate = min(eff, D('11.31'))
        rows['2910'] = (ot, rate, -pct(ot, rate), None, None)
        rows['2920'] = (ot_hours, None, D(0), '1.50', -r2(ot_hours * D('1.50')))
    # Réduction générale, régularisation progressive : coefficient sur les cumuls.
    smic_month = r2(Y['smic'] * hours / D('151.67')) + r2(Y['smic_h'] * ot_hours)
    s0 = D(before.get('smic', '0'))
    s1 = s0 + smic_month
    red0 = D(before.get('reduction', '0'))
    if Y['regime'] == 'rgdu':
        if g1 < 3 * s1:
            x = (3 * s1 / g1 - 1) / 2
            C = min(r4(D('0.02') + D('0.3781') * power(x, D('1.75'))), D('0.3981'))
        else:
            C = D(0)
        T = D('0.3981')
    else:
        T = D('0.3193')
        C = min(r4(T / D('0.6') * (D('1.6') * s1 / g1 - 1)), T) if g1 < D('1.6') * s1 else D(0)
    red1 = r2(C * g1)
    month_red = red1 - red0
    if month_red != 0:
        rows['2900'] = (gross, None, D(0), C, -month_red)
    urssaf = r2(month_red * (T - D('0.0601')) / T)
    employee = sum((r[2] for r in rows.values()), D(0))
    employer = sum((r[4] or D(0) for r in rows.values()), D(0))
    # Net imposable : + CSG/CRDS non déductibles + part patronale santé (pas la prévoyance) − HS exonérées.
    nd = rows['2810'][2] + rows['2820'][2]
    exempt = D(0)
    if ot:
        others = sum((rows[c][0] * D(rows[c][1]) for c in rows if c not in ('2800', '2810', '2820', '2910') and rows[c][0] is not None and rows[c][1] and c != '2920'), D(0))
        eff_all = others / gross
        on_ot = pct(ot, eff_all) + rows['2910'][2]          # cotisations salariales des HS, réduction déduite
        csg_ot = r2(ot * D('0.9825') * D('6.80') / 100)
        exempt = max(D(0), ot - on_ot - csg_ot)
        left = D('7500') - D(before.get('exempt', '0'))
        exempt = min(exempt, max(D(0), left))
    net_taxable = gross - employee + nd + D('28.00') - exempt
    net_social = gross - employee
    car = gross_rows.get('1400', D(0))
    meals = r2(D(case.get('meals', '0')) * D(case.get('meal_price', '0')))
    expenses = D(case.get('expenses', '0'))
    advance = D(case.get('advance', '0'))
    before_tax = gross - employee - car + meals + expenses - advance
    if 'pas' in case:
        rate = D(case['pas'])
    else:
        rate = neutral(case['grid'], net_taxable)
    tax = pct(net_taxable, rate)
    return dict(gross=gross, rows=rows, t1=t1, t2=t2, C=C, smic=smic_month, red_month=month_red, red_cum=red1,
                urssaf=urssaf, retirement=month_red - urssaf, employee=employee, employer=employer,
                net_social=net_social, net_taxable=net_taxable, exempt=exempt, before_tax=before_tax,
                pas_rate=rate, pas=tax, net=before_tax - tax, cost=gross + employer,
                cum=dict(gross=g1, ceiling=c1, smic=s1, reduction=red1, net_taxable=D(before.get('net_taxable', '0')) + net_taxable,
                         exempt=D(before.get('exempt', '0')) + exempt))


def show(name, case, codes=()):
    p = payslip(case)
    print('==', name)
    for c in codes:
        print('  ', c, p['rows'].get(c))
    for k in ('gross', 'employee', 'employer', 'C', 'smic', 'red_month', 'red_cum', 'urssaf', 'retirement', 'net_social',
              'net_taxable', 'exempt', 'before_tax', 'pas_rate', 'pas', 'net', 'cost', 't1', 't2'):
        print('   %-12s %s' % (k, p[k]))
    print('   cum', {k: str(v) for k, v in p['cum'].items()})
    return p


if __name__ == '__main__':
    # 1. Thomas Lefèvre, novembre 2026 (maquette 4), dix mois identiques avant lui.
    jan = payslip(dict(year=2026, salary='2400.00', seniority='3', pas='3.5'))
    before = dict(gross=str(10 * jan['gross']), ceiling=str(10 * D('4005')), smic=str(10 * jan['smic']),
                  reduction=str(10 * -jan['rows']['2900'][4]), net_taxable=str(10 * jan['net_taxable']))
    show('Lefèvre novembre 2026', dict(year=2026, salary='2400.00', seniority='3', pas='3.5', before=before), ['2900'])
    # 1 bis. Le même, avec un acompte de 300,00 et 45,60 de frais remboursés.
    show('Lefèvre novembre 2026, acompte et frais', dict(year=2026, salary='2400.00', seniority='3', pas='3.5', before=before,
                                                        advance='300.00', expenses='45.60'))
    # 2. Paul Vasseur, janvier 2026 : cadre, véhicule 322,50, PAS 9,9 %.
    show('Vasseur janvier 2026', dict(year=2026, salary='4650.00', car='322.50', executive=True, pas='9.9'), ['2050', '2320', '2340', '2900'])
    # 3. Mehdi Benali, juin 2026 : 7 %, 12 HS à 25 %, 15 repas à 10,40, taux neutre (grille de mai 2026).
    show('Benali juin 2026', dict(year=2026, salary='2150.00', seniority='7', ot25='12', meals='15', meal_price='10.40', grid=GRID_MAY_2026), ['2910', '2920', '2900'])
    # 3 bis. Le même, 7 400,00 d'HS déjà exonérées dans l'année.
    show('Benali juin 2026, plafond des HS', dict(year=2026, salary='2150.00', seniority='7', ot25='12', meals='15', meal_price='10.40', grid=GRID_MAY_2026, before=dict(exempt='7400.00')))
    # 4. Régularisation des plafonds : janvier 3 000,00, février 5 000,00.
    jan4 = payslip(dict(year=2026, salary='3000.00', pas='0'))
    show('Janvier 3 000', dict(year=2026, salary='3000.00', pas='0'), ['2900'])
    show('Février 5 000 après janvier 3 000', dict(year=2026, salary='5000.00', pas='0', before=dict(
        gross=str(jan4['gross']), ceiling='4005', smic=str(jan4['smic']), reduction=str(-jan4['rows']['2900'][4]))),
        ['2200', '2300', '2320', '2340', '2500', '2610', '2900'])
    # 4 bis. Le même mois de février sans cumul : tranche 2 et CET.
    show('Février 5 000 sans cumul', dict(year=2026, salary='5000.00', pas='0'), ['2200', '2320', '2340', '2900'])
    # 4 ter. Janvier 6 000,00 puis février 2 000,00 : la tranche 2 de janvier revient en tranche 1.
    jan6 = payslip(dict(year=2026, salary='6000.00', pas='0'))
    show('Février 2 000 après janvier 6 000', dict(year=2026, salary='2000.00', pas='0', before=dict(
        gross=str(jan6['gross']), ceiling='4005', smic=str(jan6['smic']), reduction='0')),
        ['2200', '2300', '2310', '2320', '2330', '2340', '2610', '2900'])
    # 5. Réduction générale régularisée à la baisse : deux mois au SMIC, puis 6 000,00 en mars.
    smic1 = payslip(dict(year=2026, salary='1823.03', pas='0'))
    show('Mois au SMIC', dict(year=2026, salary='1823.03', pas='0'), ['2900'])
    show('Mars 6 000 après deux mois au SMIC', dict(year=2026, salary='6000.00', pas='0', before=dict(
        gross=str(2 * smic1['gross']), ceiling=str(2 * D('4005')), smic=str(2 * smic1['smic']), reduction=str(-2 * smic1['rows']['2900'][4]))),
        ['2200', '2320', '2900'])
    # 5 bis. Février 9 200,00 après un mois au SMIC : l'année atteint 3 SMIC, la réduction de janvier est reprise.
    show('Février 9 200 après un mois au SMIC', dict(year=2026, salary='9200.00', pas='0', before=dict(
        gross=str(smic1['gross']), ceiling='4005', smic=str(smic1['smic']), reduction=str(-smic1['rows']['2900'][4]))), ['2900'])
    # 6. Claire Bonnet, juin 2025 : 2 350,00 + 10 %, un mois identique avant (mai), PAS 4,1 %.
    may = payslip(dict(year=2025, salary='2350.00', seniority='10', pas='4.1'))
    show('Bonnet juin 2025', dict(year=2025, salary='2350.00', seniority='10', pas='4.1', before=dict(
        gross=str(may['gross']), ceiling='3925', smic=str(may['smic']), reduction=str(-may['rows']['2900'][4]))), ['2000', '2400', '2900'])
