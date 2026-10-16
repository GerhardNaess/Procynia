import assert from 'node:assert/strict';
import test from 'node:test';
import { withOldInput } from './userFormOldInput.js';

const defaults = {
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    bid_role: 'contributor',
    is_qa: false,
    primary_department_id: '',
    department_ids: [],
    managed_department_ids: [],
    redirect_to: '/app/users',
};
const types = { arrays: ['department_ids', 'managed_department_ids'], booleans: ['is_qa'], numbers: ['primary_department_id'] };

test('without a refused save the form starts from its defaults', () => {
    assert.equal(withOldInput(defaults, null, types), defaults);
});

test('what was entered comes back in the form\'s own shape', () => {
    const restored = withOldInput(defaults, {
        name: 'Kari Nordmann',
        email: 'kari@example.test',
        bid_role: 'bid_manager',
        is_qa: '1',
        primary_department_id: '7',
        department_ids: ['7', '9'],
        redirect_to: '/somewhere-else',
    }, types);

    assert.deepEqual(restored, {
        ...defaults,
        name: 'Kari Nordmann',
        email: 'kari@example.test',
        bid_role: 'bid_manager',
        is_qa: true,
        primary_department_id: 7,
        department_ids: [7, 9],
        managed_department_ids: [],
    });
});

test('passwords are never put back, even if they were sent', () => {
    const restored = withOldInput(defaults, { name: 'Kari', password: 'hemmelig', password_confirmation: 'hemmelig' }, types);

    assert.equal(restored.password, '');
    assert.equal(restored.password_confirmation, '');
});

test('a checkbox list that was not sent means none was ticked; an unsent field keeps its default', () => {
    const restored = withOldInput({ ...defaults, department_ids: [3] }, { name: 'Kari', is_qa: '0' }, types);

    assert.deepEqual(restored.department_ids, []);
    assert.equal(restored.is_qa, false);
    assert.equal(restored.bid_role, 'contributor');
});

test('an empty value Laravel turned into null reads as an empty field', () => {
    const restored = withOldInput(defaults, { name: null, primary_department_id: null }, types);

    assert.equal(restored.name, '');
    assert.equal(restored.primary_department_id, '');
});
