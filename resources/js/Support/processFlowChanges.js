/**
 * One proposed change to a process flow, as a line the user can read.
 *
 * The server sends each change with its steps already named by label — see
 * QualityProcessFlowChangeProposer::describeChanges() — so all that is left here is choosing the
 * sentence. Kept out of the component so the wording rules can be tested without rendering.
 *
 * Every change says which of three things it is: something added, something changed, something
 * removed. That is the first question a process owner asks of each line, and a removal in
 * particular must never read like an addition.
 */

const BADGES = {
    add_role: 'add',
    add_step: 'add',
    add_flow: 'add',
    update_step: 'update',
    update_flow: 'update',
    remove_step: 'remove',
    remove_flow: 'remove',
};

export function changeBadge(kind) {
    return BADGES[kind] ?? 'update';
}

/** `:name` replacement, matching how the rest of the tab fills translated strings. */
export function fill(template, replacements = {}) {
    return Object.entries(replacements).reduce(
        (text, [key, value]) => text.split(`:${key}`).join(value ?? ''),
        template ?? '',
    );
}

const FALLBACK = {
    change_add_role: 'Ny rolle «:label»',
    change_add_activity: 'Ny aktivitet «:label»',
    change_add_decision: 'Ny beslutning «:label»',
    change_add_end: 'Ny avslutning «:label»',
    change_role: 'Rolle: :role',
    change_update_step: 'Endre «:label»',
    change_field_label: 'Tekst: «:from» → «:to»',
    change_field_role: 'Rolle: :from → :to',
    change_field_description: 'Beskrivelse: :to',
    change_remove_step: 'Fjern «:label»',
    change_remove_step_note: 'Forbindelsene til og fra steget fjernes også.',
    change_add_flow: 'Ny forbindelse «:from» → «:to»',
    change_update_flow: 'Endre utfall på «:from» → «:to»',
    change_remove_flow: 'Fjern forbindelsen «:from» → «:to»',
    change_condition: 'Utfall: :condition',
    change_condition_changed: 'Utfall: :from → :to',
    change_no_condition: 'uten utfall',
};

/**
 * @returns {{ badge: 'add'|'update'|'remove', title: string, details: string[] }}
 */
export function describeChange(change, tb = {}) {
    const t = (key, replacements) => fill(tb[key] ?? FALLBACK[key], replacements);
    const badge = changeBadge(change.kind);
    const details = [];
    let title;

    switch (change.kind) {
        case 'add_role':
            title = t('change_add_role', { label: change.label });
            break;

        case 'add_step':
            title = t(`change_add_${change.type === 'decision' || change.type === 'end' ? change.type : 'activity'}`, { label: change.label });
            if (change.role) details.push(t('change_role', { role: change.role }));
            if (change.description) details.push(change.description);
            break;

        case 'update_step':
            title = t('change_update_step', { label: change.previous_label ?? change.label });
            (change.fields ?? []).forEach((field) => {
                details.push(t(`change_field_${field.field}`, {
                    from: field.from ?? '—',
                    to: field.to ?? '—',
                }));
            });
            break;

        case 'remove_step':
            title = t('change_remove_step', { label: change.label });
            details.push(t('change_remove_step_note'));
            break;

        case 'add_flow':
            title = t('change_add_flow', { from: change.from, to: change.to });
            if (change.condition) details.push(t('change_condition', { condition: change.condition }));
            break;

        case 'update_flow':
            title = t('change_update_flow', { from: change.from, to: change.to });
            details.push(t('change_condition_changed', {
                from: change.previous_condition ?? t('change_no_condition'),
                to: change.condition ?? t('change_no_condition'),
            }));
            break;

        case 'remove_flow':
            title = t('change_remove_flow', { from: change.from, to: change.to });
            if (change.condition) details.push(t('change_condition', { condition: change.condition }));
            break;

        default:
            title = change.label ?? '';
    }

    return { badge, title, details };
}
