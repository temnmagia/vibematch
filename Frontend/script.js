const clientId = 'ff991d6f6c224110be45fa35a875b85b';
const redirectUri = 'http://127.0.0.1:80/kursova/API/callback.php';
const scopes = 'user-read-private user-read-email user-top-read';

function generateRandomString(length) {
    const characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    return Array.from(crypto.getRandomValues(new Uint8Array(length)))
        .map(x => characters[x % characters.length])
        .join('');
}

async function generateCodeChallenge(codeVerifier) {
    const data = new TextEncoder().encode(codeVerifier);
    const digest = await crypto.subtle.digest('SHA-256', data);
    return btoa(String.fromCharCode(...new Uint8Array(digest)))
        .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

document.getElementById('loginBtn').addEventListener('click', async () => {
    const codeVerifier = generateRandomString(64);
    const codeChallenge = await generateCodeChallenge(codeVerifier);

    localStorage.setItem('code_verifier', codeVerifier);

    const params = new URLSearchParams({
        response_type: 'code',
        client_id: clientId,
        redirect_uri: redirectUri,
        code_challenge_method: 'S256',
        code_challenge: codeChallenge,
        scope: scopes
    });

    window.location.href = `https://accounts.spotify.com/authorize?${params.toString()}`;
});
