#!/usr/bin/env node
/**
 * Production npm audit – fail-closed, no severity threshold, no allow-list.
 *
 * Runs `npm audit --omit=dev --json` and fails on any reported vulnerability.
 * Invalid / incomplete audit reports also fail.
 *
 * Historical note: a time-boxed exception for GHSA-fc5q-39xc-vjpf (picomatch)
 * existed until 2026-11-20. That advisory was withdrawn upstream on
 * 2026-11-18 (`withdrawnAt`), so the exception was removed. See
 * docs/readiness/SEC-DEPS-2026-11.md and the decision doc follow-up after
 * Docs-PR #259.
 */

import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

/** @typedef {{ via?: Array<unknown>, severity?: string }} VulnEntry */
/** @typedef {{ vulnerabilities: Record<string, VulnEntry>, metadata?: unknown, error?: unknown, message?: unknown }} AuditReport */

/** npm audit: 0 = clean, 1 = vulnerabilities reported. Other codes are tool failures. */
const ACCEPTED_NPM_AUDIT_STATUSES = new Set([0, 1]);

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
 */
export function evaluateProductionAudit(report) {
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
        };
    }

    const remaining = Object.keys(report.vulnerabilities).sort();

    if (remaining.length > 0) {
        return {
            ok: false,
            reason: `Production npm vulnerabilities remain: ${remaining.join(', ')}`,
            remaining,
        };
    }

    return {
        ok: true,
        reason: 'No production npm vulnerabilities.',
        remaining,
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
                ok: verdict.ok,
                reason: verdict.reason,
                remaining: verdict.remaining,
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
