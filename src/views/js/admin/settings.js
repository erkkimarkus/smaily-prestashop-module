/*
 * 2024 Smaily
 *
 * NOTICE OF LICENSE
 *
 * Smaily for PrestaShop is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * Smaily for PrestaShop is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Smaily for PrestaShop. If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Smaily <info@smaily.com>
 * @copyright 2024 Smaily
 * @license   GPL3
 */

/*
 * The Settings page's tabs Connection, Contacts and RSS (views/templates/admin/settings/ and the
 * panels of panel/), on the shell of common.js, which switches the tabs. Plain JavaScript, no
 * build. As Magento's Settings:
 *
 * - every tab saves on its own (Admin\Api\Settings\SaveTab), and a refused save marks each field
 *   at fault with its message;
 * - a tab's switch "Use its own … on this shop", unticked on a shop that holds own values, mutes the
 *   tab until the save removes them; the page then reloads to show the shared values;
 * - the connection's state is asked of Smaily once the page has loaded;
 * - Change of the store layout opens the choice above the tabs; to one store it asks first;
 * - the RSS builder builds the feed URL from the picked values, with Copy.
 *
 * Settings also asks before a change of who is synced to Smaily on a store that already syncs,
 * so that the audience never widens unnoticed.
 */
(function () {
  'use strict';

  var smailyConnect = window.smailyConnect = window.smailyConnect || {};
  var POLL_MS = 5000;
  /* The feed's own defaults: a URL leaves them out (controllers/front/SmailyRssFeed.php). */
  var FEED_DEFAULTS = {limit: '50', sort_by: 'date_upd', sort_order: 'desc'};

  function ready(callback) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', callback);
    } else {
      callback();
    }
  }

  ready(function () {
    var root = document.querySelector('[data-smaily-settings-root]');
    if (!root || !smailyConnect.api) {
      return;
    }

    function label(name, params) {
      var text = root.getAttribute('data-label-' + name) || '';
      Object.keys(params || {}).forEach(function (key) {
        text = text.split('%' + key + '%').join(String(params[key]));
      });
      return text;
    }

    function post(api, body) {
      return smailyConnect.api(root.getAttribute('data-api-' + api), body);
    }

    function result(element, text, ok) {
      if (element) {
        element.textContent = text;
        element.classList.toggle('is-error', !ok);
      }
    }

    function tabPanel(tab) {
      return root.querySelector('.smaily-tab-panel[data-tab="' + tab + '"]');
    }

    function panel(name) {
      return root.querySelector('[data-smaily-panel="' + name + '"]');
    }

    function checked(scope, name) {
      var input = scope.querySelector('input[name="' + name + '"]:checked');
      return input ? input.value : '';
    }

    function value(scope, field) {
      var input = scope.querySelector('[data-smaily-field="' + field + '"]');
      return input ? input.value : '';
    }

    /* Field errors: each message under its field, as Magento marks them (PRO-3562). */
    function clearErrors(scope) {
      scope.querySelectorAll('.smaily-field-error').forEach(function (node) {
        node.parentNode.removeChild(node);
      });
      scope.querySelectorAll('.is-invalid').forEach(function (node) {
        node.classList.remove('is-invalid');
        node.removeAttribute('aria-invalid');
      });
    }

    /* Marks each field of `fields`; answers whether every message found its field. */
    function showErrors(scope, fields) {
      var first = null;
      var placed = 0;
      (fields || []).forEach(function (error) {
        var field = error.field ? scope.querySelector('[data-smaily-field="' + error.field + '"]') : null;
        if (!field) {
          return;
        }
        placed += 1;
        var message = document.createElement('p');
        message.className = 'smaily-field-error';
        message.textContent = error.message;
        var anchor = field.closest('.smaily-subdomain-row') || field;
        anchor.parentNode.insertBefore(message, anchor.nextSibling);
        field.classList.add('is-invalid');
        field.setAttribute('aria-invalid', 'true');
        first = first || field;
      });
      if (first && first.focus) {
        first.focus();
      }
      return placed > 0 && placed === (fields || []).length;
    }

    /* A pasted Smaily URL reads as its subdomain, as the server reduces it (ConnectionPanel). */
    function subdomainOf(text) {
      var host = text.trim();
      var match = host.match(/^[a-z][a-z0-9+.-]*:\/\/([^/?#:]+)/i);
      if (match) {
        host = match[1];
      }
      return host.replace(/\.sendsmaily\.net.*$/i, '').replace(/^[\s/.]+|[\s/.]+$/g, '').toLowerCase();
    }

    function reload() {
      window.location.reload();
    }

    /* A tab's switch "Use its own … on this shop" (Magento CON-179). */
    function ownSwitch(tab) {
      return root.querySelector('[data-smaily-own="' + tab + '"]');
    }

    function applyOwn(input) {
      var tab = input.getAttribute('data-smaily-own');
      var returning = !input.checked && input.getAttribute('data-own-saved') === '1';
      var region = root.querySelector('[data-smaily-own-region="' + tab + '"]');
      region.classList.toggle('smaily-muted', returning);
      region.inert = returning;
      input.closest('.smaily-own-toggle').querySelector('[data-smaily-own-return]').hidden = !returning;
      if (tab === 'connection') {
        // An account per language is a shop's own setting (ARCHITECTURE 5.1).
        var perLanguage = region.querySelector('.smaily-choice__input[value="a"]');
        if (perLanguage) {
          perLanguage.closest('.smaily-choice').hidden = !input.checked;
        }
      }
    }

    root.querySelectorAll('[data-smaily-own]').forEach(function (input) {
      applyOwn(input);
      input.addEventListener('change', function () {
        applyOwn(input);
      });
    });

    /* Connection: the language mode, the account blocks, Test connection and the state. */
    var connection = panel('connection');
    var savedLanguageMode = connection ? (checked(connection, 'smaily-language-mode') || 'single') : 'single';
    var languageModeConfirmed = false;

    function showLanguageMode(mode) {
      var perLanguage = mode === 'a';
      connection.querySelector('[data-smaily-account-block=""]').hidden = perLanguage;
      var region = connection.querySelector('[data-smaily-per-language]');
      if (region) {
        region.hidden = !perLanguage;
      }
    }

    root.addEventListener('change', function (event) {
      var input = event.target;
      if (/(^|\.)subdomain$/.test(input.getAttribute('data-smaily-field') || '')) {
        input.value = subdomainOf(input.value);
      }
      var changed = input.closest('[data-smaily-field]');
      if (changed && changed.classList.contains('is-invalid')) {
        changed.classList.remove('is-invalid');
        changed.removeAttribute('aria-invalid');
        var anchor = changed.closest('.smaily-subdomain-row') || changed;
        if (anchor.nextSibling && anchor.nextSibling.classList && anchor.nextSibling.classList.contains('smaily-field-error')) {
          anchor.parentNode.removeChild(anchor.nextSibling);
        }
      }
      if (input.name === 'smaily-language-mode') {
        // Magento asks once before it leaves a configured per-language mode.
        var leaving = (savedLanguageMode === 'a' || savedLanguageMode === 'b') && input.value !== savedLanguageMode;
        if (leaving && !languageModeConfirmed) {
          if (!window.confirm(label('language-mode-switch'))) {
            connection.querySelector('input[name="smaily-language-mode"][value="' + savedLanguageMode + '"]').checked = true;
            input = connection.querySelector('input[name="smaily-language-mode"]:checked');
          } else {
            languageModeConfirmed = true;
          }
        }
        showLanguageMode(input.value);
      }
      if (input.matches('.smaily-choice__input')) {
        root.querySelectorAll('input[name="' + input.name + '"]').forEach(function (other) {
          other.closest('.smaily-choice').classList.toggle('is-selected', other.checked);
        });
      }
      if (input.name === 'smaily-shape-change') {
        showShapeNote();
      }
    });

    function credentials(accountBlock) {
      var language = accountBlock.getAttribute('data-smaily-account-block');
      var prefix = language === '' ? '' : 'accounts.' + language + '.';
      return {
        subdomain: value(accountBlock, prefix + 'subdomain'),
        username: value(accountBlock, prefix + 'username'),
        password: value(accountBlock, prefix + 'password'),
        language: language
      };
    }

    function showStatus(connected) {
      var status = connection && connection.querySelector('[data-smaily-connection-status]');
      if (!status) {
        return;
      }
      status.hidden = false;
      var pill = status.querySelector('[data-smaily-pill]');
      pill.classList.toggle('smaily-pill--active', connected);
      pill.classList.toggle('smaily-pill--off', !connected);
      pill.querySelector('[data-smaily-pill-text]').textContent = label(connected ? 'connected' : 'not-connected');
    }

    function testSmaily(button) {
      var accountBlock = button.closest('[data-smaily-account-block]');
      var testResult = accountBlock.querySelector('[data-smaily-test-result]');
      var data = credentials(accountBlock);
      var prefix = data.language === '' ? '' : 'accounts.' + data.language + '.';
      clearErrors(accountBlock);
      button.disabled = true;
      result(testResult, label('testing'), true);
      post('test', data).then(function (answer) {
        result(testResult, answer.connected ? label('connected') : (answer.error || label('failed')), !!answer.connected);
        if (data.language === '') {
          showStatus(!!answer.connected);
        }
      }).catch(function (error) {
        showErrors(accountBlock, (error.fields || []).map(function (field) {
          return {field: prefix + field.field, message: field.message};
        }));
        result(testResult, error.message || label('failed'), false);
      }).then(function () {
        button.disabled = false;
      });
    }

    /*
     * The state of each stored account, asked of Smaily after the page has loaded (Magento's
     * Settings shows it under the account): the account of no language, or in mode `a` each
     * language's account.
     */
    function checkStoredAccounts() {
      if (!connection) {
        return;
      }
      var mode = checked(connection, 'smaily-language-mode') || 'single';
      var blocks = mode === 'a'
        ? connection.querySelectorAll('[data-smaily-per-language] [data-smaily-account-block]')
        : connection.querySelectorAll('[data-smaily-account-block=""]');
      blocks.forEach(function (accountBlock) {
        var data = credentials(accountBlock);
        if (data.subdomain === '') {
          return;
        }
        post('test', {saved: true, language: data.language}).then(function (answer) {
          if (answer.connected === null) {
            return;
          }
          if (data.language === '') {
            showStatus(answer.connected === true);
          } else {
            result(accountBlock.querySelector('[data-smaily-test-result]'), label(answer.connected ? 'connected' : 'not-connected'), answer.connected === true);
          }
        }).catch(function () {
          // No right to ask, or Smaily out of reach: the state stays unsaid.
        });
      });
    }

    function connectionData() {
      var mode = checked(connection, 'smaily-language-mode') || 'single';
      var main = credentials(connection.querySelector('[data-smaily-account-block=""]'));
      var data = {subdomain: main.subdomain, username: main.username, password: main.password, language_mode: mode};
      if (mode === 'a') {
        data.accounts = {};
        connection.querySelectorAll('[data-smaily-per-language] [data-smaily-account-block]').forEach(function (accountBlock) {
          var account = credentials(accountBlock);
          data.accounts[account.language] = {subdomain: account.subdomain, username: account.username, password: account.password};
        });
        data.fallback_language = checked(connection, 'smaily-fallback-language');
      }
      return data;
    }

    /* Contacts: what the tab would save, and what a save changes about who is synced. */
    var contacts = panel('contacts');

    function contactsData() {
      var enabled = contacts.querySelector('[data-smaily-field="enabled"]');
      var data = {
        mode: checked(contacts, 'smaily-contacts-mode'),
        fields: Array.prototype.map.call(contacts.querySelectorAll('[data-smaily-field="fields"] input:checked'), function (input) {
          return input.value;
        }),
        guests: contacts.querySelector('[data-smaily-field="guests"]').checked,
        checkout_checkbox: contacts.querySelector('[data-smaily-field="checkout_checkbox"]').checked
      };
      if (enabled) {
        data.enabled = enabled.checked;
      }
      return data;
    }

    /*
     * A store that already syncs, saved with another mode or with guests newly included: the
     * merchant reads what changes and confirms it. Returns false when the merchant declined.
     */
    function confirmAudience(data, own) {
      var region = root.querySelector('[data-smaily-own-region="contacts"]');
      if (contacts.getAttribute('data-active') !== '1' || data.enabled === false || (own && !own.checked && own.getAttribute('data-own-saved') === '1')) {
        return true;
      }
      var lines = [];
      if (data.mode !== region.getAttribute('data-saved-mode')) {
        lines.push(label('confirm-' + data.mode.replace('_', '-')));
      }
      if (data.guests && region.getAttribute('data-saved-guests') !== '1') {
        lines.push(label('confirm-guests'));
      }
      if (lines.length === 0) {
        return true;
      }
      lines.push(label('confirm-continue'));
      return window.confirm(lines.join('\n\n'));
    }

    function rssData() {
      var builder = panel('rss');
      return {
        category: value(builder, 'category').trim(),
        limit: value(builder, 'limit').trim(),
        sort_by: value(builder, 'sort_by'),
        sort_order: value(builder, 'sort_order')
      };
    }

    /* Save Connection, Save Contacts, Save: each tab on its own (PRO-3641). */
    function saveTab(button) {
      var tab = button.getAttribute('data-tab');
      var scope = tabPanel(tab);
      var tabResult = scope.querySelector('[data-smaily-tab-result]');
      var own = ownSwitch(tab);
      var data = tab === 'connection' ? connectionData() : (tab === 'contacts' ? contactsData() : rssData());
      if (tab === 'contacts' && !confirmAudience(data, own)) {
        return;
      }
      clearErrors(scope);
      button.disabled = true;
      result(tabResult, label('saving'), true);
      post('save', {tab: tab, data: data, own: own ? own.checked : false}).then(function (answer) {
        // The shop's own values or its own automations are gone: the page shows what it reads now.
        if (answer.returned || answer.own_account_started) {
          reload();
          return;
        }
        if (own) {
          own.setAttribute('data-own-saved', own.checked ? '1' : '0');
          applyOwn(own);
        }
        if (tab === 'connection') {
          savedLanguageMode = data.language_mode;
          languageModeConfirmed = false;
          showStatus(!!answer.connected);
          scope.querySelectorAll('[data-smaily-field$="password"]').forEach(function (input) {
            input.value = '';
          });
          // The save stands either way, as in Magento; why Smaily refused shows under the account.
          var main = connection.querySelector('[data-smaily-account-block=""] [data-smaily-test-result]');
          result(main, answer.connected ? '' : (answer.error || label('not-connected')), !!answer.connected);
          result(tabResult, label('saved'), true);
        } else {
          if (tab === 'contacts') {
            var region = root.querySelector('[data-smaily-own-region="contacts"]');
            region.setAttribute('data-saved-mode', data.mode);
            region.setAttribute('data-saved-guests', data.guests ? '1' : '0');
            contacts.setAttribute('data-active', data.enabled === false ? '0' : '1');
            importCard.saved(data.enabled !== false, data.mode);
          }
          result(tabResult, label('saved'), true);
        }
      }).catch(function (error) {
        result(tabResult, showErrors(scope, error.fields) ? label('saving-failed') : error.message, false);
      }).then(function () {
        button.disabled = false;
      });
    }

    /* The first contact import: its state, Start, Cancel, and the progress while it runs. */
    var importCard = (function (card) {
      if (!card) {
        return {saved: function () {}};
      }
      var job = JSON.parse(card.getAttribute('data-smaily-import') || 'null');
      var timer = null;
      var pill = card.querySelector('[data-smaily-import-pill]');
      var line = card.querySelector('[data-smaily-import-line]');
      var bar = card.querySelector('[data-smaily-import-bar]');
      var start = card.querySelector('[data-smaily-action="import-start"]');
      var stop = card.querySelector('[data-smaily-action="import-stop"]');
      var importResult = card.querySelector('[data-smaily-import-result]');
      var states = {
        queued: ['pending', 'state-pending'],
        running: ['pending', 'state-running'],
        done: ['active', 'state-done'],
        failed: ['failed', 'state-stopped'],
        cancelled: ['off', 'state-cancelled']
      };

      function draw() {
        var active = job && (job.status === 'queued' || job.status === 'running');
        var counts = job ? {sent: job.sent, total: job.total, failed: job.failed} : {};
        pill.hidden = !job;
        if (job) {
          var state = states[job.status] || states.done;
          pill.className = 'smaily-pill smaily-pill--' + state[0];
          pill.querySelector('[data-smaily-import-pill-text]').textContent = label(state[1]);
        }
        bar.hidden = !job;
        if (job) {
          bar.querySelector('.smaily-progress__fill').style.width = (job.total > 0 ? Math.min(100, Math.round(100 * (job.sent + job.failed + job.skipped) / job.total)) : 100) + '%';
        }
        var text = '';
        if (job && job.status === 'queued') {
          text = label('queued');
        } else if (job && job.status === 'running') {
          text = label('importing') + ' ' + label('progress', counts);
        } else if (job && job.status === 'done') {
          text = job.last_error === 'contact_sync_off' ? label('sync-off') : label(job.failed > 0 ? 'done-failed' : 'done', counts);
        } else if (job && job.status === 'cancelled') {
          text = label('cancelled', counts);
        } else if (job && job.status === 'failed') {
          text = label('stopped', counts);
        }
        line.textContent = text;
        start.hidden = active;
        start.textContent = label(job && !active ? 'run-again' : 'start');
        stop.hidden = !active;
        window.clearTimeout(timer);
        if (active) {
          timer = window.setTimeout(poll, POLL_MS);
        }
      }

      function poll() {
        post('import-progress').then(function (answer) {
          job = answer.import;
          draw();
        }).catch(function () {
          timer = window.setTimeout(poll, POLL_MS);
        });
      }

      function act(action, button) {
        button.disabled = true;
        result(importResult, action === 'stop' ? label('canceling') : '', true);
        post('import', {action: action}).then(function (answer) {
          job = answer.import;
          result(importResult, '', true);
          draw();
        }).catch(function (error) {
          result(importResult, error.message, false);
        }).then(function () {
          button.disabled = false;
        });
      }

      start.addEventListener('click', function () {
        act('start', start);
      });
      stop.addEventListener('click', function () {
        act('stop', stop);
      });
      draw();

      return {
        saved: function (enabled, mode) {
          card.querySelector('[data-smaily-import-unsaved]').hidden = enabled;
          card.querySelector('[data-smaily-import-nobody]').hidden = !enabled || mode !== 'checkout_optin';
          card.querySelector('[data-smaily-import-body]').hidden = !enabled || mode === 'checkout_optin';
        }
      };
    }(root.querySelector('[data-smaily-import]')));

    /* RSS: the feed URL from the picked values; a value equal to the feed's default is left out. */
    var builder = root.querySelector('[data-smaily-rss-builder]');

    function buildFeedUrl() {
      var data = rssData();
      var params = [];
      if (/^[1-9][0-9]*$/.test(data.category)) {
        params.push('category_id=' + encodeURIComponent(data.category));
      }
      var limit = parseInt(data.limit, 10);
      if (limit && String(Math.min(250, Math.max(1, limit))) !== FEED_DEFAULTS.limit) {
        params.push('limit=' + Math.min(250, Math.max(1, limit)));
      }
      if (data.sort_by !== FEED_DEFAULTS.sort_by) {
        params.push('sort_by=' + encodeURIComponent(data.sort_by));
      }
      if (data.sort_order !== FEED_DEFAULTS.sort_order) {
        params.push('sort_order=' + encodeURIComponent(data.sort_order));
      }
      var base = builder.getAttribute('data-base-url');
      builder.querySelector('[data-smaily-rss-url]').value = base + (params.length ? (base.indexOf('?') === -1 ? '?' : '&') + params.join('&') : '');
      result(builder.querySelector('[data-smaily-rss-copy-result]'), '', true);
    }

    function copyFeedUrl() {
      var input = builder.querySelector('[data-smaily-rss-url]');
      var copyResult = builder.querySelector('[data-smaily-rss-copy-result]');
      function copied(ok) {
        result(copyResult, label(ok ? 'copied' : 'copy-failed'), ok);
      }
      // execCommand for an admin served where the clipboard API is not offered.
      function fallback() {
        input.select();
        input.setSelectionRange(0, input.value.length);
        try {
          return document.execCommand('copy');
        } catch (error) {
          return false;
        }
      }
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(input.value).then(function () {
          copied(true);
        }, function () {
          copied(fallback());
        });
      } else {
        copied(fallback());
      }
    }

    if (builder) {
      builder.addEventListener('input', buildFeedUrl);
      builder.addEventListener('change', buildFeedUrl);
      builder.querySelector('[data-smaily-rss-url]').addEventListener('click', function (event) {
        event.target.select();
      });
      buildFeedUrl();
    }

    /* How the shops are run: Change opens the choice; to one store asks first. */
    var shape = panel('store-shape');
    var shapeChange = root.querySelector('[data-smaily-shape-change]');
    var shapePage = root.querySelector('[data-smaily-shape-page]');

    function showShapeNote() {
      var current = shape.getAttribute('data-shape');
      var chosen = checked(shapeChange, 'smaily-shape-change');
      shapeChange.querySelectorAll('[data-smaily-shape-note]').forEach(function (note) {
        note.hidden = chosen === current || note.getAttribute('data-smaily-shape-note') !== chosen;
      });
    }

    function showShapeChoice(open) {
      shapeChange.hidden = !open;
      shapePage.hidden = open;
      result(shapeChange.querySelector('[data-smaily-shape-result]'), '', true);
      if (!open) {
        var current = shapeChange.querySelector('input[value="' + shape.getAttribute('data-shape') + '"]');
        current.checked = true;
        current.dispatchEvent(new Event('change', {bubbles: true}));
      }
    }

    function saveLayout(button, chosen, shapeResult) {
      button.disabled = true;
      result(shapeResult, label('saving'), true);
      post('layout', {shape: chosen}).then(reload).catch(function (error) {
        result(shapeResult, error.message, false);
        button.disabled = false;
      });
    }

    /* The question comes first while a multishop store has not answered it (Magento CON-279). */
    if (shape && shape.getAttribute('data-first') === '1') {
      shapePage.hidden = true;
    }

    root.addEventListener('click', function (event) {
      var target = event.target.closest('[data-smaily-action]');
      if (!target || !root.contains(target)) {
        return;
      }
      var action = target.getAttribute('data-smaily-action');
      if (action === 'save-tab') {
        saveTab(target);
      } else if (action === 'test-smaily') {
        testSmaily(target);
      } else if (action === 'rss-copy') {
        copyFeedUrl();
      } else if (action === 'shape-change') {
        showShapeChoice(true);
        showShapeNote();
      } else if (action === 'shape-cancel') {
        showShapeChoice(false);
      } else if (action === 'shape-save') {
        var chosen = checked(shapeChange, 'smaily-shape-change');
        if (chosen === shape.getAttribute('data-shape')) {
          showShapeChoice(false);
        } else if (chosen === 'separate' || window.confirm(shapeChange.querySelector('[data-smaily-shape-note="one"]').textContent.trim())) {
          saveLayout(target, chosen, shapeChange.querySelector('[data-smaily-shape-result]'));
        }
      } else if (action === 'save-shape') {
        saveLayout(target, checked(shape, 'smaily-shape'), shape.querySelector('[data-smaily-shape-result]'));
      } else if (action === 'invitation-connect') {
        var subdomain = root.querySelector('[data-smaily-field="subdomain"]');
        if (subdomain) {
          subdomain.scrollIntoView({block: 'center'});
          subdomain.focus();
        }
      }
    });

    checkStoredAccounts();
  });
}());
