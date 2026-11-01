import http from 'k6/http';
import { check, sleep } from 'k6';

// Sign in once, then time the customer list, search, one customer, and the dispatch week.
// Run with: k6 run -e BASE_URL=http://127.0.0.1:8099 -e EMAIL=... -e PASSWORD=... tests/load/pages.js
export const options = {
    vus: 1,
    iterations: 20,
    thresholds: {
        'http_req_duration{page:customers}': ['p(95)<500'],
        'http_req_duration{page:search}': ['p(95)<300'],
        'http_req_duration{page:customer}': ['p(95)<800'],
        'http_req_duration{page:dispatch}': ['p(95)<500'],
    },
};

export function setup() {
    const base = __ENV.BASE_URL;
    const login = http.get(`${base}/login`);
    const token = login.body.match(/name="csrf-token" content="([^"]+)"/)?.[1]
        || login.body.match(/csrf-token" content="([^"]+)"/)?.[1];
    const signed = http.post(`${base}/login`, {
        email: __ENV.EMAIL,
        password: __ENV.PASSWORD,
        _token: token,
    }, { redirects: 0 });
    check(signed, { 'signed in': (response) => response.status === 302 });

    return { cookie: signed.cookies, base };
}

export default function (data) {
    const jar = http.cookieJar();
    const base = data.base;
    for (const [page, path] of [
        ['customers', '/customers'],
        ['search', '/customers?q=LD-000010'],
        ['customer', '/customers/1'],
        ['dispatch', '/dispatch?mode=week'],
    ]) {
        const response = http.get(`${base}${path}`, { tags: { page } });
        check(response, { [`${page} ok`]: (row) => row.status === 200 });
    }
    sleep(0.2);
}
