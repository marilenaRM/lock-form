// Plays the scenario with the LiveComponent forms, where every field change re-renders the component and submits
// the form to the server. Each step waits for what it expects to see; any mismatch fails the run.
// Set RECORD=1 to keep a video in output/.

import { chromium } from 'playwright';

const base = process.env.DEMO_URL ?? 'http://localhost:8091';
const pause = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const browser = await chromium.launch({ slowMo: process.env.RECORD ? 120 : 0 });
const context = await browser.newContext({
    viewport: { width: 1280, height: 860 },
    ...(process.env.RECORD ? { recordVideo: { dir: 'output/', size: { width: 1280, height: 860 } } } : {}),
});
const page = await context.newPage();

async function caption(text) {
    console.log(`  ${text}`);
    if (!process.env.RECORD) {
        return;
    }
    await page.evaluate((message) => {
        let banner = document.getElementById('recording-caption');
        if (!banner) {
            banner = document.createElement('div');
            banner.id = 'recording-caption';
            banner.setAttribute('role', 'status');
            Object.assign(banner.style, {
                position: 'fixed', left: '50%', bottom: '24px', transform: 'translateX(-50%)', zIndex: 10,
                padding: '10px 18px', borderRadius: '8px', background: '#1d2433', color: '#fff',
                font: '600 18px system-ui, sans-serif', boxShadow: '0 4px 16px rgba(0,0,0,.25)',
            });
            document.body.append(banner);
        }
        banner.textContent = message;
    }, text);
    await pause(1600);
}

async function scenario(protection) {
    console.log(`LiveComponent, protection ${protection ? 'on' : 'off'}`);
    await page.goto(`${base}/?live=1&protection=${protection ? 1 : 0}`);
    await page.getByRole('button', { name: 'Réinitialiser la démo' }).click();
    await page.waitForURL(/live=1/);

    const alice = page.frameLocator('iframe[name=alice]');
    const bob = page.frameLocator('iframe[name=bob]');
    const history = page.frameLocator('iframe[name=history]');
    const lastSave = history.locator('tbody tr').first();

    await caption(`LiveComponent, protection ${protection ? 'activée' : 'désactivée'}`);

    await caption('Bob passe le prix à 99,00 € et enregistre');
    await bob.getByLabel('Prix', { exact: true }).fill('99,00');
    await bob.getByRole('button', { name: 'Enregistrer' }).click();
    await bob.getByText('Enregistré.').waitFor();
    await lastSave.getByText('Bob').waitFor();

    await caption('Alice change le nom et quitte le champ : le composant se re-rend et soumet le formulaire');
    await alice.getByLabel('Nom', { exact: true }).fill('Clavier mécanique sans fil');
    await alice.getByLabel('Nom', { exact: true }).press('Tab');

    if (!protection) {
        await alice.getByRole('button', { name: 'Enregistrer' }).click();
        await alice.getByText('Enregistré.').waitFor();
        await lastSave.getByText('Alice').waitFor();
        await lastSave.getByText('99.00').waitFor();
        await caption('Sans protection : Alice a remis 89,90 € sans le savoir');

        return;
    }

    const acknowledgement = alice.getByLabel('J’ai pris connaissance de ces changements', { exact: false });
    await acknowledgement.waitFor();
    await caption('Le re-render a déjà détecté le conflit : la case de confirmation apparaît');

    await alice.getByRole('button', { name: 'Enregistrer' }).click();
    await alice.getByText('Valeur actuelle : 99,00').waitFor();
    await caption('Enregistrer est refusé : Alice voit le prix de Bob, son nom est conservé');
    if ((await lastSave.textContent()).includes('Alice')) {
        throw new Error('Alice’s save went through without acknowledging the conflict.');
    }

    await acknowledgement.check();
    await caption('Elle coche la case pour garder ses valeurs malgré tout');
    await alice.getByRole('button', { name: 'Enregistrer' }).click();
    await alice.getByText('Enregistré.').waitFor();
    await lastSave.getByText('Alice').waitFor();
    await caption('Enregistré, en connaissance de cause');
}

try {
    await scenario(false);
    await scenario(true);
    console.log('OK');
} finally {
    await context.close();
    await browser.close();
}
