import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    profileAnswerLabel,
    profileChangeLines,
    profileFormData,
    toggleListAnswer,
    visibleProfileFields,
} from './supplierProfileAnswers.js';

const source = (file) => readFileSync(new URL(file, import.meta.url), 'utf8');

const FIELDS = ['data_role', 'special_category_data', 'stores_our_data', 'privileged_access', 'data_location', 'uses_subcontractors', 'sectors'];

const tr = {
    profile: {
        not_answered: 'Ikke besvart',
        none_selected: 'Ingen av disse',
        answers: { yes: 'Ja', no: 'Nei', unknown: 'Ikke avklart' },
        data_roles: { processor: 'Databehandler', controller: 'Selvstendig behandlingsansvarlig', unknown: 'Ikke avklart' },
        sectors: { ict: 'IKT og digitale tjenester', cleaning: 'Renhold' },
        labels: { special_category_data: 'Særlige kategorier', uses_subcontractors: 'Bruker underleverandører', sectors: 'Bransjer', data_role: 'Rolle' },
        history: { change_line: ':question: :from → :to' },
    },
};

describe('Ja, Nei and Ikke avklart stay apart from not answered', () => {
    test('each answer has its own name, and an empty list is «Ingen av disse»', () => {
        assert.equal(profileAnswerLabel('uses_subcontractors', 'yes', tr), 'Ja');
        assert.equal(profileAnswerLabel('uses_subcontractors', 'no', tr), 'Nei');
        assert.equal(profileAnswerLabel('uses_subcontractors', 'unknown', tr), 'Ikke avklart');
        assert.equal(profileAnswerLabel('uses_subcontractors', null, tr), 'Ikke besvart');
        assert.equal(profileAnswerLabel('data_role', 'processor', tr), 'Databehandler');
        assert.equal(profileAnswerLabel('sectors', [], tr), 'Ingen av disse');
        assert.equal(profileAnswerLabel('sectors', null, tr), 'Ikke besvart');
        assert.equal(profileAnswerLabel('sectors', ['ict', 'cleaning'], tr), 'IKT og digitale tjenester, Renhold');
    });

    test('«Ingen av disse» is an answer, unticking the last code is not', () => {
        const order = ['ict', 'cleaning', 'goods'];

        assert.deepEqual(toggleListAnswer(null, null, order), []);
        assert.equal(toggleListAnswer([], null, order), null);
        assert.deepEqual(toggleListAnswer([], 'goods', order), ['goods']);
        assert.deepEqual(toggleListAnswer(['goods'], 'ict', order), ['ict', 'goods']);
        assert.equal(toggleListAnswer(['ict'], 'ict', order), null);
    });

    test('the form starts from the current answers, with a fresh begrunnelse', () => {
        assert.deepEqual(profileFormData(['stores_our_data', 'sectors'], null), { stores_our_data: null, sectors: null, reason: '' });
        assert.deepEqual(profileFormData(['stores_our_data', 'sectors'], { stores_our_data: 'unknown', sectors: [] }), { stores_our_data: 'unknown', sectors: [], reason: '' });
    });
});

describe('Only the questions asked for the supplier are shown', () => {
    test('personal data, system access and stored data decide the follow-up questions', () => {
        const none = visibleProfileFields(FIELDS, { processes_personal_data: false, has_system_access: false }, {});
        assert.deepEqual(none, ['stores_our_data', 'data_location', 'uses_subcontractors', 'sectors']);

        const all = visibleProfileFields(FIELDS, { processes_personal_data: true, has_system_access: true }, { stores_our_data: 'unknown' });
        assert.deepEqual(all, FIELDS);

        // Known not to store our data: where the data is is not asked. Not classified: nothing depends on it.
        assert.ok(! visibleProfileFields(FIELDS, null, { stores_our_data: 'no' }).includes('data_location'));
        assert.ok(! visibleProfileFields(FIELDS, null, {}).includes('data_role'));
    });
});

describe('Profilhistorikk reads as what changed', () => {
    test('a change lists each changed question with before and after, nothing else', () => {
        const entry = {
            from: { uses_subcontractors: 'no', special_category_data: 'unknown', sectors: ['ict'], data_role: 'processor' },
            to: { uses_subcontractors: 'yes', special_category_data: 'yes', sectors: ['ict'], data_role: 'processor' },
        };

        assert.deepEqual(profileChangeLines(entry, FIELDS, tr), [
            'Særlige kategorier: Ikke avklart → Ja',
            'Bruker underleverandører: Nei → Ja',
        ]);
    });

    test('the first save lists the answers given, and a question answered later reads from «Ikke besvart»', () => {
        assert.deepEqual(profileChangeLines({ from: null, to: { uses_subcontractors: 'no', sectors: [], data_role: null } }, FIELDS, tr), [
            'Bruker underleverandører: Nei',
            'Bransjer: Ingen av disse',
        ]);
        assert.deepEqual(profileChangeLines({ from: { sectors: null }, to: { sectors: ['cleaning'] } }, ['sectors'], tr), ['Bransjer: Ikke besvart → Renhold']);
    });
});

describe('Only supplier.edit on a supplier that is not ended gets Rediger profil', () => {
    test('the page passes the server\'s can_edit_profile, and the card offers editing only with it', () => {
        assert.match(source('./Show.jsx'), /canEdit=\{permissions\.can_edit_profile \?\? false\}/);

        const card = source('./SupplierProfile.jsx');
        assert.match(card, /\{canEdit && ! open && \(/);
        assert.match(card, /\{canEdit && open && \(/);
        // Explicit choices, never a toggle that could collapse «Ikke avklart» into «Nei».
        assert.match(card, /type="radio"/);
        assert.doesNotMatch(card, /role="switch"/);
    });
});
