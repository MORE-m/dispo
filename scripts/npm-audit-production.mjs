#!/usr/bin/env node
/**
 * Production npm audit with a single, time-boxed GHSA exception.
 *
 * Runs `npm audit --omit=dev --json` and fails on any advisory that is not
 * explicitly allow-listed. Metavulnerabilities that only exist because of an
 * allow-listed advisory are also accepted.
 *
 * This does NOT disable audits, raise severity thresholds, or rewrite the
 * lockfile. See docs/entscheidungen/SEC-NPM-AUDIT-PICOMATCH-GHSA-fc5q-39xc-vjpf.md.
 */

import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

/** @typedef {{ source?: number, name?: string, title?: string, url?: string, severity?: string, range?: string }} AdvisoryVia */
/** @typedef {{ via: Array<string|AdvisoryVia>, severity?: string }} VulnEntry */

export const EXCEPTION = {
    ghsa: 'GHSA-fc5q-39xc-vjpf',
    /** npm advisory source id observed in audit JSON for this GHSA */
    sourceIds: new Set([1247710]),
    /** Inclusive calendar date (UTC) – after this the exception must be re-approved. */
    expiresOn: '2026-12-18',
    packageName: 'picomatch',
};

/**
 * @param {string} expiresOn
 * @param {Date} [now]
 */
export function isExceptionExpired(expiresOn, now = new Date()) {
    const end = Date.parse(`${expiresOn}T23:59:59.999Z`);
    if (Number.isNaN(end)) {
        throw new Error(`Invalid exception expiry date: ${expiresOn}`);
    }
    return now.getTime() > end;
}

/**
 * @param {AdvisoryVia} advisory
 */
export function advisoryMatchesException(advisory) {
    if (EXCEPTION.sourceIds.has(Number(advisory.source))) {
        return true;
    }
    const haystack = `${advisory.url ?? ''} ${advisory.name ?? ''} ${advisory.title ?? ''}`;
    return haystack.includes(EXCEPTION.ghsa);
}

/**
 * @param {Record<string, VulnEntry>} vulnerabilities
 * @param {string} packageName
 * @param {Set<string>} [stack]
 * @returns {boolean}
 */
export function packageOnlyExempted(
    vulnerabilities,
    packageName,
    stack = new Set(),
) {
    if (stack.has(packageName)) {
        return true;
    }
    stack.add(packageName);

    const entry = vulnerabilities[packageName];
    if (!entry) {
        return true;
    }

    for (const via of entry.via ?? []) {
        if (typeof via === 'string') {
            if (!packageOnlyExempted(vulnerabilities, via, stack)) {
                return false;
            }
            continue;
        }

        if (!advisoryMatchesException(via)) {
            return false;
        }
    }

    return true;
}

/**
 * @param {{ vulnerabilities?: Record<string, VulnEntry>, metadata?: { vulnerabilities?: Record<string, number> } }} report
 * @param {{ now?: Date }} [options]
 */
export function evaluateProductionAudit(report, options = {}) {
    if (isExceptionExpired(EXCEPTION.expiresOn, options.now)) {
        return {
            ok: false,
            reason: `Security exception for ${EXCEPTION.ghsa} expired on ${EXCEPTION.expiresOn}. Re-evaluate and remove or renew the exception.`,
            remaining: [],
            exempted: [],
        };
    }

    const vulnerabilities = report.vulnerabilities ?? {};
    const remaining = [];
    const exempted = [];

    for (const name of Object.keys(vulnerabilities).sort()) {
        if (packageOnlyExempted(vulnerabilities, name)) {
            exempted.push(name);
        } else {
            remaining.push(name);
        }
    }

    if (remaining.length > 0) {
        return {
            ok: false,
            reason: `Unapproved production npm vulnerabilities remain: ${remaining.join(', ')}`,
            remaining,
            exempted,
        };
    }

    return {
        ok: true,
        reason:
            exempted.length > 0
                ? `Only allow-listed advisory ${EXCEPTION.ghsa} (expires ${EXCEPTION.expiresOn}) present via: ${exempted.join(', ')}`
                : 'No production npm vulnerabilities.',
        remaining,
        exempted,
    };
}

/**
 * @param {string} cwd
 */
export function runNpmAuditJson(cwd) {
    const result = spawnSync('npm', ['audit', '--omit=dev', '--json'], {
        cwd,
        encoding: 'utf8',
        maxBuffer: 20 * 1024 * 1024,
    });

    const stdout = result.stdout ?? '';
    if (!stdout.trim()) {
        throw new Error(
            `npm audit produced no JSON (status=${result.status}): ${result.stderr ?? ''}`,
        );
    }

    try {
        return JSON.parse(stdout);
    } catch (error) {
        throw new Error(
            `Failed to parse npm audit JSON: ${error instanceof Error ? error.message : String(error)}`,
        );
    }
}

function main() {
    const cwd = process.cwd();
    const report = runNpmAuditJson(cwd);
    const verdict = evaluateProductionAudit(report);

    console.log(
        JSON.stringify(
            {
                exception: {
                    ghsa: EXCEPTION.ghsa,
                    package: EXCEPTION.packageName,
                    expiresOn: EXCEPTION.expiresOn,
                },
                ok: verdict.ok,
                reason: verdict.reason,
                remaining: verdict.remaining,
                exempted: verdict.exempted,
                metadata: report.metadata ?? null,
            },
            null,
            2,
        ),
    );

    if (!verdict.ok) {
        process.exit(1);
    }
}

const invokedDirectly =
    process.argv[1] !== undefined &&
    path.resolve(process.argv[1]) === fileURLToPath(import.meta.url);

if (invokedDirectly) {
    main();
}
