/**
 * PageHelp for the watch profile form, shared by the create and edit pages so both explain the
 * page the same way. It describes this page only: what a profile is, what the criteria do, and
 * what active means. Where matches end up afterwards belongs to the pages that show them.
 */
export function getWatchProfileHelpSections(wp) {
    return [
        {
            title: wp.form_page_help_section_about ?? 'Hva siden brukes til',
            items: [
                {
                    title: wp.form_page_help_item_what_title ?? 'Én profil, ett sett kriterier',
                    text: wp.form_page_help_item_what_text ?? 'Profilen kan være personlig eller knyttet til en avdeling. Nye kunngjøringer sammenlignes løpende mot kriteriene, og treffene havner hos eieren av profilen.',
                },
                {
                    title: wp.form_page_help_item_criteria_title ?? 'Nøkkelord og CPV-koder',
                    text: wp.form_page_help_item_criteria_text ?? 'Nøkkelord leter etter ord i kunngjøringens tittel, beskrivelse og oppdragsgiver. CPV-koder treffer på kategori.',
                },
                {
                    title: wp.form_page_help_item_cpv_title ?? 'Slik velger du CPV-koder',
                    text: wp.form_page_help_item_cpv_text ?? 'Søk på det du leter etter, eller skriv koden direkte. Legg til de kodene som passer, og fjern dem du ikke vil ha.',
                },
                {
                    title: wp.form_page_help_item_status_title ?? 'Aktiv og inaktiv',
                    text: wp.form_page_help_item_status_text ?? 'Bare aktive profiler brukes til å finne nye treff. En inaktiv profil beholder kriteriene sine, men leter ikke.',
                },
            ],
        },
    ];
}
