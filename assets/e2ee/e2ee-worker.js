// E2EE Argon2id KDF Web Worker
self.onmessage = async function(e) {
    const { action, password, salt, ops, mem } = e.data;
    if (action === 'deriveKey') {
        if (typeof sodium === 'undefined') {
            importScripts('/libs/vendor-js/sodium/sodium.js');
            await sodium.ready;
        }
        const saltBytes = sodium.from_base64(salt);
        const kek = sodium.crypto_pwhash(
            32,
            password,
            saltBytes,
            ops,
            mem,
            sodium.crypto_pwhash_ALG_ARGON2ID13
        );
        self.postMessage({ kek: sodium.to_base64(kek) });
    }
};
