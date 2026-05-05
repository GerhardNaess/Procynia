import { usePage } from '@inertiajs/react';

export function useTranslations() {
    const { translations = {} } = usePage().props;
    return translations;
}
