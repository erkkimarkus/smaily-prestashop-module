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
 * The Initial setup's steps (views/templates/admin/setup/ and the panels of panel/), on the shell
 * of common.js: the store-layout question, the Connection panel with Test connection, the Contacts
 * panel with the first contact import, Finish, and the Overview's connection state. Plain
 * JavaScript, no build.
 *
 * Continue saves a step through Admin\Api\Setup\SaveStep (window.smailyConnect.setup.onContinue);
 * a refused save marks each field at fault with its message and keeps the step. Back keeps what
 * was typed: the steps stay on the page. The Automations and Intelligence steps belong to later
 * work; Continue passes through them, and Finish on step 4 runs window.smailyConnect.setup.finish(),
 * which a later saver of step 4 calls after its own save.
 */
(function () {
  'use strict';

  var smailyConnect = window.smailyConnect = window.smailyConnect || {};
  var POLL_MS = 5000;

  function ready(callback) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', callback);
    } else {
      callback();
    }
  }

  ready(function () {
    var root = document.querySelector('[data-smaily-setup-root]');
    var container = root && root.querySelector('[data-smaily-setup]');
    if (!container || !smailyConnect.setup) {
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

    function panel(name) {
      return container.querySelector('[data-smaily-panel="' + name + '"]');
    }

    function stepPanel(step) {
      return container.querySelector('.smaily-step-panel[data-step="' + step + '"]');
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

    function checked(scope, name) {
      var input = scope.querySelector('input[name="' + name + '"]:checked');
      return input ? input.value : '';
    }

    function value(scope, field) {
      var input = scope.querySelector('[data-smaily-field="' + field + '"]');
      return input ? input.value : '';
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

    /* Choice cards: the selected card carries is-selected. */
    container.addEventListener('change', function (event) {
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
      if (input.matches('.smaily-choice__input')) {
        container.querySelectorAll('input[name="' + input.name + '"]').forEach(function (other) {
          other.closest('.smaily-choice').classList.toggle('is-selected', other.checked);
        });
      }
      if (input.name === 'smaily-language-mode') {
        var connection = panel('connection');
        var perLanguage = input.value === 'a';
        connection.querySelector('[data-smaily-account-block=""]').hidden = perLanguage;
        var region = connection.querySelector('[data-smaily-per-language]');
        if (region) {
          region.hidden = !perLanguage;
        }
      }
    });

    /* The store-layout question comes first while no layout is stored (Magento CON-279). */
    var shape = panel('store-shape');
    if (shape && shape.getAttribute('data-first') === '1') {
      container.querySelector('.smaily-steps').hidden = true;
      container.querySelector('.smaily-footer').hidden = true;
      container.querySelector('[data-smaily-shape-page]').hidden = true;
      var invitation = container.querySelector('[data-smaily-invitation]');
      if (invitation) {
        invitation.hidden = true;
      }
    }

    function saveShape(button) {
      var shapeResult = shape.querySelector('[data-smaily-shape-result]');
      button.disabled = true;
      result(shapeResult, label('saving'), true);
      post('save', {step: 'shape', data: {shape: checked(shape, 'smaily-shape')}}).then(function () {
        window.location.reload();
      }).catch(function (error) {
        result(shapeResult, error.message, false);
        button.disabled = false;
      });
    }

    /* Connection: one block is the account of no language (''), or of a language in mode a. */
    function block(element) {
      return element.closest('[data-smaily-account-block]');
    }

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
      var status = container.querySelector('[data-smaily-connection-status]');
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
      var accountBlock = block(button);
      var testResult = accountBlock.querySelector('[data-smaily-test-result]');
      var data = credentials(accountBlock);
      var prefix = data.language === '' ? '' : 'accounts.' + data.language + '.';
      clearErrors(accountBlock);
      button.disabled = true;
      result(testResult, label('testing'), true);
      post('test', data).then(function (answer) {
        if (answer.connected) {
          result(testResult, label(data.language === '' ? 'connected-next' : 'connected'), true);
        } else {
          result(testResult, answer.error || label('failed'), false);
        }
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

    function failed(scope, error) {
      throw new Error(showErrors(scope, error.fields) ? label('saving-failed') : error.message);
    }

    function saveConnect() {
      var step = stepPanel(1);
      clearErrors(step);
      var connection = panel('connection');
      var data = {};
      if (connection) {
        var mode = checked(connection, 'smaily-language-mode') || 'single';
        var main = credentials(connection.querySelector('[data-smaily-account-block=""]'));
        data = {subdomain: main.subdomain, username: main.username, password: main.password, language_mode: mode};
        if (mode === 'a') {
          data.accounts = {};
          connection.querySelectorAll('[data-smaily-per-language] [data-smaily-account-block]').forEach(function (accountBlock) {
            var account = credentials(accountBlock);
            data.accounts[account.language] = {subdomain: account.subdomain, username: account.username, password: account.password};
          });
          data.fallback_language = checked(connection, 'smaily-fallback-language');
        }
      }
      return post('save', {step: 'connect', data: data}).then(function (answer) {
        showStatus(!!answer.connected);
        container.querySelectorAll('[data-smaily-field$="password"]').forEach(function (input) {
          input.value = '';
        });
      }).catch(function (error) {
        failed(step, error);
      });
    }

    function saveContacts() {
      var step = stepPanel(2);
      clearErrors(step);
      var contacts = panel('contacts');
      var data = {};
      if (contacts) {
        var enabled = contacts.querySelector('[data-smaily-field="enabled"]');
        data = {
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
      }
      return post('save', {step: 'contacts', data: data}).then(function () {
        importCard.saved(data.enabled !== false, data.mode);
      }).catch(function (error) {
        failed(step, error);
      });
    }

    /*
     * Finish: the setup is done; the page opens again on the Overview, which the server draws from
     * what is saved now.
     */
    smailyConnect.setup.finish = function () {
      return post('save', {step: 'finish'}).then(function () {
        var url = new URL(root.getAttribute('data-setup-url'), window.location.href);
        new URL(window.location.href).searchParams.forEach(function (paramValue, key) {
          url.searchParams.set(key, paramValue);
        });
        url.searchParams.set('step', '5');
        window.location.href = url.toString();
        return new Promise(function () {});
      });
    };

    smailyConnect.setup.onContinue(1, saveConnect);
    smailyConnect.setup.onContinue(2, saveContacts);
    smailyConnect.setup.onContinue(4, smailyConnect.setup.finish);

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
    }(container.querySelector('[data-smaily-import]')));

    /* The Overview asks Smaily about each stored account when it shows. */
    function checkAccounts() {
      var states = container.querySelectorAll('[data-smaily-account-state]');
      var pending = states.length;
      var allConnected = pending > 0;
      states.forEach(function (pill) {
        post('test', {saved: true, language: pill.getAttribute('data-smaily-account-state')}).then(function (answer) {
          var connected = answer.connected === true;
          allConnected = allConnected && connected;
          pill.classList.add(connected ? 'smaily-pill--active' : 'smaily-pill--off');
          pill.querySelector('[data-smaily-pill-text]').textContent = label(connected ? 'connected' : 'not-connected');
          if (!connected && answer.error) {
            pill.title = answer.error;
          }
        }).catch(function () {
          allConnected = false;
          pill.classList.add('smaily-pill--off');
          pill.querySelector('[data-smaily-pill-text]').textContent = label('not-connected');
        }).then(function () {
          pending -= 1;
          if (pending === 0) {
            container.querySelector('[data-smaily-overview-connected]').hidden = !allConnected;
            container.querySelector('[data-smaily-overview-not-connected]').hidden = allConnected;
          }
        });
      });
    }

    container.addEventListener('click', function (event) {
      var target = event.target.closest('[data-smaily-action]');
      if (!target || !container.contains(target)) {
        return;
      }
      var action = target.getAttribute('data-smaily-action');
      if (action === 'save-shape') {
        saveShape(target);
      } else if (action === 'test-smaily') {
        testSmaily(target);
      } else if (action === 'setup-goto') {
        event.preventDefault();
        var item = container.querySelector('.smaily-steps li[data-step="' + target.getAttribute('data-step') + '"]');
        if (item) {
          item.click();
        }
      } else if (action === 'invitation-connect') {
        var subdomain = container.querySelector('[data-smaily-field="subdomain"]');
        if (subdomain) {
          subdomain.scrollIntoView({block: 'center'});
          subdomain.focus();
        }
      }
    });

    if (!stepPanel(5).hidden) {
      checkAccounts();
    }
  });
}());
