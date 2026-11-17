import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { createInterface } from 'node:readline/promises';

const workspace = fileURLToPath(new URL('../../../../../', import.meta.url));
const origin = new URL(process.env.AIYA_TEST_WP_URL ?? 'http://localhost:8000');
assert(['localhost', '127.0.0.1', '[::1]'].includes(origin.hostname), 'Use a loopback test site.');
const prefix = 'aiya_it_' + randomBytes(6).toString('hex');
const hold = process.argv.includes('--hold');
assert(!hold || process.stdin.isTTY, '--hold requires an interactive terminal; use the default for unattended runs.');
const frontend = process.env.AIYA_TEST_FRONT_URL;
if (frontend) {
  assert(['localhost', '127.0.0.1', '[::1]'].includes(new URL(frontend).hostname), 'Use a loopback frontend.');
}
let failures = 0;

async function cli(mode) {
  const child = spawn('docker', ['compose', 'run', '--rm', '-T', 'wpcli', 'eval-file',
    '/var/www/html/wp-content/plugins/aiya-core/tests/Integration/runtime.php', mode, prefix],
  { cwd: workspace, stdio: ['ignore', 'pipe', 'pipe'], env: { ...process.env, MSYS_NO_PATHCONV: '1' } });
  let stdout = '';
  let stderr = '';
  child.stdout.setEncoding('utf8').on('data', (chunk) => { stdout += chunk; });
  child.stderr.setEncoding('utf8').on('data', (chunk) => { stderr += chunk; });
  const code = await new Promise((resolve, reject) => {
    child.on('error', reject);
    child.on('close', resolve);
  });
  // Prepare stdout contains bearer tokens: never echo it on an error.
  assert.equal(code, 0, 'WP-CLI ' + mode + ' failed: ' + stderr.slice(-2000));
  const line = stdout.split(/\r?\n/).find((item) => item.startsWith('AIYA_INTEGRATION='));
  assert(line, 'Missing WP-CLI integration result.');
  return JSON.parse(line.slice('AIYA_INTEGRATION='.length));
}

async function check(name, operation) {
  try {
    await operation();
    console.log('PASS ' + name);
  } catch (error) {
    failures += 1;
    console.error('FAIL ' + name + ': ' + error.message);
  }
}

async function request(path, token, init = {}) {
  const response = await fetch(new URL(path, origin), { ...init,
    headers: { ...(token ? { Authorization: 'Bearer ' + token } : {}), ...init.headers },
    redirect: 'manual', signal: AbortSignal.timeout(15000) });
  return { response, body: await response.json().catch(() => null) };
}

try {
  const fixture = await cli('prepare');
  console.log('PASS real MariaDB DELETE/UPDATE/INSERT failures, password preservation and warm-cache revocation');
  await check('pretty/plain REST authenticate a privileged bearer', async () => {
    for (const path of ['/wp-json/aiya/core/v1/users/me', '/?rest_route=/aiya/core/v1/users/me']) {
      const { response, body } = await request(path, fixture.token);
      assert.equal(response.status, 200);
      assert.equal(body.data.id, fixture.userId);
      assert.match(response.headers.get('cache-control') ?? '', /no-store/);
    }
  });
  await check('GET override cannot borrow authentication from the visible contract path', async () => {
    const { response } = await request('/wp-json/aiya/core/v1/users/me?rest_route=/wp/v2/users/me', fixture.token);
    assert([401, 403, 404].includes(response.status), 'Native route accessible: ' + response.status);
  });
  await check('POST override authenticates only the effective namespace', async () => {
    const { response } = await request('/wp-json/aiya/core/v1/users/me', fixture.token, {
      method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ rest_route: '/wp/v2/users/me' }) });
    assert([401, 403, 404].includes(response.status), 'Native POST accessible: ' + response.status);
  });
  await check('a contract query route overrides a native pretty path', async () => {
    const { response, body } = await request('/wp-json/wp/v2/users/me?rest_route=/aiya/core/v1/users/me', fixture.token);
    assert.equal(response.status, 200);
    assert.equal(body.data.id, fixture.userId);
  });
  await check('real restricted comments retain identity and exclude held rows', async () => {
    for (const post of Object.values(fixture.posts)) {
      const { response, body } = await request('/wp-json/aiya/core/v1/content/' + post.id + '/comments?page=2&perPage=1&order=asc', fixture.token);
      assert.equal(response.status, 200);
      assert.deepEqual(body.data.map((item) => item.id), [post.comments[1]]);
      assert.equal(body.meta.pagination.totalItems, post.comments.length);
      assert.equal((await request('/wp-json/aiya/core/v1/content/' + post.id + '/comments?page=2&perPage=1')).response.status, 404);
    }
    assert.equal((await request('/wp-json/aiya/core/v1/content/' + fixture.posts.member.id + '/comments', fixture.viewerToken)).response.status, 404);
    assert.equal((await request('/wp-json/aiya/core/v1/content/' + fixture.posts.login.id + '/comments', fixture.viewerToken)).response.status, 200);
  });
  assert.equal(failures, 0, 'Integration checks failed.');
  if (frontend) {
    const { token, viewerToken, ...publicFixture } = fixture;
    const child = spawn(process.execPath,
      [fileURLToPath(new URL('../../../../../front-station/tests/integration/comments.mjs', import.meta.url))],
      { cwd: workspace, stdio: ['pipe', 'inherit', 'inherit'] });
    child.stdin.on('error', () => {});
    child.stdin.end(JSON.stringify(publicFixture));
    const code = await new Promise((resolve, reject) => {
      child.on('error', reject);
      child.on('close', resolve);
    });
    assert.equal(code, 0, 'Frontend HTTP integration failed.');
  }
  if (hold) {
    const { token, viewerToken, ...publicFixture } = fixture;
    console.log('AIYA_BROWSER_FIXTURE=' + JSON.stringify(publicFixture));
    console.log('Fixture held for browser acceptance; press Enter to clean up.');
    const input = createInterface({ input: process.stdin, output: process.stdout });
    const abort = new AbortController();
    const stop = () => abort.abort();
    input.once('SIGINT', stop);
    input.once('close', stop);
    process.once('SIGINT', stop);
    process.once('SIGTERM', stop);
    try {
      await input.question('', { signal: abort.signal });
    } catch (error) {
      if (!abort.signal.aborted) throw error;
    } finally {
      input.close();
      process.removeListener('SIGINT', stop);
      process.removeListener('SIGTERM', stop);
    }
  }
} finally {
  await cli('cleanup');
  console.log('PASS fixture cleanup (' + prefix + ')');
}
