import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync, statSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');

function env(name: string, fallback = ''): string {
    return process.env[name] ?? fallback;
}

export function spt008LogDir(): string {
    return env(
        'E2E_SPT008_LOG_DIR',
        path.join(root, 'storage/logs/spt008'),
    );
}

export function spt008Phase(): string {
    return env('E2E_SPT008_PHASE', 'default');
}

export function spt008Port(): string {
    return env('E2E_SPT008_PORT', '8033');
}

function metaPath(): string {
    return path.join(
        spt008LogDir(),
        `server-${spt008Phase()}-${spt008Port()}.meta`,
    );
}

function readMeta(): Record<string, string> {
    const file = metaPath();
    if (!existsSync(file)) {
        return {};
    }
    const out: Record<string, string> = {};
    for (const line of readFileSync(file, 'utf8').split('\n')) {
        const idx = line.indexOf('=');
        if (idx > 0) {
            out[line.slice(0, idx)] = line.slice(idx + 1);
        }
    }
    return out;
}

function psSnapshot(pid: string | undefined): {
    pid: string;
    alive: boolean;
    rss_kb: number | null;
    vsz_kb: number | null;
} {
    if (!pid || pid === 'unknown') {
        return { pid: pid ?? 'missing', alive: false, rss_kb: null, vsz_kb: null };
    }
    try {
        const raw = execFileSync('ps', ['-o', 'pid=,rss=,vsz=', '-p', pid], {
            encoding: 'utf8',
        }).trim();
        if (raw === '') {
            return { pid, alive: false, rss_kb: null, vsz_kb: null };
        }
        const parts = raw.split(/\s+/).filter(Boolean);
        return {
            pid,
            alive: true,
            rss_kb: Number(parts[1] ?? NaN) || null,
            vsz_kb: Number(parts[2] ?? NaN) || null,
        };
    } catch {
        return { pid, alive: false, rss_kb: null, vsz_kb: null };
    }
}

export function portOpen(port: string): boolean {
    try {
        if (process.platform === 'darwin') {
            const out = execFileSync('lsof', [`-iTCP:${port}`, '-sTCP:LISTEN'], {
                encoding: 'utf8',
            });
            return out.trim().length > 0;
        }
        const out = execFileSync('ss', ['-ltn'], { encoding: 'utf8' });
        return out.includes(`:${port} `) || out.includes(`:${port}\n`);
    } catch {
        return false;
    }
}

export function freeMemoryMb(): string | null {
    try {
        if (process.platform === 'linux') {
            return execFileSync('free', ['-m'], { encoding: 'utf8' }).trim();
        }
        // macOS: rough available memory via vm_stat / pages — keep simple
        return execFileSync('vm_stat', [], { encoding: 'utf8' })
            .split('\n')
            .slice(0, 8)
            .join(' | ');
    } catch {
        return null;
    }
}

export function diagSnapshot(tag: string): void {
    const meta = readMeta();
    const parent = psSnapshot(meta.parent_pid);
    const child = psSnapshot(meta.child_pid);
    const payload = {
        tag,
        at: new Date().toISOString(),
        phase: spt008Phase(),
        port: spt008Port(),
        port_open: portOpen(spt008Port()),
        parent,
        child,
        free: freeMemoryMb(),
    };
    console.log(`SPT008_DIAG ${JSON.stringify(payload)}`);
}

export function logExportFile(label: string, filePath: string): void {
    try {
        const size = statSync(filePath).size;
        console.log(
            `SPT008_XLSX_SIZE label=${label} bytes=${size} path=${path.basename(filePath)}`,
        );
    } catch (error) {
        console.log(
            `SPT008_XLSX_SIZE label=${label} error=${String(error)}`,
        );
    }
}

export function logFixtureMatrix(orders: {
    multi: {
        expected: {
            total_positions: number;
            calendar_positions: number;
            average_positions: number;
            xlsx_calendar_rows: number;
            xlsx_average_rows: number;
        };
    };
}): void {
    console.log(
        `SPT008_FIXTURE_MULTI ${JSON.stringify(orders.multi.expected)}`,
    );
}

export function lastServerLogLines(n = 40): string {
    const log = path.join(
        spt008LogDir(),
        `server-${spt008Phase()}-${spt008Port()}.log`,
    );
    if (!existsSync(log)) {
        return `missing log ${log}`;
    }
    const lines = readFileSync(log, 'utf8').trimEnd().split('\n');
    return lines.slice(-n).join('\n');
}

export function readServerExit(): string | null {
    const file = path.join(
        spt008LogDir(),
        `server-${spt008Phase()}-${spt008Port()}.exit`,
    );
    if (!existsSync(file)) {
        return null;
    }
    return readFileSync(file, 'utf8').trim();
}
