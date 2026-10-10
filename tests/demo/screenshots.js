// Screenshots of the module's pages on a test instance (Puppeteer). Usage:
//   node screenshots.js <base-url> <admin-email> <admin-password> <output-dir>
const puppeteer = require('puppeteer');
const fs = require('fs');

(async () => {
    const [base, email, password, out] = process.argv.slice(2);
    fs.mkdirSync(out, { recursive: true });
    const browser = await puppeteer.launch({ args: ['--no-sandbox'] });
    const page = await browser.newPage();
    await page.setViewport({ width: 1280, height: 900 });
    await page.goto(base + '/login', { waitUntil: 'networkidle2' });
    await page.type('input[name=email]', email);
    await page.type('input[name=password]', password);
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);

    const shot = async (name) => page.screenshot({ path: `${out}/${name}.png`, fullPage: true });

    await page.goto(base + '/workflow-packs', { waitUntil: 'networkidle2' });
    await shot('liste-packs');

    await page.goto(base + '/workflow-packs/import', { waitUntil: 'networkidle2' });
    await Promise.all([page.waitForNavigation(), page.click('input[name=bundled] + button')]);
    await shot('import-etape2');

    // both mailboxes, then preview
    await page.$$eval('input[name="mailboxes[]"]', (els) => els.forEach((el) => { el.checked = true; }));
    await page.$eval('input[name="params[security_email]"]', (el) => { el.value = 'secu@demo.test'; });
    await Promise.all([page.waitForNavigation(), page.click('form.wp-form button[type=submit]')]);
    await shot('import-previsualisation');

    await page.setViewport({ width: 390, height: 844, isMobile: true });
    await shot('import-previsualisation-mobile');
    await page.setViewport({ width: 1280, height: 900 });

    await page.goto(base + '/workflow-packs/export', { waitUntil: 'networkidle2' });
    await shot('export');
    await page.goto(base + '/workflow-packs/diagnostic', { waitUntil: 'networkidle2' });
    await shot('diagnostic');
    await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
