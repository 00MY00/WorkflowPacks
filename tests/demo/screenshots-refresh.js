// Screenshot of the list page with the Refresh module active (same arguments as screenshots.js).
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
    await page.goto(base + '/workflow-packs', { waitUntil: 'networkidle2' });
    await new Promise((r) => setTimeout(r, 1500));
    await page.screenshot({ path: `${out}/liste-packs-refresh.png`, fullPage: false });
    await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
