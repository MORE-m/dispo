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
/** @typedef {{ vulnerabilities: Record<string, VulnEntry>, metadata?: unknown, error?: unknown, message?: unknown }} AuditReport */

export const EXCEPTION = {
    ghsa: 'GHSA-fc5q-39xc-vjpf',
    /** npm advisory source id observed in audit JSON for this GHSA */
    sourceIds: new Set([1247710]),
    /** Inclusive calendar date (UTC) – after this the exception must be re-approved. */
    expiresOn: '2026-12-18',
    packageName: 'picomatch',
};

/** npm audit: 0 = clean, 1 = vulnerabilities reported. Other codes are tool failures. */
const ACCEPTED_NPM_AUDIT_STATUSES = new Set([0, 1]);

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
 * @param {Set<string>} visiting
 * @returns {true|false|'cycle'}
 */
function resolvePackageExemption(vulnerabilities, packageName, visiting) {
    if (visiting.has(packageName)) {
        // Signal cyclic edge to the caller; never treat as proven exemption.
        return 'cycle';
    }

    const entry = vulnerabilities[packageName];
    if (entry === undefined || entry === null || typeof entry !== 'object') {
        return false;
    }

    const vias = entry.via;
    if (!Array.isArray(vias) || vias.length === 0) {
        return false;
    }

    visiting.add(packageName);

    let sawConcreteProof = false;
    let sawCycleOnlyChild = false;

    for (const via of vias) {
        if (typeof via === 'string') {
            if (via.trim() === '') {
                visiting.delete(packageName);
                return false;
            }

            const child = resolvePackageExemption(
                vulnerabilities,
                via,
                visiting,
            );
            if (child === 'cycle') {
                // Back-edge / unresolved cycle: do not count as proof.
                sawCycleOnlyChild = true;
                continue;
            }
            if (child !== true) {
                visiting.delete(packageName);
                return false;
            }
            sawConcreteProof = true;
            continue;
        }

        if (via === null || typeof via !== 'object') {
            visiting.delete(packageName);
            return false;
        }

        if (!advisoryMatchesException(via)) {
            visiting.delete(packageName);
            return false;
        }
        sawConcreteProof = true;
    }

    visiting.delete(packageName);

    if (sawConcreteProof) {
        return true;
    }

    // Propagate unresolved cycles to the parent so a sibling allow-listed
    // advisory on the parent can still prove the chain.
    if (sawCycleOnlyChild) {
        return 'cycle';
    }

    return false;
}

/**
 * Returns whether every vulnerability path for `packageName` resolves only to the
 * allow-listed GHSA. Unknown references and unresolved cycles fail closed.
 *
 * @param {Record<string, VulnEntry>} vulnerabilities
 * @param {string} packageName
 * @param {Set<string>} [visiting] path-local recursion guard (do not reuse across roots)
 * @returns {boolean}
 */
export function packageOnlyExempted(
    vulnerabilities,
    packageName,
    visiting = new Set(),
) {
    return (
        resolvePackageExemption(vulnerabilities, packageName, visiting) === true
    );
}

/**
 * @param {unknown} report
 * @returns {asserts report is AuditReport}
 */
export function assertValidAuditReport(report) {
    if (
        report === null ||
        typeof report !== 'object' ||
        Array.isArray(report)
    ) {
        throw new Error('npm audit report must be a JSON object');
    }

    const record = /** @type {Record<string, unknown>} */ (report);

    if (
        'error' in record &&
        record.error !== undefined &&
        record.error !== null
    ) {
        throw new Error(
            `npm audit reported an error: ${typeof record.error === 'string' ? record.error : JSON.stringify(record.error)}`,
        );
    }

    if (!Object.hasOwn(record, 'vulnerabilities')) {
        throw new Error('npm audit report is missing a vulnerabilities object');
    }

    if (
        record.vulnerabilities === null ||
        typeof record.vulnerabilities !== 'object' ||
        Array.isArray(record.vulnerabilities)
    ) {
        throw new Error(
            'npm audit report vulnerabilities must be a plain object',
        );
    }
}

/**
 * @param {unknown} report
 * @param {{ now?: Date }} [options]
 */
export function evaluateProductionAudit(report, options = {}) {
    try {
        assertValidAuditReport(report);
    } catch (error) {
        return {
            ok: false,
            reason:
                error instanceof Error
                    ? error.message
                    : 'Invalid npm audit report',
            remaining: [],
            exempted: [],
        };
    }

    if (isExceptionExpired(EXCEPTION.expiresOn, options.now)) {
        return {
            ok: false,
            reason: `Security exception for ${EXCEPTION.ghsa} expired on ${EXCEPTION.expiresOn}. Re-evaluate and remove or renew the exception.`,
            remaining: [],
            exempted: [],
        };
    }

    const vulnerabilities = report.vulnerabilities;
    const remaining = [];
    const exempted = [];

    for (const name of Object.keys(vulnerabilities).sort()) {
        // Fresh visiting set per root package so independent paths do not leak state.
        if (packageOnlyExempted(vulnerabilities, name, new Set())) {
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
 * @param {{ spawnSyncImpl?: typeof spawnSync }} [options]
 */
export function runNpmAuditJson(cwd, options = {}) {
    const spawn = options.spawnSyncImpl ?? spawnSync;
    const result = spawn('npm', ['audit', '--omit=dev', '--json'], {
        cwd,
        encoding: 'utf8',
        maxBuffer: 20 * 1024 * 1024,
    });

    if (result.error) {
        throw new Error(`npm audit failed to start: ${result.error.message}`);
    }

    if (result.status === null) {
        throw new Error(
            `npm audit terminated by signal ${result.signal ?? 'unknown'}`,
        );
    }

    if (!ACCEPTED_NPM_AUDIT_STATUSES.has(result.status)) {
        throw new Error(
            `npm audit exited with unexpected status ${result.status}: ${result.stderr ?? ''}`,
        );
    }

    const stdout = result.stdout ?? '';
    if (!stdout.trim()) {
        throw new Error(
            `npm audit produced no JSON (status=${result.status}): ${result.stderr ?? ''}`,
        );
    }

    let parsed;
    try {
        parsed = JSON.parse(stdout);
    } catch (error) {
        throw new Error(
            `Failed to parse npm audit JSON: ${error instanceof Error ? error.message : String(error)}`,
        );
    }

    assertValidAuditReport(parsed);
    return parsed;
}

function main() {
    const cwd = process.cwd();
    let report;
    try {
        report = runNpmAuditJson(cwd);
    } catch (error) {
        console.error(error instanceof Error ? error.message : String(error));
        process.exit(1);
    }

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
