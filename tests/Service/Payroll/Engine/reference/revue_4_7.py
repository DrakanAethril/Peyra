#!/usr/bin/env python3
"""Recalcul indépendant de trois bulletins, écrit pour la revue du lot 4 (étape 4.7a).

Ni le moteur de Peyra, ni le script de référence des étapes 4.3 (bulletins_4_3c.py) ne sont lus ni
importés : ce script repart des règles publiques et des taux de janvier 2026 recopiés à la main des
sources officielles (urssaf.fr, agirc-arrco.fr, service-public.gouv.fr ; les mêmes que
config/regulatory/2026.yaml, qui cite chacune). Python, décimal exact, aucun fichier du dépôt lu.

Les trois bulletins sont ceux de janvier 2026 de Menuiserie Vasseur (fixtures/demo/employees.yaml ;
établissement de 8,69 salariés, taux AT 2,10 %, sans versement mobilité, complémentaire santé du
dossier 28,00 € de chaque côté) - un premier mois de l'année, sans cumul antérieur :

1. Kevin Brun (S006), non-cadre au SMIC : 1 823,03 €, taux de prélèvement transmis 0 % ;
2. Paul Vasseur (S001), cadre au-dessus du plafond : 4 650,00 €, taux transmis 9,9 % ;
3. Hugo Marchand (S004), apprenti (contrat du 01/09/2025, nouveau régime) : 492,22 €, taux neutre.

Règles appliquées (rédigées ici, depuis les sources) :
- chaque part (salariale, patronale) de chaque cotisation = assiette × taux, arrondie au centime au demi
  supérieur ; tranche 1 jusqu'au plafond mensuel (4 005,00 €), tranche 2 de 1 à 8 plafonds, assurance
  chômage, AGS et Apec jusqu'à 4 plafonds, CET sur les tranches 1 et 2 si le brut dépasse le plafond ;
- CSG et CRDS : 98,25 % du brut (limite de 4 plafonds sans objet ici) plus les parts patronales de
  prévoyance et de complémentaire santé, assiette arrondie au centime ;
- réduction générale dégressive unique 2026 (F24542) : C = 0,02 + 0,3781 × [½ × (3 × SMIC ÷ R − 1)]^1,75,
  au plus 0,3981, nulle dès 3 SMIC, arrondi à quatre décimales ; SMIC de référence du mois 1 823,03 € ;
  réduction = R × C arrondi au centime, en déduction des cotisations patronales ;
- montant net social = brut − cotisations salariales ; net imposable = brut − cotisations salariales +
  CSG non déductible + CRDS + part patronale de complémentaire santé ; prélèvement = net imposable × taux,
  arrondi au centime ; net à payer = brut − cotisations salariales − prélèvement ; coût employeur = brut
  + cotisations patronales (réduction déduite) ;
- apprenti dont le contrat a commencé depuis le 01/03/2025 (F2918) : cotisations salariales à taux légal et
  CSG/CRDS sur la seule part du brut au-delà de 50 % du SMIC mensuel (911,52 €) - la complémentaire santé du
  dossier reste due ; cotisations patronales entières ; salaire exonéré d'impôt dans la limite du SMIC annuel.

Lancer : python3 -I tests/Service/Payroll/Engine/reference/revue_4_7.py
IndependentRecalculationTest compare le moteur aux montants que ce script imprime.
"""

from decimal import ROUND_HALF_UP, Decimal as D, getcontext

getcontext().prec = 40
CENT = D('0.01')

PMSS = D('4005')
SMIC_MONTHLY = D('1823.03')  # 12,02 € × 151,67 h, au 01/01/2026
AT_RATE = D('2.10')
HEALTH_FLAT = D('28.00')  # complémentaire santé du dossier, chaque part


def cents(x):
    return x.quantize(CENT, rounding=ROUND_HALF_UP)


def part(base, rate):
    return cents(base * rate / 100)


def tranche(gross, low, high):
    """Part du brut entre low et high plafonds mensuels."""
    return max(D(0), min(gross, high * PMSS) - low * PMSS)


def payslip(name, gross, executive, apprentice, rate):
    t1 = tranche(gross, 0, 1)
    t2 = tranche(gross, 1, 8)
    four = tranche(gross, 0, 4)
    lines = []  # (rubrique, salarié, employeur)

    # Apprenti : la part du brut jusqu'à 50 % du SMIC n'est soumise à aucune cotisation salariale légale.
    threshold = cents(SMIC_MONTHLY * D('0.5')) if apprentice else D(0)

    def employee_base(base):
        return max(D(0), base - threshold)

    lines.append(('Maladie', D(0), part(gross, D('13.00'))))
    if executive:
        lines.append(('Prévoyance cadres (minimum, tranche 1)', D(0), part(t1, D('1.50'))))
    lines.append(('Complémentaire santé', HEALTH_FLAT, HEALTH_FLAT))
    lines.append(('Accidents du travail', D(0), part(gross, AT_RATE)))
    lines.append(('Vieillesse plafonnée', part(employee_base(t1), D('6.90')), part(t1, D('8.55'))))
    lines.append(('Vieillesse déplafonnée', part(employee_base(gross), D('0.40')), part(gross, D('2.11'))))
    lines.append(('Agirc-Arrco tranche 1', part(employee_base(t1), D('3.15')), part(t1, D('4.72'))))
    lines.append(('CEG tranche 1', part(employee_base(t1), D('0.86')), part(t1, D('1.29'))))
    if t2 > 0:
        lines.append(('Agirc-Arrco tranche 2', part(t2, D('8.64')), part(t2, D('12.95'))))
        lines.append(('CEG tranche 2', part(t2, D('1.08')), part(t2, D('1.62'))))
        lines.append(('CET', part(t1 + t2, D('0.14')), part(t1 + t2, D('0.21'))))
    lines.append(('Allocations familiales', D(0), part(gross, D('5.25'))))
    lines.append(('Assurance chômage', D(0), part(four, D('4.00'))))
    lines.append(('AGS', D(0), part(four, D('0.25'))))
    if executive:
        lines.append(('Apec', part(four, D('0.024')), part(four, D('0.036'))))
    lines.append(('CSA', D(0), part(gross, D('0.30'))))
    lines.append(('FNAL (moins de 50)', D(0), part(t1, D('0.10'))))
    lines.append(('Dialogue social', D(0), part(gross, D('0.016'))))
    lines.append(('Formation professionnelle (moins de 11)', D(0), part(gross, D('0.55'))))
    lines.append(('Taxe d’apprentissage', D(0), part(gross, D('0.68'))))

    employer_provident = sum(e for n, s, e in lines if n.startswith('Prévoyance'))
    employer_health = HEALTH_FLAT
    csg_base = cents((gross - threshold if apprentice else gross).max(D(0)) * D('0.9825')) + employer_provident + employer_health
    csg_ded = part(csg_base, D('6.80'))
    csg_non_ded = part(csg_base, D('2.40'))
    crds = part(csg_base, D('0.50'))
    lines.append(('CSG déductible', csg_ded, D(0)))
    lines.append(('CSG non déductible', csg_non_ded, D(0)))
    lines.append(('CRDS', crds, D(0)))

    # Réduction générale dégressive unique 2026.
    if gross >= 3 * SMIC_MONTHLY:
        coefficient = D(0)
    else:
        bracket = (3 * SMIC_MONTHLY / gross - 1) / 2
        coefficient = min(D('0.02') + D('0.3781') * bracket ** D('1.75'), D('0.3981'))
        coefficient = coefficient.quantize(D('0.0001'), rounding=ROUND_HALF_UP)
    reduction = cents(gross * coefficient)

    employee = sum(s for n, s, e in lines)
    employer = sum(e for n, s, e in lines) - reduction
    net_social = gross - employee
    net_taxable = gross - employee + csg_non_ded + crds + employer_health
    exempt = D(0)
    if apprentice:
        exempt = net_taxable  # dans la limite du SMIC annuel (21 876 €), loin d'être atteinte en janvier
        net_taxable = D(0)
    withholding = part(net_taxable, rate)
    net_pay = gross - employee - withholding

    return {
        'name': name, 'gross': gross, 'lines': lines, 'csg_base': csg_base, 'coefficient': coefficient,
        'reduction': reduction, 'employee': employee, 'employer': employer, 'net_social': net_social,
        'net_taxable': net_taxable, 'exempt': exempt, 'withholding': withholding, 'net_pay': net_pay, 'cost': gross + employer,
    }


CASES = [
    payslip('Kevin Brun (S006), non-cadre au SMIC', D('1823.03'), False, False, D('0')),
    payslip('Paul Vasseur (S001), cadre au-dessus du plafond', D('4650.00'), True, False, D('9.9')),
    # Hugo Marchand n'a pas de taux transmis : le taux neutre d'un net imposable nul est 0 %.
    payslip('Hugo Marchand (S004), apprenti', D('492.22'), False, True, D('0')),
]

if __name__ == '__main__':
    for case in CASES:
        print('==', case['name'], '· janvier 2026')
        for name, employee, employer in case['lines']:
            print(f'   {name:45} {employee:>9} {employer:>9}')
        print(f"   assiette CSG {case['csg_base']} ; réduction générale {case['reduction']} (C = {case['coefficient']})")
        for key in ('gross', 'employee', 'employer', 'net_social', 'net_taxable', 'exempt', 'withholding', 'net_pay', 'cost'):
            print(f'   {key:12} {case[key]}')
