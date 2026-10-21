// E2EE Argon2id KDF Web Worker
self.onmessage = async function(e) {
    const { action, password, salt, ops, mem } = e.data;
    if (action === 'deriveKey') {
        let s = (typeof self !== 'undefined' && self.sodium) || (typeof window !== 'undefined' && window.sodium) || (typeof sodium !== 'undefined' ? sodium : null);
        if (!s) {
            importScripts('/libs/vendor-js/sodium/sodium.js');
            s = (typeof self !== 'undefined' && self.sodium) || (typeof window !== 'undefined' && window.sodium) || (typeof sodium !== 'undefined' ? sodium : null);
        }
        if (s && s.ready) {
            await s.ready;
        }
        if (!s) {
            throw new Error('Sodium library failed to load in Web Worker');
        }
        const saltBytes = s.from_base64(salt);
        const kek = s.crypto_pwhash(
            32,
            password,
            saltBytes,
            ops,
            mem,
            s.crypto_pwhash_ALG_ARGON2ID13
        );
        self.postMessage({ kek: s.to_base64(kek) });
    }
};
