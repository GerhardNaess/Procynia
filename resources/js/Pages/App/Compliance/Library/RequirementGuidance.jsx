import { Link } from '@inertiajs/react';

const TERM = 'text-base font-semibold text-slate-600';
const VALUE = 'mt-1 break-words text-base text-slate-900';

/**
 * What Kravbiblioteket says about one requirement: its type, normative character and documented
 * information; the guidance; suggested documentation; and «Gjøres i Procynia» — links to the
 * modules where the work is typically done, which the server sends only for modules this person
 * can open. Shared by the library tree and the requirement page. Nothing here says the requirement
 * is met.
 *
 * @param {object} details  type/rule/documented_information/guidance/documentation/modules
 * @param {boolean} showDescription  the library tree shows the description here; the requirement
 *                                   page already shows it as Kravtekst
 * @param {number}  headingLevel     one below the heading it sits under (1.3.1, A11Y-119): 3 on the
 *                                   requirement page, below the clause in the library tree
 */
export default function RequirementGuidance({ details, tr, showDescription = false, idPrefix, headingLevel = 4 }) {
    const tl = tr.library ?? {};
    const Heading = `h${Math.min(6, Math.max(2, headingLevel))}`;
    const documentation = details.documentation ?? [];
    const modules = details.modules ?? [];

    return (
        <div className="space-y-4" data-testid={`${idPrefix}-guidance`}>
            {showDescription && details.description && (
                <div>
                    <Heading className={TERM}>{tl.description ?? 'Hva kravet innebærer'}</Heading>
                    <p className="mt-1 whitespace-pre-line break-words text-base leading-7 text-slate-800">{details.description}</p>
                </div>
            )}

            <dl className="grid gap-4 sm:grid-cols-3">
                <div>
                    <dt className={TERM}>{tl.requirement_type ?? 'Kravtype'}</dt>
                    <dd className={VALUE}>{tl.types?.[details.requirement_type] ?? details.requirement_type}</dd>
                </div>
                <div>
                    <dt className={TERM}>{tl.rule_label ?? 'Normativ karakter'}</dt>
                    <dd className={VALUE}>{tl.rules?.[details.applicability_rule] ?? details.applicability_rule}</dd>
                </div>
                {details.documented_information && (
                    <div>
                        <dt className={TERM}>{tl.documented_information_label ?? 'Dokumentert informasjon'}</dt>
                        <dd className={VALUE}>{tl.documented_information?.[details.documented_information] ?? details.documented_information}</dd>
                    </div>
                )}
            </dl>

            {details.guidance && (
                <div>
                    <Heading className={TERM}>{tl.guidance ?? 'Veiledning'}</Heading>
                    <p className="mt-1 whitespace-pre-line break-words text-base leading-7 text-slate-800">{details.guidance}</p>
                </div>
            )}

            {documentation.length > 0 && (
                <div>
                    <Heading className={TERM}>{tl.documentation ?? 'Forslag til dokumentasjon'}</Heading>
                    <ul className="mt-1 list-disc space-y-1 pl-6 text-base text-slate-800">
                        {documentation.map((item) => <li key={item} className="break-words">{item}</li>)}
                    </ul>
                </div>
            )}

            {modules.length > 0 && (
                <div>
                    <Heading className={TERM}>{tl.modules_heading ?? 'Gjøres i Procynia'}</Heading>
                    <ul className="mt-2 flex flex-wrap gap-2" data-testid={`${idPrefix}-modules`}>
                        {modules.map((module) => (
                            <li key={module.key}>
                                <Link href={module.href} className="inline-flex min-h-10 items-center rounded-full border border-violet-200 bg-violet-50 px-3 py-1.5 text-base font-semibold text-violet-700 hover:border-violet-300 hover:bg-violet-100">
                                    {tl.modules?.[module.key] ?? module.key} →
                                </Link>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
