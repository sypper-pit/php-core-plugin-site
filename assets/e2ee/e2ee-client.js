// E2EE Client Module
window.E2EEClient = (function() {
    let dek = null;
    let autoLockTimer = null;

    function getSodium() {
        const s = (typeof window !== 'undefined' && window.sodium) ? window.sodium : ((typeof self !== 'undefined' && self.sodium) ? self.sodium : (typeof sodium !== 'undefined' ? sodium : null));
        if (!s) {
            throw new Error('Sodium library is not loaded');
        }
        return s;
    }

    function resetAutoLock() {
        if (autoLockTimer) clearTimeout(autoLockTimer);
        autoLockTimer = setTimeout(lock, 15 * 60 * 1000); // 15 mins
    }

    window.addEventListener('mousemove', resetAutoLock);
    window.addEventListener('keydown', resetAutoLock);
    window.addEventListener('pagehide', lock);

    function lock() {
        const s = (typeof window !== 'undefined' && window.sodium) ? window.sodium : ((typeof self !== 'undefined' && self.sodium) ? self.sodium : (typeof sodium !== 'undefined' ? sodium : null));
        if (dek && s && typeof s.memzero === 'function') {
            s.memzero(dek);
        }
        dek = null;
        console.log("E2EE Client locked.");
    }

    async function initClient(masterPassword) {
        const sodium = getSodium();
        await sodium.ready;

        if (typeof sodium.crypto_pwhash !== 'function') {
            throw new Error('Argon2id KDF (crypto_pwhash) is missing in the loaded libsodium library. Please hard-refresh your browser cache (Ctrl+F5).');
        }

        const saltBytes = sodium.crypto_pwhash_SALTBYTES || 16;
        const ops = sodium.crypto_pwhash_OPSLIMIT_MODERATE || 3;
        const mem = sodium.crypto_pwhash_MEMLIMIT_MODERATE || 268435456;
        const alg = sodium.crypto_pwhash_ALG_ARGON2ID13 || 2;
        const npub = sodium.crypto_aead_xchacha20poly1305_ietf_NPUBBYTES || 24;

        const salt = sodium.randombytes_buf(saltBytes);

        const kek = sodium.crypto_pwhash(
            32,
            masterPassword,
            salt,
            ops,
            mem,
            alg
        );

        dek = sodium.randombytes_buf(32);
        const nonce = sodium.randombytes_buf(npub);
        const wrappedDek = sodium.crypto_aead_xchacha20poly1305_ietf_encrypt(
            dek,
            sodium.from_string('dek|v1'),
            null,
            nonce,
            kek
        );

        const recoveryKey = sodium.randombytes_buf(32);
        const recNonce = sodium.randombytes_buf(npub);
        const wrappedRec = sodium.crypto_aead_xchacha20poly1305_ietf_encrypt(
            dek,
            sodium.from_string('rec|v1'),
            null,
            recNonce,
            recoveryKey
        );

        sodium.memzero(kek);
        resetAutoLock();

        return {
            envelope: {
                v: 1,
                alg: 'xchacha20poly1305-ietf+secretstream',
                kdf: {
                    name: 'argon2id13',
                    salt: sodium.to_base64(salt),
                    ops: ops,
                    mem: mem
                },
                wrap_dek: {
                    nonce: sodium.to_base64(nonce),
                    ct: sodium.to_base64(wrappedDek)
                },
                wrap_recovery: {
                    nonce: sodium.to_base64(recNonce),
                    ct: sodium.to_base64(wrappedRec)
                }
            },
            recoveryKey: sodium.to_base64(recoveryKey)
        };
    }

    async function unlockClient(masterPassword, envelope) {
        const sodium = getSodium();
        await sodium.ready;

        if (typeof sodium.crypto_pwhash !== 'function') {
            throw new Error('Argon2id KDF (crypto_pwhash) is missing in the loaded libsodium library. Please hard-refresh your browser cache (Ctrl+F5).');
        }

        const alg = sodium.crypto_pwhash_ALG_ARGON2ID13 || 2;
        const salt = sodium.from_base64(envelope.kdf.salt);
        const kek = sodium.crypto_pwhash(
            32,
            masterPassword,
            salt,
            envelope.kdf.ops,
            envelope.kdf.mem,
            alg
        );

        const nonce = sodium.from_base64(envelope.wrap_dek.nonce);
        const ct = sodium.from_base64(envelope.wrap_dek.ct);

        try {
            dek = sodium.crypto_aead_xchacha20poly1305_ietf_decrypt(
                null,
                ct,
                sodium.from_string('dek|v1'),
                nonce,
                kek
            );
            sodium.memzero(kek);
            resetAutoLock();
            return true;
        } catch (e) {
            sodium.memzero(kek);
            return false;
        }
    }

    return {
        initClient,
        unlockClient,
        lock
    };
})();
