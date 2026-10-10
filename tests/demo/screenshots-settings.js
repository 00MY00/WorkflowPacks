// Settings page (Manage › Settings › Workflow Packs) and module page with the update banner.
//   node screenshots-settings.js <base-url> <admin-email> <admin-password> <output-dir>
const puppeteer = require('puppeteer');
(async () => {
    const [base, email, password, out] = process.argv.slice(2);
    const browser = await puppeteer.launch({ args: ['--no-sandbox'] });
    const page = await browser.newPage();
    await page.setViewport({ width: 1280, height: 900 });
    await page.goto(base + '/login', { waitUntil: 'networkidle2' });
    await page.type('input[name=email]', email);
    await page.type('input[name=password]', password);
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    await page.goto(base + '/app-settings/workflowpacks', { waitUntil: 'networkidle2' });
    await page.screenshot({ path: `${out}/reglages-mise-a-jour.png` });
    await page.goto(base + '/workflow-packs', { waitUntil: 'networkidle2' });
    await page.screenshot({ path: `${out}/bandeau-mise-a-jour.png`, clip: { x: 0, y: 0, width: 1280, height: 360 } });
    await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
