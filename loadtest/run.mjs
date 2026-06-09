// Load-test driver for new-claude.
//
// Runs N student flows (login → token validate → load exam → submit) in
// a bounded pool, round-robined across a fleet of artisan-serve workers,
// while M teachers parallel-monitor dashboard/scores/pending-score.
//
// Requirements:
//   - node 22+ (for native fetch + headers.getSetCookie)
//   - LoadTestSeeder already run
//   - Fleet of `php artisan serve --port=80NN` workers running on each
//     port listed in config.json
//
// Usage: node loadtest/run.mjs

import fs from 'node:fs';
import { performance } from 'node:perf_hooks';
import { setTimeout as sleep } from 'node:timers/promises';

process.on('unhandledRejection', (reason) => {
    console.error('\n[loadtest] UNHANDLED REJECTION:', reason);
    process.exit(2);
});
process.on('uncaughtException', (err) => {
    console.error('\n[loadtest] UNCAUGHT EXCEPTION:', err);
    process.exit(3);
});

const config = JSON.parse(fs.readFileSync(new URL('./config.json', import.meta.url), 'utf-8'));
const { studentCount, teacherCount, concurrency, baseHost, ports, token, examCode, password, teacherIterations, teacherIntervalMs } = config;

const origins = ports.map((p) => `http://${baseHost}:${p}`);
let rrCounter = 0;
const nextOrigin = () => origins[(rrCounter++) % origins.length];

const stats = {
    studentLogin: { ok: 0, fail: 0 },
    tokenValidate: { ok: 0, fail: 0 },
    examShow: { ok: 0, fail: 0 },
    submit: { ok: 0, fail: 0 },
    teacherLogin: { ok: 0, fail: 0 },
    teacherDashboard: { ok: 0, fail: 0 },
    teacherScores: { ok: 0, fail: 0 },
    teacherPending: { ok: 0, fail: 0 },
    teacherMonitor: { ok: 0, fail: 0 },
};
const submissionIds = new Set();
const errorSamples = [];

// ----- cookie jar helpers -----
function captureCookies(res, jar) {
    const headerCookies = typeof res.headers.getSetCookie === 'function' ? res.headers.getSetCookie() : [];
    for (const raw of headerCookies) {
        const eq = raw.indexOf('=');
        const semi = raw.indexOf(';');
        if (eq < 0) continue;
        const name = raw.slice(0, eq).trim();
        const value = raw.slice(eq + 1, semi >= 0 ? semi : raw.length).trim();
        jar.set(name, value);
    }
}
const cookieHeader = (jar) => [...jar.entries()].map(([k, v]) => `${k}=${v}`).join('; ');

// ----- Inertia page extraction -----
function decodeEntities(s) {
    return s
        .replace(/&quot;/g, '"')
        .replace(/&#39;/g, "'")
        .replace(/&apos;/g, "'")
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&amp;/g, '&');
}
function extractInertiaPage(html) {
    const m = html.match(/<script data-page="app" type="application\/json">([\s\S]*?)<\/script>/);
    if (!m) return null;
    return JSON.parse(decodeEntities(m[1]));
}

function recordError(phase, err) {
    stats[phase].fail++;
    if (errorSamples.length < 10) {
        errorSamples.push(`${phase}: ${err.message ?? err}`);
    }
}

// ----- one student run -----
async function runStudent(i) {
    const username = `loadstudent${String(i + 1).padStart(4, '0')}`;
    const jar = new Map();

    // 1. Login (rotating origins)
    try {
        const origin = nextOrigin();
        const r = await fetch(`${origin}/login`, {
            method: 'POST',
            redirect: 'manual',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                Origin: origin,
            },
            body: JSON.stringify({ username, password }),
        });
        if (r.status !== 302 && r.status !== 200) throw new Error(`HTTP ${r.status}`);
        captureCookies(r, jar);
        if (!jar.has('secure-exam-session')) throw new Error('no session cookie');
        stats.studentLogin.ok++;
    } catch (e) {
        recordError('studentLogin', e);
        return;
    }

    // 2. Token validate
    try {
        const origin = nextOrigin();
        const r = await fetch(`${origin}/token`, {
            method: 'POST',
            redirect: 'manual',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                Origin: origin,
                Cookie: cookieHeader(jar),
            },
            body: JSON.stringify({ token }),
        });
        if (r.status !== 302 && r.status !== 200) throw new Error(`HTTP ${r.status}`);
        captureCookies(r, jar);
        if (!jar.has('secure-exam-access')) throw new Error('no access cookie');
        stats.tokenValidate.ok++;
    } catch (e) {
        recordError('tokenValidate', e);
        return;
    }

    // 3. Load exam page (Inertia HTML)
    let questions = null;
    try {
        const origin = nextOrigin();
        const r = await fetch(`${origin}/exams/${examCode}`, {
            headers: { Cookie: cookieHeader(jar) },
        });
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        const html = await r.text();
        const page = extractInertiaPage(html);
        if (!page) throw new Error('no inertia payload');
        questions = page.props?.questions;
        if (!Array.isArray(questions) || questions.length === 0) {
            throw new Error('no questions in payload (component=' + page.component + ')');
        }
        stats.examShow.ok++;
    } catch (e) {
        recordError('examShow', e);
        return;
    }

    // 4. Build correct answers
    const answers = {};
    for (const q of questions) {
        switch (q.type) {
            case 'single_choice':
                answers[q.id] = 'b';
                break;
            case 'multi_select':
                answers[q.id] = ['1', '3'];
                break;
            case 'short_text':
                answers[q.id] = 'Paris';
                break;
            case 'numeric':
                answers[q.id] = 42;
                break;
            case 'essay':
                answers[q.id] = 'Body in motion stays in motion unless acted on by a force.';
                break;
        }
    }

    // 5. Submit (JSON path)
    try {
        const origin = nextOrigin();
        const r = await fetch(`${origin}/exams/${examCode}/submit`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                Origin: origin,
                Cookie: cookieHeader(jar),
            },
            body: JSON.stringify({ answers, events: [] }),
        });
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        const j = await r.json().catch(() => null);
        if (!j?.submissionId) throw new Error('no submissionId');
        submissionIds.add(j.submissionId);
        stats.submit.ok++;
    } catch (e) {
        recordError('submit', e);
    }
}

// ----- one teacher run (monitoring loop) -----
async function runTeacher(i) {
    const username = `loadteacher${String(i + 1).padStart(2, '0')}`;
    const isOwner = i === 0; // loadteacher01 owns LOADTEST
    const jar = new Map();

    try {
        const origin = nextOrigin();
        const r = await fetch(`${origin}/login`, {
            method: 'POST',
            redirect: 'manual',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                Origin: origin,
            },
            body: JSON.stringify({ username, password }),
        });
        if (r.status !== 302 && r.status !== 200) throw new Error(`HTTP ${r.status}`);
        captureCookies(r, jar);
        if (!jar.has('secure-exam-session')) throw new Error('no session cookie');
        stats.teacherLogin.ok++;
    } catch (e) {
        recordError('teacherLogin', e);
        return;
    }

    for (let it = 0; it < teacherIterations; it++) {
        const cookie = cookieHeader(jar);
        // dashboard
        try {
            const o = nextOrigin();
            const r = await fetch(`${o}/teacher`, { headers: { Cookie: cookie } });
            r.ok ? stats.teacherDashboard.ok++ : stats.teacherDashboard.fail++;
        } catch (e) {
            recordError('teacherDashboard', e);
        }
        // scores tree
        try {
            const o = nextOrigin();
            const r = await fetch(`${o}/teacher/scores`, { headers: { Cookie: cookie } });
            r.ok ? stats.teacherScores.ok++ : stats.teacherScores.fail++;
        } catch (e) {
            recordError('teacherScores', e);
        }
        // pending-score
        try {
            const o = nextOrigin();
            const r = await fetch(`${o}/teacher/pending-score`, { headers: { Cookie: cookie } });
            r.ok ? stats.teacherPending.ok++ : stats.teacherPending.fail++;
        } catch (e) {
            recordError('teacherPending', e);
        }
        // Owner also live-monitors LOADTEST (detail + submissions json)
        if (isOwner) {
            try {
                const o = nextOrigin();
                const r = await fetch(`${o}/teacher/exams/${examCode}/submissions`, {
                    headers: { Accept: 'application/json', Cookie: cookie },
                });
                r.ok ? stats.teacherMonitor.ok++ : stats.teacherMonitor.fail++;
            } catch (e) {
                recordError('teacherMonitor', e);
            }
        }
        await sleep(teacherIntervalMs);
    }
}

// ----- bounded worker pool -----
async function pool(jobCount, worker, parallelism) {
    let cursor = 0;
    const workers = Array.from({ length: parallelism }, async () => {
        while (true) {
            const i = cursor++;
            if (i >= jobCount) return;
            await worker(i);
        }
    });
    await Promise.all(workers);
}

// ----- main -----
function printResults(elapsed) {
    const payload = {
        elapsed,
        stats,
        distinctSubmissionIds: submissionIds.size,
        throughput: {
            studentSubmitsPerSec: stats.submit.ok / elapsed,
            teacherReqsPerSec: (stats.teacherDashboard.ok + stats.teacherScores.ok + stats.teacherPending.ok) / elapsed,
        },
        errorSamples,
    };
    // Always dump to a file — most reliable under bash pipes / SIGPIPE / etc.
    try {
        fs.writeFileSync(new URL('./results.json', import.meta.url), JSON.stringify(payload, null, 2));
    } catch (e) {
        console.error('Failed to write results.json:', e);
    }
    console.log('\n\n=== RESULTS ===');
    console.log(`Elapsed: ${elapsed.toFixed(1)}s`);
    console.log(JSON.stringify(stats, null, 2));
    console.log(`Distinct submissionIds collected: ${submissionIds.size}`);
    console.log(
        `Throughput: ${(stats.submit.ok / elapsed).toFixed(1)} student submits/s, ${((stats.teacherDashboard.ok + stats.teacherScores.ok + stats.teacherPending.ok) / elapsed).toFixed(1)} teacher reqs/s`,
    );
    if (errorSamples.length > 0) {
        console.log('\nError samples (first 10):');
        errorSamples.forEach((e) => console.log('  - ' + e));
    }
}

const t0 = performance.now();
let tick;
try {
    console.log(`[loadtest] Starting: ${studentCount} students + ${teacherCount} teachers, ${ports.length} workers, concurrency=${concurrency}`);
    console.log(`[loadtest] Fleet: ${origins.join(', ')}`);

    const teacherPromise = pool(teacherCount, runTeacher, Math.min(teacherCount, 20));
    tick = setInterval(() => {
        const total = stats.submit.ok + stats.submit.fail;
        process.stdout.write(
            `\r[loadtest] login ${stats.studentLogin.ok}/${stats.studentLogin.ok + stats.studentLogin.fail}  validate ${stats.tokenValidate.ok}  show ${stats.examShow.ok}  submit ${stats.submit.ok}/${total}        `,
        );
    }, 1000);

    await pool(studentCount, runStudent, concurrency);
    await teacherPromise;
} catch (err) {
    console.error('\n[loadtest] FATAL in main:', err);
} finally {
    if (tick) clearInterval(tick);
    printResults((performance.now() - t0) / 1000);
}
