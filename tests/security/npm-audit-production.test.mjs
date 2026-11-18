import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    EXCEPTION,
    advisoryMatchesException,
    evaluateProductionAudit,
    isExceptionExpired,
    packageOnlyExempted,
} from '../../scripts/npm-audit-production.mjs';

const picomatchReport = {
    vulnerabilities: {
        picomatch: {
            severity: 'high',
            via: [
                {
                    source: 1247710,
                    name: 'picomatch',
                    title: 'picomatch vulnerable to ReDoS through single-branch repeated extglobs',
                    url: 'https://github.com/advisories/GHSA-fc5q-39xc-vjpf',
                    severity: 'high',
                    range: '<=4.0.7',
                },
            ],
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

    void it('accepts metavulnerabilities that only stem from the allow-listed GHSA', () => {
        assert.equal(
            packageOnlyExempted(
                picomatchReport.vulnerabilities,
                'laravel-vite-plugin',
            ),
            true,
        );
        assert.equal(
            packageOnlyExempted(
                picomatchReport.vulnerabilities,
                '@inertiajs/vite',
            ),
            true,
        );
    });

    void it('fails when another advisory is present', () => {
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

    void it('passes when only the allow-listed chain is present before expiry', () => {
        const verdict = evaluateProductionAudit(picomatchReport, {
            now: new Date('2026-11-18T12:00:00Z'),
        });
        assert.equal(verdict.ok, true);
        assert.ok(verdict.exempted.includes('picomatch'));
        assert.ok(verdict.exempted.includes('laravel-vite-plugin'));
    });

    void it('fails after the documented expiry date', () => {
        assert.equal(
            isExceptionExpired(
                EXCEPTION.expiresOn,
                new Date('2026-12-19T00:00:00Z'),
            ),
            true,
        );
        const verdict = evaluateProductionAudit(picomatchReport, {
            now: new Date('2026-12-19T00:00:00Z'),
        });
        assert.equal(verdict.ok, false);
        assert.match(verdict.reason, /expired/i);
    });
});
