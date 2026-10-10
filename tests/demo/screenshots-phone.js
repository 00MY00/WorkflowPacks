// Phone screenshots with the Refresh module (390 px): Profile panel (burger) with the module entry, preview cards.
//   node screenshots-phone.js <base-url> <admin-email> <admin-password> <output-dir>
const puppeteer = require('puppeteer');

(async () => {
    const [base, email, password, out] = process.argv.slice(2);
    const browser = await puppeteer.launch({ args: ['--no-sandbox'] });
    const page = await browser.newPage();
    await page.setViewport({ width: 390, height: 844, isMobile: true, hasTouch: true });
    await page.goto(base + '/login', { waitUntil: 'networkidle2' });
    await page.type('input[name=email]', email);
    await page.type('input[name=password]', password);
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);

    await page.goto(base + '/workflow-packs', { waitUntil: 'networkidle2' });
    await new Promise((r) => setTimeout(r, 1500));
    await page.screenshot({ path: `${out}/mobile-liste.png` });
    const me = await page.$('.rf-m-tab-me');
    if (me) {
        await page.evaluate(() => document.querySelector('.rf-m-tab-me').click());
        await new Promise((r) => setTimeout(r, 800));
        await page.screenshot({ path: `${out}/mobile-menu-profil.png` });
        const found = await page.$('.rf-m-acct .wp-m-entry');
        console.log('entry in Profile panel:', !!found);
    } else {
        console.log('no Refresh tab bar');
    }

    await page.goto(base + '/workflow-packs/import', { waitUntil: 'networkidle2' });
    await Promise.all([page.waitForNavigation(), page.click('input[name=bundled] + button')]);
    await page.$$eval('input[name="mailboxes[]"]', (els) => els.forEach((el) => { el.checked = true; }));
    await page.$eval('input[name="params[security_email]"]', (el) => { el.value = 'secu@demo.test'; });
    await Promise.all([page.waitForNavigation(), page.click('form.wp-form button[type=submit]')]);
    await new Promise((r) => setTimeout(r, 1000));
    await page.screenshot({ path: `${out}/mobile-previsualisation.png`, fullPage: false });
    const matrix = await page.$('.wp-matrix');
    if (matrix) {
        await matrix.screenshot({ path: `${out}/mobile-matrice.png` });
    }
    await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
