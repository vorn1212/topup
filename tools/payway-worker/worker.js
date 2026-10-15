'use strict';

/**
 * ABA PayWay link worker.
 * Input (environment): PAYWAY_LINK_URL, DEPOSIT_AMOUNT, QR_OUT, EVIDENCE_OUT, SESSION_SECONDS, HEADLESS
 * Output: one JSON object per line on stdout:
 *   {"event":"started"}
 *   {"event":"qr_ready","path":"..."}
 *   {"event":"paid","amount":"25.00"}      (amount is null when it could not be read)
 *   {"event":"expired"}
 *   {"event":"error","message":"..."}
 */

const { chromium } = require('playwright');

const cfg = {
  url: process.env.PAYWAY_LINK_URL || '',
  amount: process.env.DEPOSIT_AMOUNT || '',
  qrOut: process.env.QR_OUT || '',
  evidenceOut: process.env.EVIDENCE_OUT || '',
  sessionMs: (parseInt(process.env.SESSION_SECONDS || '200', 10) || 200) * 1000,
  headless: process.env.HEADLESS !== 'false',
};

function emit(obj) {
  process.stdout.write(JSON.stringify(obj) + '\n');
}

function fail(message) {
  emit({ event: 'error', message: String(message).slice(0, 300) });
}

/** First "12.34 USD" style amount in a text block, or null. */
function parseUsdAmount(text) {
  const m = /([\d,]+(?:\.\d{1,2})?)\s*USD/i.exec(text || '');
  return m ? m[1].replace(/,/g, '') : null;
}

/** Amount shown on the Paid screen, or null if it cannot be read. */
function parsePaidAmount(text) {
  const m = /\bPaid\b[\s\S]{0,60}?([\d,]+(?:\.\d{1,2})?)\s*USD/i.exec(text || '');
  return m ? m[1].replace(/,/g, '') : null;
}

function isPaidScreen(text) {
  return /\bPaid\b/.test(text || '') && /receipt/i.test(text || '');
}

function isExpiredScreen(text) {
  return /\b(expired|time\s?out|timed out)\b/i.test(text || '');
}

/**
 * Finds the red KHQR card (name + amount + QR) and nothing else.
 * 1. Locate the QR drawing: the largest roughly square canvas/img/svg on the page.
 * 2. Climb upwards while the parent does NOT yet contain the "ABA KHQR" header
 *    or the "Scan with mobile banking app" caption. The last element before
 *    that point is the card. No DOM ids are assumed.
 */
async function findQrCard(page) {
  const handle = await page.evaluateHandle(() => {
    const visible = (el) => {
      const r = el.getBoundingClientRect();
      return r.width >= 100 && r.height >= 100 && r.width <= 500 && r.height <= 500;
    };

    const candidates = Array.from(document.querySelectorAll('canvas, img, svg'))
      .filter(visible)
      .filter((el) => {
        const r = el.getBoundingClientRect();
        const ratio = r.width / r.height;
        return ratio > 0.85 && ratio < 1.18; // square-ish: excludes the wide placeholder picture
      })
      .sort((a, b) => {
        const ra = a.getBoundingClientRect();
        const rb = b.getBoundingClientRect();
        return rb.width * rb.height - ra.width * ra.height;
      });

    const qr = candidates[0];
    if (!qr) return null;

    const tooBig = /ABA\s*KHQR|scan with mobile banking app/i;

    let card = qr;
    while (card.parentElement && card.parentElement !== document.body) {
      const parent = card.parentElement;
      if (tooBig.test(parent.innerText || '')) break;
      card = parent;
    }

    return card;
  });

  return handle.asElement();
}

(async () => {
  let browser;
  let page;

  try {
    let host = '';
    try {
      host = new URL(cfg.url).hostname;
    } catch (e) {
      /* handled below */
    }
    if (host !== 'link.payway.com.kh') return fail('Refusing to open a URL outside link.payway.com.kh');
    if (!/^\d{1,5}(\.\d{1,2})?$/.test(cfg.amount)) return fail('Invalid amount');
    if (!cfg.qrOut) return fail('QR_OUT is required');

    browser = await chromium.launch({ headless: cfg.headless });
    const context = await browser.newContext({
      viewport: { width: 1100, height: 800 },
      deviceScaleFactor: 2, // sharper QR when the website enlarges it
      locale: 'en-US',
      extraHTTPHeaders: { 'Accept-Language': 'en-US,en;q=0.9' },
    });
    page = await context.newPage();
    page.setDefaultTimeout(30000);

    emit({ event: 'started' });

    // 1. Open the link and type the amount.
    await page.goto(cfg.url, { waitUntil: 'domcontentloaded' });
    const input = page.locator('#txt_amount');
    await input.waitFor({ state: 'visible' });
    await input.click();
    await input.fill(cfg.amount);

    // 2. Click Continue (invisible to the customer).
    await page.getByText(/^\s*Continue\s*$/i).first().click();

    // 3. Wait for the KHQR screen.
    await page.getByText(/scan with mobile banking app/i).first().waitFor({ state: 'visible' });
    await page.waitForTimeout(1000); // let the QR finish drawing

    const card = await findQrCard(page);
    const cardText = card ? await card.innerText() : '';

    // Safety: the QR must be for exactly the amount we asked for.
    // Prefer the card text; fall back to the whole page if the card has no amount.
    const shown = parseUsdAmount(cardText) ?? parseUsdAmount(await page.innerText('body'));
    if (shown === null || Number(shown) !== Number(cfg.amount)) {
      return fail('QR amount (' + shown + ') does not match the requested amount');
    }

    if (card) {
      await card.screenshot({ path: cfg.qrOut });
    } else {
      // Fallback: the payment panel area.
      await page.screenshot({ path: cfg.qrOut, clip: { x: 550, y: 150, width: 520, height: 520 } });
    }
    emit({ event: 'qr_ready', path: cfg.qrOut });

    // 4. Watch the same page for "Paid".
    const deadline = Date.now() + cfg.sessionMs;
    while (Date.now() < deadline) {
      const body = await page.innerText('body').catch(() => '');

      if (isPaidScreen(body)) {
        if (cfg.evidenceOut) {
          await page.screenshot({ path: cfg.evidenceOut }).catch(() => {});
        }
        emit({ event: 'paid', amount: parsePaidAmount(body) });
        return;
      }

      if (isExpiredScreen(body)) {
        emit({ event: 'expired' });
        return;
      }

      await page.waitForTimeout(1000);
    }

    emit({ event: 'expired' });
  } catch (e) {
    fail(e && e.message ? e.message : e);
    if (page && cfg.evidenceOut) {
      await page.screenshot({ path: cfg.evidenceOut.replace(/\.png$/i, '-error.png') }).catch(() => {});
    }
  } finally {
    if (browser) await browser.close().catch(() => {});
  }
})();