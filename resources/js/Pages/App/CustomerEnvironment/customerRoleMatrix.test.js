import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { rolesInDomain } from './customerRoleMatrix.js';

const QUALITY = {
    key: 'quality',
    permissions: [{ key: 'quality.view' }, { key: 'quality.create' }, { key: 'quality.approve' }],
};

const WIKI = {
    key: 'wiki',
    permissions: [{ key: 'wiki.view' }, { key: 'wiki.approve' }, { key: 'wiki.source.manage' }],
};

const kvalitetsleder = { id: 1, name: 'Kvalitetsleder', permission_keys: ['quality.view', 'quality.approve'] };
const wikiansvarlig = { id: 2, name: 'Wiki-ansvarlig', permission_keys: ['wiki.view', 'wiki.approve'] };
const begge = { id: 3, name: 'Kvalitetsdirektør', permission_keys: ['quality.view', 'wiki.source.manage'] };
const tom = { id: 4, name: 'Nyopprettet', permission_keys: [] };

const roles = [kvalitetsleder, wikiansvarlig, begge, tom];

describe('a domain matrix lists only the roles that act in that domain', () => {
    test('Kvalitet leaves out the Wiki-only role', () => {
        assert.deepEqual(
            rolesInDomain(roles, QUALITY).map((role) => role.name),
            ['Kvalitetsleder', 'Kvalitetsdirektør']
        );
    });

    test('Enterprise Wiki leaves out the quality-only role', () => {
        assert.deepEqual(
            rolesInDomain(roles, WIKI).map((role) => role.name),
            ['Wiki-ansvarlig', 'Kvalitetsdirektør']
        );
    });

    test('a role with permissions in both domains appears in both', () => {
        assert.ok(rolesInDomain(roles, QUALITY).includes(begge));
        assert.ok(rolesInDomain(roles, WIKI).includes(begge));
    });

    test('a role with no permissions at all appears in neither', () => {
        assert.ok(! rolesInDomain(roles, QUALITY).includes(tom));
        assert.ok(! rolesInDomain(roles, WIKI).includes(tom));
    });

    test('the incoming order is kept', () => {
        const reversed = [...roles].reverse();

        assert.deepEqual(
            rolesInDomain(reversed, QUALITY).map((role) => role.id),
            [3, 1]
        );
    });

    test('an inactive role is still listed — the matrix filters by domain, not by status', () => {
        const inactive = { id: 5, name: 'Pensjonert', is_active: false, permission_keys: ['quality.view'] };

        assert.ok(rolesInDomain([inactive], QUALITY).includes(inactive));
    });
});

/**
 * The filter is display only, and these guards are what keep it that way: everything else on the
 * page must keep showing every role, or a role that lost its last permission in one domain would
 * become unreachable.
 */
describe('the rest of the page is unfiltered', () => {
    const here = dirname(fileURLToPath(import.meta.url));
    const panel = readFileSync(join(here, 'CustomerRolesPanel.jsx'), 'utf8');

    test('only the domain matrix body uses the filter', () => {
        assert.equal(panel.match(/rolesInDomain\(/g).length, 1);
        assert.match(panel, /const domainRoles = rolesInDomain\(roles, domain\);/);
        assert.match(panel, /\{domainRoles\.map\(\(role\) => \(/);
    });

    test('the role list below the matrices iterates every role', () => {
        assert.ok(panel.includes('{roles.map((role) => {'), 'role list still maps all roles');
    });

    test('the edit dialog still offers both permission groups', () => {
        const dialog = panel.slice(panel.indexOf('field_permissions'));

        assert.match(dialog, /\{domains\.map\(\(domain\) => \(/);
    });
});
