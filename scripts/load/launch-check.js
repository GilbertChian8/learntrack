/* global __ENV */
import { check, fail } from 'k6';
import crypto from 'k6/crypto';
import encoding from 'k6/encoding';
import exec from 'k6/execution';
import http from 'k6/http';
import { Counter } from 'k6/metrics';

const BASE_URL = required('BASE_URL').replace(/\/+$/, '');
const EDUCATOR_EMAIL = required('EDUCATOR_EMAIL');
const EDUCATOR_PASSWORD = required('EDUCATOR_PASSWORD');
const GROUP_NAME = __ENV.GROUP_NAME || 'Pediatrics Group B';
const SUMMARY_FILE = __ENV.SUMMARY_FILE || 'summary.json';

const REDIRECT_URI = 'http://localhost:9999/callback';
const GROUP_PROGRESS_TARGET_MS = 300;
const MCP_TOOL_TARGET_MS = 1000;
const TOOLS = [
    'list_my_groups',
    'get_group_progress',
    'find_learners_behind',
    'get_learner_summary',
    'find_hardest_assignments',
];
const OPERATIONS = [
    { op: 'group_progress', count: 200, targetMs: GROUP_PROGRESS_TARGET_MS },
    ...TOOLS.map((op) => ({ op, count: 100, targetMs: MCP_TOOL_TARGET_MS })),
];

const GROUP_PROGRESS_QUERY = `query ($groupId: ID!) {
  groupProgress(groupId: $groupId) {
    group { id name institution }
    generatedAt
    assignments { id title topic type dueAt scope targetedCount completedCount completionRate averageScore }
    learners {
      learner { id name }
      behind
      reasons
      averageScore
      counts { notStarted inProgress completed overdue }
      statuses { assignmentId status score completedAt }
    }
  }
}`;

const rateLimited = new Counter('rate_limited');

http.setResponseCallback(http.expectedStatuses({ min: 200, max: 299 }));

export const options = {
    setupTimeout: '2m',
    summaryTrendStats: ['min', 'p(50)', 'p(95)', 'p(99)', 'max', 'count'],
    scenarios: {
        group_progress: {
            executor: 'shared-iterations',
            exec: 'groupProgress',
            vus: 10,
            iterations: 200,
            maxDuration: '1m',
        },
        mcp_tools: {
            executor: 'constant-arrival-rate',
            exec: 'mcpTool',
            rate: 50,
            timeUnit: '1m',
            duration: '10m',
            preAllocatedVUs: 5,
            maxVUs: 10,
            startTime: '90s',
        },
    },
    thresholds: Object.assign(
        {
            http_req_failed: ['rate==0'],
            checks: ['rate==1'],
            rate_limited: ['count==0'],
        },
        ...OPERATIONS.map(({ op, count, targetMs }) => ({
            [`http_req_duration{op:${op}}`]: [`p(95)<${targetMs}`],
            [`http_reqs{op:${op}}`]: [`count==${count}`],
        })),
    ),
};

export function setup() {
    const mcpToken = oauthToken();
    const apiToken = apiLogin();
    const groups = apiGet(apiToken, '/api/v1/groups?limit=200').data;
    const groupId = findGroupId(groups);
    const learnerId = findLearnerId(apiToken, groups, groupId);

    console.log(`Group ${groupId} (${GROUP_NAME}), learner ${learnerId}.`);

    return { apiToken, mcpToken, groupId, learnerId };
}

export function groupProgress(data) {
    const response = http.post(
        `${BASE_URL}/graphql`,
        JSON.stringify({
            query: GROUP_PROGRESS_QUERY,
            variables: { groupId: String(data.groupId) },
        }),
        { headers: apiHeaders(data.apiToken), tags: { op: 'group_progress' } },
    );

    countRateLimited(response);
    check(response, {
        'group_progress answers 200': (r) => r.status === 200,
        'group_progress answers the group without errors': (r) => {
            const body = json(r);

            return (
                body !== null &&
                body.errors === undefined &&
                body.data?.groupProgress?.group?.id === String(data.groupId)
            );
        },
    });
}

export function mcpTool(data) {
    const iteration = exec.scenario.iterationInTest;
    const name = TOOLS[iteration % TOOLS.length];
    const response = mcp(
        data.mcpToken,
        'tools/call',
        { name, arguments: toolArguments(name, data) },
        iteration + 1,
        name,
    );

    countRateLimited(response);
    check(response, {
        [`${name} answers 200`]: (r) => r.status === 200,
        [`${name} answers a result, not an error`]: (r) => {
            const body = json(r);

            return (
                body !== null &&
                body.error === undefined &&
                body.result?.isError !== true &&
                body.result?.structuredContent !== undefined
            );
        },
    });
}

export function handleSummary(data) {
    delete data.setup_data;

    const text = report(data);

    return {
        stdout: text,
        [SUMMARY_FILE]: JSON.stringify(data, null, 2),
        [SUMMARY_FILE.replace(/\.json$/, '') + '.txt']: text,
    };
}

function oauthToken() {
    const resource = getJson(
        `${BASE_URL}/.well-known/oauth-protected-resource/mcp`,
    );
    const server = getJson(
        `${resource.authorization_servers[0]}/.well-known/oauth-authorization-server`,
    );

    if (server.issuer !== BASE_URL) {
        fail(
            `The discovery documents name ${server.issuer}, not BASE_URL. Set BASE_URL to the app's public URL.`,
        );
    }

    const registration = expectStatus(
        http.post(
            server.registration_endpoint,
            JSON.stringify({
                client_name: 'LearnTrack load check',
                redirect_uris: [REDIRECT_URI],
            }),
            {
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
                tags: { op: 'setup' },
            },
        ),
        201,
        'client registration',
    );
    const clientId = registration.json('client_id');

    const verifier = encoding.b64encode(crypto.randomBytes(32), 'rawurl');
    const state = encoding.b64encode(crypto.randomBytes(16), 'rawurl');
    const authorizeUrl = `${server.authorization_endpoint}?${query({
        client_id: clientId,
        redirect_uri: REDIRECT_URI,
        response_type: 'code',
        scope: 'mcp:use',
        state,
        code_challenge: crypto.sha256(verifier, 'base64rawurl'),
        code_challenge_method: 'S256',
    })}`;

    const toLogin = expectStatus(
        redirectless('GET', authorizeUrl, null, {}, 302),
        302,
        'authorize as a guest',
    );
    const loginPage = expectStatus(
        http.get(toLogin.headers.Location, { tags: { op: 'setup' } }),
        200,
        'login page',
    );

    const login = expectStatus(
        redirectless(
            'POST',
            toLogin.headers.Location,
            JSON.stringify({
                email: EDUCATOR_EMAIL,
                password: EDUCATOR_PASSWORD,
                remember: false,
            }),
            {
                'Content-Type': 'application/json',
                Accept: 'text/html, application/xhtml+xml',
                'X-Inertia': 'true',
                'X-Inertia-Version': inertiaPage(loginPage).version,
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(toLogin.headers.Location),
            },
            409,
        ),
        409,
        'web login',
    );

    const consentPage = expectStatus(
        http.get(login.headers['X-Inertia-Location'], {
            tags: { op: 'setup' },
        }),
        200,
        'consent page',
    );
    const consent = inertiaPage(consentPage);

    if (consent.component !== 'mcp/consent') {
        fail(
            `The consent step showed ${consent.component}, not mcp/consent. Use an educator account.`,
        );
    }

    const approval = expectStatus(
        redirectless(
            'POST',
            server.authorization_endpoint,
            {
                _token: consent.props.csrfToken,
                state: consent.props.state,
                auth_token: consent.props.authToken,
            },
            {},
            302,
        ),
        302,
        'consent approval',
    );
    const callback = parseQuery(approval.headers.Location, REDIRECT_URI);

    if (callback.state !== state || callback.code === undefined) {
        fail(
            'The approval did not redirect back with a code and the same state.',
        );
    }

    const tokens = expectStatus(
        http.post(
            server.token_endpoint,
            {
                grant_type: 'authorization_code',
                client_id: clientId,
                redirect_uri: REDIRECT_URI,
                code_verifier: verifier,
                code: callback.code,
            },
            { headers: { Accept: 'application/json' }, tags: { op: 'setup' } },
        ),
        200,
        'token exchange',
    );
    const mcpToken = tokens.json('access_token');

    expectStatus(
        mcp(
            mcpToken,
            'initialize',
            {
                protocolVersion: '2025-11-25',
                capabilities: {},
                clientInfo: { name: 'LearnTrack load check', version: '1.0.0' },
            },
            1,
            'setup',
        ),
        200,
        'MCP initialize',
    );

    const listed = expectStatus(
        mcp(mcpToken, 'tools/list', {}, 2, 'setup'),
        200,
        'MCP tools/list',
    ).json('result.tools.#.name');
    const missing = TOOLS.filter((tool) => !listed.includes(tool));

    if (missing.length > 0) {
        fail(`tools/list does not list ${missing.join(', ')}.`);
    }

    return mcpToken;
}

function apiLogin() {
    const response = http.post(
        `${BASE_URL}/api/v1/auth/login`,
        JSON.stringify({ email: EDUCATOR_EMAIL, password: EDUCATOR_PASSWORD }),
        {
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            tags: { op: 'setup' },
        },
    );

    return expectStatus(response, 200, 'API login').json('data.token');
}

function findGroupId(groups) {
    const group = groups.find((candidate) => candidate.name === GROUP_NAME);

    if (group === undefined) {
        fail(`${EDUCATOR_EMAIL} does not teach ${GROUP_NAME}.`);
    }

    return group.id;
}

/**
 * The learner of the group who is in the most of the educator's groups, so get_learner_summary has the most to answer.
 */
function findLearnerId(apiToken, groups, groupId) {
    const memberships = {};
    let candidates = [];

    for (const group of groups) {
        const learnerIds = apiGet(
            apiToken,
            `/api/v1/groups/${group.id}`,
        ).data.learners.map((learner) => learner.id);

        for (const learnerId of learnerIds) {
            memberships[learnerId] = (memberships[learnerId] || 0) + 1;
        }

        if (group.id === groupId) {
            candidates = learnerIds;
        }
    }

    return candidates.sort(
        (a, b) => memberships[b] - memberships[a] || a - b,
    )[0];
}

function toolArguments(name, data) {
    switch (name) {
        case 'list_my_groups':
            return {};
        case 'get_learner_summary':
            return { learner_id: data.learnerId };
        default:
            return { group_id: data.groupId };
    }
}

function mcp(token, method, params, id, op) {
    return http.post(
        `${BASE_URL}/mcp`,
        JSON.stringify({ jsonrpc: '2.0', id, method, params }),
        {
            headers: {
                Authorization: `Bearer ${token}`,
                'Content-Type': 'application/json',
                Accept: 'application/json, text/event-stream',
            },
            tags: { op },
        },
    );
}

function apiGet(token, path) {
    return expectStatus(
        http.get(`${BASE_URL}${path}`, {
            headers: apiHeaders(token),
            tags: { op: 'setup' },
        }),
        200,
        `GET ${path}`,
    ).json();
}

function apiHeaders(token) {
    return {
        Authorization: `Bearer ${token}`,
        'Content-Type': 'application/json',
        Accept: 'application/json',
    };
}

function getJson(url) {
    return expectStatus(
        http.get(url, {
            headers: { Accept: 'application/json' },
            tags: { op: 'setup' },
        }),
        200,
        `GET ${url}`,
    ).json();
}

function redirectless(method, url, body, headers, expected) {
    return http.request(method, url, body, {
        headers,
        redirects: 0,
        responseCallback: http.expectedStatuses(expected),
        tags: { op: 'setup' },
    });
}

function expectStatus(response, status, step) {
    if (response.status !== status) {
        fail(`${step}: expected HTTP ${status}, got ${response.status}.`);
    }

    return response;
}

function inertiaPage(response) {
    const match =
        /<script data-page="app" type="application\/json">([\s\S]*?)<\/script>/.exec(
            response.body,
        );

    if (match === null) {
        fail(`${response.url} has no Inertia page data.`);
    }

    return JSON.parse(match[1]);
}

function xsrfToken(url) {
    const cookie = http.cookieJar().cookiesForURL(url)['XSRF-TOKEN'];

    if (cookie === undefined) {
        fail('The login page set no XSRF-TOKEN cookie.');
    }

    return decodeURIComponent(cookie[0]);
}

function parseQuery(url, prefix) {
    if (url === undefined || !url.startsWith(`${prefix}?`)) {
        fail(`Expected a redirect to ${prefix}, got ${url}.`);
    }

    const values = {};

    for (const pair of url.slice(prefix.length + 1).split('&')) {
        const [key, value = ''] = pair.split('=');
        values[decodeURIComponent(key)] = decodeURIComponent(
            value.replace(/\+/g, ' '),
        );
    }

    return values;
}

function query(values) {
    return Object.entries(values)
        .map(
            ([key, value]) =>
                `${encodeURIComponent(key)}=${encodeURIComponent(value)}`,
        )
        .join('&');
}

function json(response) {
    try {
        return response.json();
    } catch {
        return null;
    }
}

function countRateLimited(response) {
    if (response.status === 429) {
        rateLimited.add(1);
    }
}

function required(name) {
    const value = __ENV[name];

    if (value === undefined || value === '') {
        throw new Error(`Set ${name}.`);
    }

    return value;
}

function report(data) {
    const metric = (name) => data.metrics[name] || { values: {} };
    const ms = (value) => (value === undefined ? '-' : value.toFixed(1));
    const lines = [
        '',
        `LearnTrack launch check, ${new Date().toISOString()}`,
        '',
        'operation                  count    p50 ms    p95 ms    p99 ms    max ms   target    verdict',
    ];

    for (const { op, count, targetMs } of OPERATIONS) {
        const actual = metric(`http_reqs{op:${op}}`).values.count || 0;
        const values =
            actual > 0 ? metric(`http_req_duration{op:${op}}`).values : {};
        const met = actual === count && values['p(95)'] < targetMs;

        lines.push(
            [
                op.padEnd(24),
                `${actual}/${count}`.padStart(7),
                ms(values['p(50)']).padStart(9),
                ms(values['p(95)']).padStart(9),
                ms(values['p(99)']).padStart(9),
                ms(values.max).padStart(9),
                `<${targetMs}`.padStart(8),
                met ? '   met' : '   not met',
            ].join(' '),
        );
    }

    const failed = metric('http_req_failed').values;
    const checks = metric('checks').values;
    const failedThresholds = Object.entries(data.metrics).flatMap(
        ([name, details]) =>
            Object.entries(details.thresholds || {})
                .filter(([, result]) => !result.ok)
                .map(([expression]) => `${name} ${expression}`),
    );

    lines.push(
        '',
        `Failed requests: ${failed.passes || 0} of ${(failed.passes || 0) + (failed.fails || 0)}`,
        `429 answers: ${metric('rate_limited').values.count || 0}`,
        `Checks passed: ${checks.passes || 0} of ${(checks.passes || 0) + (checks.fails || 0)}`,
        failedThresholds.length === 0
            ? 'Thresholds: all passed'
            : `Thresholds failed: ${failedThresholds.join('; ')}`,
        '',
    );

    return lines.join('\n');
}
