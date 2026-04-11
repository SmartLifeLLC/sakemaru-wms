export function getItem(key) {
    try {
        const value = localStorage.getItem(key);
        if (value === null) return null;
        return JSON.parse(value);
    } catch {
        return localStorage.getItem(key);
    }
}

export function setItem(key, value) {
    try {
        localStorage.setItem(key, typeof value === 'string' ? value : JSON.stringify(value));
    } catch (e) {
        console.error('localStorage write failed:', e);
    }
}

export function removeItem(key) {
    localStorage.removeItem(key);
}
