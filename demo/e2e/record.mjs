// Plays the demo scenario in Chromium, twice (protection off, then on), and records a video of it.
// Each step waits for what it expects to see, so a broken demo fails here instead of producing a misleading video.
// Run it through the Playwright image, which ships the browser matching package.json (see ../README.md).

import { chromium } from 'playwright';

const base = process.env.DEMO_URL ?? 'http://localhost:8091';
const pause = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const browser = await chromium.launch({ slowMo: 120 });
const context = await browser.newContext({
    viewport: { width: 1280, height: 860 },
    recordVideo: { dir: 'output/', size: { width: 1280, height: 860 } },
});
const page = await context.newPage();

async function caption(text) {
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
    await page.goto(`${base}/?protection=${protection ? 1 : 0}`);
    await page.getByRole('button', { name: 'Réinitialiser la démo' }).click();
    await page.waitForURL(/\?protection=/);

    const alice = page.frameLocator('iframe[name=alice]');
    const bob = page.frameLocator('iframe[name=bob]');
    const history = page.frameLocator('iframe[name=history]');

    await caption(protection ? 'Protection activée' : 'Protection désactivée');
    await caption('Alice et Bob ouvrent le même produit (prix : 89,90 €)');

    await caption('Bob passe le prix à 99,00 € et enregistre');
    await bob.getByLabel('Prix', { exact: true }).fill('99,00');
    await bob.getByRole('button', { name: 'Enregistrer' }).click();
    await bob.getByText('Enregistré.').waitFor();

    await caption('Alice voit toujours 89,90 € : elle change seulement le nom');
    await alice.getByLabel('Nom', { exact: true }).fill('Clavier mécanique sans fil');
    await alice.getByRole('button', { name: 'Enregistrer' }).click();

    if (protection) {
        await alice.getByText('Valeur actuelle : 99,00').waitFor();
        await caption('Alice est prévenue et voit le prix de Bob ; son nouveau nom est conservé');
        await pause(1500);
    } else {
        await alice.getByText('Enregistré.').waitFor();
        await page.locator('.history').scrollIntoViewIfNeeded();
        await history.locator('tbody tr').first().getByText('Alice').waitFor();
        await caption('Enregistré sans erreur… et l’historique montre qu’Alice a remis 89,90 €');
        await pause(1500);
        await page.evaluate(() => window.scrollTo(0, 0));
    }
}

try {
    await scenario(false);
    await scenario(true);
} finally {
    await context.close();
    await browser.close();
}
