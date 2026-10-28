// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * k6 HTTP load test for mod_sideline (P-39): real logins, real sessions, the real AJAX endpoint.
 *
 * Scenario "plateau": VUS learners stay active for DURATION. Each virtual user logs in as its own
 * learner, opens the activity page once and then requests the commentary repeatedly through
 * lib/ajax/service.php, as the panel does. Every tenth request first changes the learner's state
 * (marks an activity complete or incomplete), so the next commentary has to be generated again.
 * The two kinds of request are measured separately: "cached" and "after state change".
 *
 * Scenario "race": after the plateau, RACE_VUS sessions of ONE learner request the commentary at
 * the same moment, right after a state change. The server must answer every request and keep one
 * current cache row for that learner (checked afterwards by verify_load.php).
 *
 * Environment (printed by tests/load/seed_load.php): BASE_URL, CMID, USER_PREFIX, USERS, PASSWORD,
 * PAGE_CMIDS. Tuning: VUS (20), DURATION (60s), RACE_VUS (20).
 */
import http from 'k6/http';
import exec from 'k6/execution';
import {check, sleep, fail} from 'k6';
import {Trend, Counter} from 'k6/metrics';

const BASE_URL = __ENV.BASE_URL;
const CMID = Number(__ENV.CMID);
const USER_PREFIX = __ENV.USER_PREFIX;
const USERS = Number(__ENV.USERS || 50);
const PASSWORD = __ENV.PASSWORD;
const PAGE_CMIDS = (__ENV.PAGE_CMIDS || '').split(',').filter((v) => v !== '').map(Number);
const VUS = Number(__ENV.VUS || 20);
const DURATION = __ENV.DURATION || '60s';
const RACE_VUS = Number(__ENV.RACE_VUS || 20);
const DURATION_SECONDS = parseInt(DURATION, 10);

const cached = new Trend('sideline_commentary_cached', true);
const regenerated = new Trend('sideline_commentary_after_change', true);
const raced = new Trend('sideline_commentary_race', true);
const wsErrors = new Counter('sideline_ws_errors');

export const options = {
    // Each virtual user keeps its Moodle session across iterations (k6 clears cookies per iteration by default).
    noCookiesReset: true,
    scenarios: {
        plateau: {executor: 'constant-vus', vus: VUS, duration: DURATION, exec: 'plateau'},
        race: {
            executor: 'per-vu-iterations',
            vus: RACE_VUS,
            iterations: 1,
            startTime: (DURATION_SECONDS + 5) + 's',
            maxDuration: '60s',
            exec: 'race',
        },
    },
    thresholds: {
        'http_req_failed': ['rate<0.01'],
        'checks': ['rate>0.99'],
        'sideline_ws_errors': ['count==0'],
        // Budgets for a development web server (PHP's built-in server with several workers). See README.
        'sideline_commentary_cached': ['p(95)<800'],
        'sideline_commentary_after_change': ['p(95)<1500'],
        'sideline_commentary_race': ['max<10000'],
    },
};

/**
 * Stop at once when the environment is incomplete or the site does not answer.
 *
 * @returns {object} Nothing that the scenarios need.
 */
export function setup() {
    if (!BASE_URL || !CMID || !USER_PREFIX || !PASSWORD) {
        fail('BASE_URL, CMID, USER_PREFIX and PASSWORD must be set (see tests/load/seed_load.php).');
    }
    const probe = http.get(`${BASE_URL}/login/index.php`);
    if (probe.status !== 200) {
        fail(`Site not reachable: ${BASE_URL} answered HTTP ${probe.status}.`);
    }
    return {};
}

/**
 * Log in through the login form and return the session key from the activity page.
 *
 * Each virtual user has its own cookie jar, so this is a separate Moodle session.
 *
 * @param {string} username Username.
 * @returns {string} The session key.
 */
function login(username) {
    // k6 reuses virtual users across scenarios: start from a clean session, never from a plateau learner's.
    http.cookieJar().clear(BASE_URL);
    const form = http.get(`${BASE_URL}/login/index.php`);
    const token = (form.body.match(/name="logintoken" value="([^"]+)"/) || [])[1] || '';
    const res = http.post(`${BASE_URL}/login/index.php`, {username: username, password: PASSWORD, logintoken: token});
    check(res, {'login ok': (r) => r.status === 200 && !r.url.includes('/login/index.php')});
    const page = http.get(`${BASE_URL}/mod/sideline/view.php?id=${CMID}`);
    check(page, {'activity page 200': (r) => r.status === 200});
    const sesskey = (page.body.match(/"sesskey":"([^"]+)"/) || [])[1];
    // The activity page is only shown to a logged-in learner; the login page would carry a session key too.
    if (!sesskey || !page.url.includes('/mod/sideline/view.php') || !page.body.includes('logout.php')) {
        fail(`Login as ${username} failed.`);
    }
    return sesskey;
}

/**
 * Call one external function through Moodle's AJAX endpoint.
 *
 * @param {string} sesskey Session key.
 * @param {string} method Function name.
 * @param {object} args Arguments.
 * @returns {object} The response object of the call and the HTTP response.
 */
function call(sesskey, method, args) {
    const res = http.post(
        `${BASE_URL}/lib/ajax/service.php?sesskey=${sesskey}&info=${method}`,
        JSON.stringify([{index: 0, methodname: method, args: args}]),
        {headers: {'Content-Type': 'application/json'}, tags: {name: method}}
    );
    let body = null;
    try {
        body = JSON.parse(res.body)[0];
    } catch (e) {
        body = null;
    }
    if (!body || body.error) {
        wsErrors.add(1);
    }
    return {res: res, body: body};
}

/**
 * Request the commentary and check it.
 *
 * @param {string} sesskey Session key.
 * @param {Trend} trend Where to record the duration.
 * @returns {object|null} The commentary data.
 */
function commentary(sesskey, trend) {
    const {res, body} = call(sesskey, 'mod_sideline_get_commentary', {cmid: CMID});
    trend.add(res.timings.duration);
    check(body, {
        'commentary delivered': (b) => b !== null && !b.error && b.data.status === 'ok',
        'commentary has text': (b) => b !== null && !b.error && b.data.blocks.length > 0,
    });
    return body && !body.error ? body.data : null;
}

/**
 * Mark one activity complete or incomplete for the current learner.
 *
 * @param {string} sesskey Session key.
 * @param {number} cmid Activity.
 * @param {boolean} completed New state.
 */
function setCompletion(sesskey, cmid, completed) {
    const {body} = call(sesskey, 'core_completion_update_activity_completion_status_manually', {
        cmid: cmid, completed: completed,
    });
    check(body, {'completion changed': (b) => b !== null && !b.error});
}

const session = {sesskey: null, iteration: 0};

/**
 * Plateau: one learner per virtual user, mostly cached requests, every tenth after a state change.
 */
export function plateau() {
    if (session.sesskey === null) {
        // Learner 0 is reserved for the race scenario.
        session.sesskey = login(USER_PREFIX + (1 + ((exec.vu.idInTest - 1) % Math.max(1, USERS - 1))));
    }
    session.iteration++;
    if (session.iteration % 10 === 0 && PAGE_CMIDS.length) {
        // One fixed activity per virtual user, alternately complete and incomplete: every change is a real one.
        const cmid = PAGE_CMIDS[(exec.vu.idInTest - 1) % PAGE_CMIDS.length];
        setCompletion(session.sesskey, cmid, (session.iteration / 10) % 2 === 1);
        commentary(session.sesskey, regenerated);
    } else {
        commentary(session.sesskey, cached);
    }
    sleep(0.2);
}

/**
 * Race: many sessions of the same learner request the commentary at the same moment after a change.
 */
export function race() {
    const sesskey = login(USER_PREFIX + '0');
    // Wait for a common moment, well after every session has logged in.
    const barrier = exec.scenario.startTime + 15000;
    // One session changes the state shortly before the barrier, so the cache is dirty when everyone asks.
    if (exec.vu.iterationInScenario === 0 && exec.scenario.iterationInTest === 0 && PAGE_CMIDS.length) {
        sleep(Math.max(0, barrier - 3000 - Date.now()) / 1000);
        setCompletion(sesskey, PAGE_CMIDS[0], true);
    }
    sleep(Math.max(0, barrier - Date.now()) / 1000);
    const data = commentary(sesskey, raced);
    check(data, {'race answer has blocks': (d) => d !== null && d.blocks.length > 0});
}
