import asyncio, os
from playwright.async_api import async_playwright

OUT = '/tmp/wiki_shots'
os.makedirs(OUT, exist_ok=True)
BASE = 'https://appvaley.com/mall/'

async def main():
    async with async_playwright() as p:
        b = await p.chromium.launch(headless=True)
        pg = await b.new_page(viewport={'width': 1400, 'height': 860})
        await pg.goto(BASE, wait_until='domcontentloaded')
        # login
        await pg.wait_for_selector('input[type=email], input[type=text]', timeout=15000)
        await pg.fill('input[type=email], input[type=text]', 'superadmin@razzakplaza.com')
        await pg.fill('input[type=password]', 'admin12345')
        await pg.click('button:has-text("লগ ইন"), button[type=submit]')
        await pg.wait_for_timeout(6000)
        assert 'মল' in await pg.title() or await pg.locator('.page-head, main').count() > 0, 'login failed'

        async def shot(name, wait=3500):
            await pg.wait_for_timeout(wait)
            await pg.screenshot(path=f'{OUT}/{name}.png')
            print('saved', name)

        # 1 dashboard
        await pg.goto(BASE + '#/mall?tab=dashboard', wait_until='domcontentloaded')
        await shot('01_dashboard')

        # 2 meters + info panel
        await pg.goto(BASE + '#/mall?tab=meters', wait_until='domcontentloaded')
        await pg.wait_for_timeout(4000)
        await pg.click('.ssel-btn')
        await pg.wait_for_timeout(600)
        await pg.fill('.ssel-q', 'A-102')
        await pg.wait_for_timeout(600)
        opts = pg.locator('.ssel-panel .ssel-item, .ssel-panel div')
        for i in range(await opts.count()):
            txt = await opts.nth(i).inner_text()
            if 'A-102' in txt and '—' in txt:
                await opts.nth(i).click()
                break
        await shot('02_meters_panel')

        # 3 collect modal (step 1)
        await pg.goto(BASE + '#/mall?tab=payments', wait_until='domcontentloaded')
        await pg.wait_for_timeout(4000)
        await pg.click('button:has-text("আদায়")')
        await pg.wait_for_timeout(2000)
        await shot('03_collect_step1')
        # pick a defaulter shop inside the modal (A-105 has unpaid bills)
        await pg.click('.overlay .ssel-btn')
        await pg.wait_for_timeout(600)
        await pg.fill('.ssel-q', 'A-105')
        await pg.wait_for_timeout(600)
        opts = pg.locator('.ssel-panel .ssel-item, .ssel-panel div')
        for i in range(await opts.count()):
            txt = await opts.nth(i).inner_text()
            if 'A-105' in txt and '—' in txt:
                await opts.nth(i).click()
                break
        await pg.wait_for_timeout(1500)
        # click the unpaid bill row -> pay modal (JS click bypasses hit-testing)
        await pg.eval_on_selector('.overlay .modal-b div[style*="cursor"]', 'el => el.click()')
        await pg.wait_for_timeout(1500)
        await shot('04_collect_pay')

        # close modal, then payments history panel
        await pg.click('.overlay .modal-h button, .overlay .close')
        await pg.wait_for_timeout(800)
        await pg.click('.page-head .ssel-btn')
        await pg.wait_for_timeout(600)
        await pg.fill('.ssel-q', 'A-102')
        await pg.wait_for_timeout(600)
        opts = pg.locator('.ssel-panel .ssel-item, .ssel-panel div')
        for i in range(await opts.count()):
            txt = await opts.nth(i).inner_text()
            if 'A-102' in txt and '—' in txt:
                await opts.nth(i).click()
                break
        await shot('05_payment_history')

        # 5 COA
        await pg.goto(BASE + '#/mall?tab=coa', wait_until='domcontentloaded')
        await shot('06_coa')

        # 6 invoices (open first invoice detail preview)
        await pg.goto(BASE + '#/mall?tab=invoices', wait_until='domcontentloaded')
        await pg.wait_for_timeout(4000)
        try:
            await pg.click('button[title*="👁"], button:has-text("👁")')
            await pg.wait_for_timeout(2000)
            await shot('07_invoice_preview')
        except Exception as e:
            print('invoice preview skip:', e)
            await shot('07_invoices')

        # 7 journal
        await pg.goto(BASE + '#/mall?tab=journal', wait_until='domcontentloaded')
        await shot('08_journal')

        # 8 wiki (open a couple items)
        await pg.goto(BASE + '#/wiki', wait_until='domcontentloaded')
        await pg.wait_for_timeout(3000)
        await pg.click('main div[style*="cursor:pointer"] >> nth=0')
        await pg.click('main div[style*="cursor:pointer"] >> nth=1')
        await shot('09_wiki')

        await b.close()
        print('DONE')

asyncio.run(main())
