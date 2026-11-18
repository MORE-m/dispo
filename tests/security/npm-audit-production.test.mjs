import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    EXCEPTION,
    advisoryMatchesException,
    assertValidAuditReport,
    evaluateProductionAudit,
    isExceptionExpired,
    packageOnlyExempted,
    runNpmAuditJson,
} from '../../scripts/npm-audit-production.mjs';

const allowlistedAdvisory = {
    source: 1247710,
    name: 'picomatch',
    title: 'picomatch vulnerable to ReDoS through single-branch repeated extglobs',
    url: 'https://github.com/advisories/GHSA-fc5q-39xc-vjpf',
    severity: 'high',
    range: '<=4.0.7',
};

const picomatchReport = {
    vulnerabilities: {
        picomatch: {
            severity: 'high',
            via: [allowlistedAdvisory],
        },
        tinyglobby: {
            severity: 'high',
            via: ['picomatch', 'fdir'],
        },
        fdir: {
            severity: 'high',
            via: ['picomatch'],
        },
        'laravel-vite-plugin': {
            severity: 'high',
            via: ['tinyglobby', 'vite-plugin-full-reload'],
        },
        'vite-plugin-full-reload': {
            severity: 'high',
            via: ['picomatch'],
        },
        '@inertiajs/vite': {
            severity: 'high',
            via: ['tinyglobby'],
        },
    },
};

void describe('npm-audit-production exception filter', () => {
    void it('recognizes the allow-listed GHSA by source id and URL', () => {
        assert.equal(
            advisoryMatchesException({
                source: 1247710,
                url: 'https://example.invalid',
            }),
            true,
        );
        assert.equal(
            advisoryMatchesException({
                url: 'https://github.com/advisories/GHSA-fc5q-39xc-vjpf',
            }),
            true,
        );
        assert.equal(
            advisoryMatchesException({
                source: 999,
                url: 'https://github.com/advisories/GHSA-other',
                title: 'unrelated',
            }),
            false,
        );
    });

    void it('rejects unknown referenced vulnerabilities', () => {
        const vulns = {
            parent: {
                severity: 'high',
                via: ['missing-child'],
            },
        };
        assert.equal(packageOnlyExempted(vulns, 'parent', new Set()), false);
        assert.equal(
            packageOnlyExempted(vulns, 'missing-child', new Set()),
            false,
        );
    });

    void it('rejects pure cyclic references without an allow-listed advisory leaf', () => {
        const vulns = {
            a: { severity: 'high', via: ['b'] },
            b: { severity: 'high', via: ['a'] },
        };
        assert.equal(packageOnlyExempted(vulns, 'a', new Set()), false);
        assert.equal(packageOnlyExempted(vulns, 'b', new Set()), false);
    });

    void it('accepts a cycle only when another via proves the allow-listed GHSA', () => {
        const vulns = {
            a: {
                severity: 'high',
                via: ['b', allowlistedAdvisory],
            },
            b: {
                severity: 'high',
                via: ['a'],
            },
        };
        assert.equal(packageOnlyExempted(vulns, 'a', new Set()), true);
        // b is only a metavuln of a, which itself proves the allow-listed GHSA.
        assert.equal(packageOnlyExempted(vulns, 'b', new Set()), true);
    });

    void it('keeps independent metavulnerability paths isolated', () => {
        const vulns = {
            ...picomatchReport.vulnerabilities,
            'source-map-js': {
                severity: 'high',
                via: [
                    {
                        source: 1,
                        name: 'source-map-js',
                        url: 'https://github.com/advisories/GHSA-68fv-2mgg-jv7q',
                        title: 'other',
                    },
                ],
            },
            unrelated: {
                severity: 'high',
                via: ['source-map-js'],
            },
        };

        assert.equal(
            packageOnlyExempted(vulns, 'laravel-vite-plugin', new Set()),
            true,
        );
        assert.equal(packageOnlyExempted(vulns, 'unrelated', new Set()), false);

        const verdict = evaluateProductionAudit(
            { vulnerabilities: vulns },
            { now: new Date('2026-11-18T12:00:00Z') },
        );
        assert.equal(verdict.ok, false);
        assert.ok(verdict.exempted.includes('laravel-vite-plugin'));
        assert.ok(verdict.remaining.includes('source-map-js'));
        assert.ok(verdict.remaining.includes('unrelated'));
    });

    void it('accepts metavulnerabilities that only stem from the allow-listed GHSA', () => {
        assert.equal(
            packageOnlyExempted(
                picomatchReport.vulnerabilities,
                'laravel-vite-plugin',
                new Set(),
            ),
            true,
        );
        assert.equal(
            packageOnlyExempted(
                picomatchReport.vulnerabilities,
                '@inertiajs/vite',
                new Set(),
            ),
            true,
        );
    });

    void it('fails npm error reports without vulnerability data', () => {
        const verdict = evaluateProductionAudit(
            {
                error: {
                    code: 'ENOTFOUND',
                    summary: 'registry unavailable',
                },
                message: 'audit failed',
            },
            { now: new Date('2026-11-18T12:00:00Z') },
        );
        assert.equal(verdict.ok, false);
        assert.match(verdict.reason, /error/i);

        assert.throws(
            () =>
                assertValidAuditReport({
                    message: 'broken',
                }),
            /vulnerabilities/,
        );
    });

    void it('rejects invalid JSON and unexpected npm audit exit codes', () => {
        assert.throws(
            () =>
                runNpmAuditJson('/tmp', {
                    spawnSyncImpl: () => ({
                        status: 0,
                        stdout: '{not-json',
                        stderr: '',
                        error: undefined,
                        signal: null,
                    }),
                }),
            /parse npm audit JSON/i,
        );

        assert.throws(
            () =>
                runNpmAuditJson('/tmp', {
                    spawnSyncImpl: () => ({
                        status: 2,
                        stdout: JSON.stringify({ vulnerabilities: {} }),
                        stderr: 'audit tooling failed',
                        error: undefined,
                        signal: null,
                    }),
                }),
            /unexpected status 2/i,
        );

        assert.throws(
            () =>
                runNpmAuditJson('/tmp', {
                    spawnSyncImpl: () => ({
                        status: null,
                        stdout: '',
                        stderr: '',
                        error: undefined,
                        signal: 'SIGTERM',
                    }),
                }),
            /signal/i,
        );
    });

    void it('accepts a valid audit report with no findings', () => {
        const verdict = evaluateProductionAudit(
            { vulnerabilities: {} },
            { now: new Date('2026-11-18T12:00:00Z') },
        );
        assert.equal(verdict.ok, true);
        assert.deepEqual(verdict.remaining, []);
        assert.deepEqual(verdict.exempted, []);
        assert.match(verdict.reason, /no production npm vulnerabilities/i);

        const parsed = runNpmAuditJson('/tmp', {
            spawnSyncImpl: () => ({
                status: 0,
                stdout: JSON.stringify({ vulnerabilities: {} }),
                stderr: '',
                error: undefined,
                signal: null,
            }),
        });
        assert.deepEqual(parsed.vulnerabilities, {});
    });

    void it('accepts a valid report with only the allow-listed GHSA chain', () => {
        const verdict = evaluateProductionAudit(picomatchReport, {
            now: new Date('2026-11-18T12:00:00Z'),
        });
        assert.equal(verdict.ok, true);
        assert.ok(verdict.exempted.includes('picomatch'));
        assert.ok(verdict.exempted.includes('laravel-vite-plugin'));
        assert.deepEqual(verdict.remaining, []);
    });

    void it('rejects a valid report that also contains a non-allow-listed GHSA', () => {
        const mixed = structuredClone(picomatchReport);
        mixed.vulnerabilities['source-map-js'] = {
            severity: 'high',
            via: [
                {
                    source: 1,
                    name: 'source-map-js',
                    url: 'https://github.com/advisories/GHSA-68fv-2mgg-jv7q',
                    title: 'other',
                },
            ],
        };

        const verdict = evaluateProductionAudit(mixed, {
            now: new Date('2026-11-18T12:00:00Z'),
        });
        assert.equal(verdict.ok, false);
        assert.deepEqual(verdict.remaining, ['source-map-js']);
    });

    void it('keeps the exception valid through 2026-12-18 and fails afterwards', () => {
        assert.equal(
            isExceptionExpired(
                EXCEPTION.expiresOn,
                new Date('2026-12-18T23:59:59.999Z'),
            ),
            false,
        );
        assert.equal(
            evaluateProductionAudit(picomatchReport, {
                now: new Date('2026-12-18T23:59:59.999Z'),
            }).ok,
            true,
        );

        assert.equal(
            isExceptionExpired(
                EXCEPTION.expiresOn,
                new Date('2026-12-19T00:00:00.000Z'),
            ),
            true,
        );
        const expired = evaluateProductionAudit(picomatchReport, {
            now: new Date('2026-12-19T00:00:00.000Z'),
        });
        assert.equal(expired.ok, false);
        assert.match(expired.reason, /expired/i);
        assert.equal(EXCEPTION.expiresOn, '2026-12-18');
    });
});
