import { Link } from '@inertiajs/react';
import { originPrefix } from '../../../Support/complianceNavigation';

/**
 * Where a requirement comes from, the same way on every page of the module:
 * «ISO 14001:2026 · 6.1.2 · Miljøaspekter». The standard and reference come first and are never
 * left out, so nobody has to guess which standard a requirement belongs to — also where two
 * standards have requirements with the same title. With `href` the title is the link.
 */
export default function RequirementOrigin({ origin, href = null, testId = 'compliance-origin', className = '' }) {
    if (! origin) {
        return null;
    }

    const prefix = originPrefix(origin);

    return (
        <p className={`break-words text-base text-slate-900 ${className}`} data-testid={testId}>
            {prefix && (
                <>
                    <span className="font-semibold text-slate-700">{prefix}</span>
                    <span className="text-slate-500"> · </span>
                </>
            )}
            {href ? (
                <Link href={href} className="font-semibold text-violet-700 hover:text-violet-900">{origin.title}</Link>
            ) : (
                <span className="font-semibold">{origin.title}</span>
            )}
        </p>
    );
}
