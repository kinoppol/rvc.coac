const BASE = '.'; // relative to /public/ — app.html and index.php share this directory

let csrfToken = null;

// The JS/CSS build this page was loaded with; the server names the current one on
// every response, so after a deploy the app reloads itself at the next page change.
const PAGE_BUILD = document.querySelector('meta[name="app-build"]')?.content || null;
let staleBuild = false;

export function isStaleBuild() {
    return staleBuild;
}

export function setCsrfToken(token) {
    csrfToken = token;
}

export function getCsrfToken() {
    return csrfToken;
}

async function request(method, path, body) {
    const opts = {
        method,
        headers: {},
        credentials: 'same-origin',
    };
    if (csrfToken && method !== 'GET') {
        opts.headers['X-CSRF-Token'] = csrfToken;
    }
    if (body !== undefined) {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(body);
    }

    const res = await fetch(`${BASE}/api${path}`, opts);
    const build = res.headers.get('X-App-Build');
    if (build && PAGE_BUILD && build !== PAGE_BUILD) staleBuild = true;

    if (res.status === 401) {
        window.dispatchEvent(new CustomEvent('app:unauthorized'));
        throw new Error('กรุณาเข้าสู่ระบบ');
    }

    const contentType = res.headers.get('Content-Type') || '';
    if (!contentType.includes('application/json')) {
        if (!res.ok) throw new Error('เกิดข้อผิดพลาด (' + res.status + ')');
        return res;
    }

    const data = await res.json();
    if (!res.ok) {
        throw Object.assign(new Error(data.error || 'เกิดข้อผิดพลาด'), { status: res.status });
    }
    return data;
}

export const api = {
    get: (path) => request('GET', path),
    post: (path, body) => request('POST', path, body ?? {}),
    put: (path, body) => request('PUT', path, body ?? {}),
    del: (path) => request('DELETE', path),
    downloadUrl: (path) => `${BASE}/api${path}`,
};
