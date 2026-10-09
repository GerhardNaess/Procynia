/**
 * What the Oppfølging help explains, for the page the person actually has.
 *
 * With Anbud the page has its aksjon views and counters — Venter på svar, Opprettet av meg,
 * Innkommende, Frister innen 7 dager — and the help explains them as it always did, plus «Mine
 * oppgaver». Without Anbud the page is «Mine oppgaver» alone (InfoCenterController), so the help
 * explains only that: describing views the person does not have would read as something missing.
 *
 * `tenderAvailable` is the backend's module status (infoCenter.tender_available); nothing is decided
 * here about access. All text comes from info_center_page translations; the fallbacks are the
 * Norwegian strings, as everywhere on this page.
 */
export function infoCenterPageHelp(ic = {}, tenderAvailable = true) {
    const myTasks = { title: ic.page_help_item_my_tasks_title ?? 'Mine oppgaver' };
    const modules = {
        title: ic.page_help_item_modules_title ?? 'Oppgaver fra flere moduler',
        text: ic.page_help_item_modules_text ?? 'Mine oppgaver samler arbeid som er tildelt deg i modulene dere bruker. En oppgave forsvinner når arbeidet er gjort, ikke når du leser et varsel.',
    };

    if (! tenderAvailable) {
        return {
            intro: ic.page_help_intro_my_tasks_only ?? 'Oppfølging viser oppgavene som er tildelt deg, samlet på ett sted.',
            sections: [
                {
                    title: ic.page_help_section_my_tasks ?? 'Mine oppgaver',
                    items: [
                        { ...myTasks, text: ic.page_help_item_my_tasks_only_text ?? 'Viser oppgaver som er tildelt deg og fortsatt er åpne, gruppert etter frist: Forfalt, Denne uken, Senere og Uten frist.' },
                        modules,
                    ],
                },
                {
                    title: ic.page_help_section_practical ?? 'Praktisk bruk',
                    items: [
                        { title: ic.page_help_item_practical_title ?? 'Daglig oppfølging', text: ic.page_help_item_practical_my_tasks_only_text ?? 'Bruk Oppfølging som din daglige oversikt over hva som venter på deg. Åpne en oppgave for å gjøre arbeidet der det hører hjemme.' },
                    ],
                },
            ],
        };
    }

    return {
        intro: ic.page_help_intro,
        sections: [
            {
                title: ic.page_help_section_views ?? 'Panelene øverst på siden',
                items: [
                    { ...myTasks, text: ic.page_help_item_my_tasks_text ?? 'Viser åpne oppgaver og oppfølginger som er tildelt deg.' },
                    modules,
                    { title: ic.page_help_item_awaiting_title ?? 'Venter på svar', text: ic.page_help_item_awaiting_text ?? 'Viser oppfølginger du har sendt ut, men som fortsatt venter på respons.' },
                    { title: ic.page_help_item_outbound_title ?? 'Opprettet av meg', text: ic.page_help_item_outbound_text ?? 'Viser oppgaver og oppfølginger du selv har opprettet.' },
                    { title: ic.page_help_item_inbound_title ?? 'Innkommende', text: ic.page_help_item_inbound_text ?? 'Viser oppfølginger eller forespørsler som kommer inn til deg.' },
                    { title: ic.page_help_item_deadline_title ?? 'Frister innen 7 dager', text: ic.page_help_item_deadline_text ?? 'Viser åpne punkter med nær frist.' },
                ],
            },
            {
                title: ic.page_help_section_practical ?? 'Praktisk bruk',
                items: [
                    { title: ic.page_help_item_practical_title ?? 'Daglig oppfølging', text: ic.page_help_item_practical_text ?? 'Bruk Oppfølging som din daglige personlige oppfølgingsliste. Bruk Arbeidsliste og sakssider til selve anbudssakene.' },
                ],
            },
        ],
    };
}

/**
 * The «Mine oppgaver» panel's explanation without Anbud: the panel's own translated description,
 * since the page's standing explanation speaks of aksjoner. Null with Anbud — the panel then keeps
 * the text it always had.
 */
export function myTasksPanelHelp(ic = {}, tenderAvailable = true) {
    return tenderAvailable
        ? null
        : (ic.my_tasks?.neutral_panel_description ?? 'Oppgaver som er tildelt deg og fortsatt er åpne.');
}
