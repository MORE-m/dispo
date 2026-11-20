import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    assertValidAuditReport,
    evaluateProductionAudit,
    runNpmAuditJson,
} from '../../scripts/npm-audit-production.mjs';

const sampleFindingReport = {
    vulnerabilities: {
        picomatch: {
            severity: 'high',
            via: [
                {
                    source: 1247710,
                    name: 'picomatch',
                    title: 'withdrawn advisory placeholder',
                    url: 'https://github.com/advisories/GHSA-fc5q-39xc-vjpf',
                    severity: 'high',
                    range: '<=4.0.7',
                },
            ],
        },
        tinyglobby: {
            severity: 'high',
            via: ['picomatch'],
        },
    },
};

void describe('npm-audit-production fail-closed gate', () => {
    void it('accepts a valid audit report with no findings', () => {
        const verdict = evaluateProductionAudit({ vulnerabilities: {} });
        assert.equal(verdict.ok, true);
        assert.deepEqual(verdict.remaining, []);
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

    void it('rejects any production vulnerability without allow-list', () => {
        const verdict = evaluateProductionAudit(sampleFindingReport);
        assert.equal(verdict.ok, false);
        assert.deepEqual(verdict.remaining, ['picomatch', 'tinyglobby']);
        assert.match(verdict.reason, /remain/i);
    });

    void it('fails npm error reports without vulnerability data', () => {
        const verdict = evaluateProductionAudit({
            error: {
                code: 'ENOTFOUND',
                summary: 'registry unavailable',
            },
            message: 'audit failed',
        });
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

    void it('rejects reports that are not plain objects', () => {
        assert.equal(evaluateProductionAudit(null).ok, false);
        assert.equal(evaluateProductionAudit([]).ok, false);
        assert.equal(evaluateProductionAudit('x').ok, false);
        assert.throws(
            () =>
                assertValidAuditReport({
                    vulnerabilities: [],
                }),
            /plain object/,
        );
    });
});
