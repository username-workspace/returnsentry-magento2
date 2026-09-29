import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';

const template = readFileSync(new URL('../../view/frontend/templates/beacon.phtml', import.meta.url), 'utf8');
const script = /<<<'JS'\n([\s\S]*?)\nJS;/.exec(template)[1];

const settings = {
  src: 'https://rs.example.com/beacon.js',
  key: 'rs_pk_test',
  page: 'product',
  step: null,
  checkoutSteps: false,
  loadWithoutConsent: false,
  consentCookie: 'axeptio_authorized_vendors',
  consentCookieValue: 'returnsentry',
  consentEvent: 'returnsentry:consent',
};

function page(cookies, stored = {}, overrides = {}) {
  const jar = new Map(Object.entries(cookies));
  const storage = new Map(Object.entries(stored));
  const listeners = {};
  const loaded = [];
  const document = {
    get cookie() {
      return [...jar].map(([name, value]) => `${name}=${value}`).join('; ');
    },
    set cookie(line) {
      const [pair, ...attributes] = line.split('; ');
      const [name, value] = [pair.slice(0, pair.indexOf('=')), pair.slice(pair.indexOf('=') + 1)];
      if (attributes.includes('Max-Age=0')) {
        jar.delete(name);
      } else {
        jar.set(name, value);
      }
    },
    head: { appendChild: (tag) => loaded.push(tag.src) },
    createElement: () => ({ setAttribute() {} }),
    addEventListener() {},
  };
  const window = {
    addEventListener: (type, listener) => (listeners[type] ??= []).push(listener),
    localStorage: { removeItem: (name) => storage.delete(name) },
  };
  vm.runInNewContext(`(${script})(${JSON.stringify({ ...settings, ...overrides })});`, {
    window,
    document,
    location: { protocol: 'https:', hash: '' },
  });
  return {
    jar,
    storage,
    loaded,
    dispatch: (detail) => (listeners[settings.consentEvent] ?? []).forEach((listener) => listener({ detail })),
  };
}

test('a consent cookie that grants remembers the consent and loads the beacon', () => {
  const shop = page({ axeptio_authorized_vendors: ',google,returnsentry,' });

  assert.equal(shop.jar.get('rs_consent'), '1');
  assert.deepEqual(shop.loaded, [settings.src]);
});

test('a consent cookie that no longer grants withdraws the remembered consent', () => {
  const shop = page({ rs_consent: '1', axeptio_authorized_vendors: ',google,' });

  assert.equal(shop.jar.has('rs_consent'), false);
});

test('a withdrawal erases the visitor id from the device, without loading the beacon', () => {
  const shop = page({ rs_consent: '1', rs_vid: 'k3Jd9QxW2bL0pZ7aR4tY1g', axeptio_authorized_vendors: ',google,' }, {
    rs_vid: 'k3Jd9QxW2bL0pZ7aR4tY1g',
  });

  assert.equal(shop.jar.has('rs_vid'), false);
  assert.equal(shop.storage.has('rs_vid'), false);
  assert.deepEqual(shop.loaded, []);
});

test('a withdrawal on a sign-in page keeps the visitor id the exemption allows', () => {
  const shop = page(
    { rs_consent: '1', rs_vid: 'k3Jd9QxW2bL0pZ7aR4tY1g', axeptio_authorized_vendors: ',google,' },
    { rs_vid: 'k3Jd9QxW2bL0pZ7aR4tY1g' },
    { loadWithoutConsent: true }
  );

  assert.equal(shop.jar.get('rs_vid'), 'k3Jd9QxW2bL0pZ7aR4tY1g');
  assert.equal(shop.storage.get('rs_vid'), 'k3Jd9QxW2bL0pZ7aR4tY1g');
});

test('without the consent cookie, a consent given through the event stays', () => {
  const shop = page({ rs_consent: '1' });

  assert.equal(shop.jar.get('rs_consent'), '1');
});

test('a malformed consent cookie leaves the consent event working', () => {
  const shop = page({ axeptio_authorized_vendors: '%E0%A4%A' });
  shop.dispatch(true);

  assert.equal(shop.jar.get('rs_consent'), '1');
});
