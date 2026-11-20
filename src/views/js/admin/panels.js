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
 * What the Initial setup (setup.js) and Settings (settings.js) share of the panels in
 * views/templates/admin/panel/: the page's words and endpoints, the field errors, the Connection
 * panel's account blocks with Test connection and the state, what the Connection and Contacts
 * panels would save, and the first contact import. Plain JavaScript, no build; on the shell of
 * common.js.
 *
 * window.smailyConnect.panels(root, connectedLabel) takes the page's root element, which carries
 * the endpoints (data-api-*) and the words (data-label-*), and the label that a passed test of the
 * account of no language shows. onChange(hook) runs the page's own hook on every change, between
 * the field's clean-up and the choice cards.
 */
(function () {
  'use strict';

  var smailyConnect = window.smailyConnect = window.smailyConnect || {};
  var POLL_MS = 5000;

  /* The words of an element's data-label-* attributes, with %name% placeholders filled in. */
  function labels(element) {
    return function (name, params) {
      var text = element.getAttribute('data-label-' + name) || '';
      Object.keys(params || {}).forEach(function (key) {
        text = text.split('%' + key + '%').join(String(params[key]));
      });
      return text;
    };
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

  /* Connection: one block is the account of no language (''), or of a language in mode a. */
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

  smailyConnect.panels = function (root, connectedLabel) {
    var show = smailyConnect.show;
    var label = labels(root);
    var hook = null;

    function post(api, body) {
      return smailyConnect.api(root.getAttribute('data-api-' + api), body);
    }

    function panel(name) {
      return root.querySelector('[data-smaily-panel="' + name + '"]');
    }

    /* Mode a swaps the one account for an account per language. */
    function showLanguageMode(mode) {
      var connection = panel('connection');
      var perLanguage = mode === 'a';
      connection.querySelector('[data-smaily-account-block=""]').hidden = perLanguage;
      var region = connection.querySelector('[data-smaily-per-language]');
      if (region) {
        region.hidden = !perLanguage;
      }
    }

    function showStatus(connected) {
      var status = root.querySelector('[data-smaily-connection-status]');
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
      show(testResult, label('testing'), true);
      post('test', data).then(function (answer) {
        if (answer.connected) {
          show(testResult, label(data.language === '' ? connectedLabel : 'connected'), true);
        } else {
          show(testResult, answer.error || label('failed'), false);
        }
        if (data.language === '') {
          showStatus(!!answer.connected);
        }
      }).catch(function (error) {
        showErrors(accountBlock, (error.fields || []).map(function (field) {
          return {field: prefix + field.field, message: field.message};
        }));
        show(testResult, error.message || label('failed'), false);
      }).then(function () {
        button.disabled = false;
      });
    }

    /* What the Connection panel would save. */
    function connectionData() {
      var connection = panel('connection');
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

    /* What the Contacts panel would save. */
    function contactsData() {
      var contacts = panel('contacts');
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

    /* The first contact import: its state, Start, Cancel, and the progress while it runs. */
    var importCard = (function (card) {
      if (!card) {
        return {saved: function () {}};
      }
      var word = labels(card);
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
          pill.querySelector('[data-smaily-import-pill-text]').textContent = word(state[1]);
        }
        bar.hidden = !job;
        if (job) {
          bar.querySelector('.smaily-progress__fill').style.width = (job.total > 0 ? Math.min(100, Math.round(100 * (job.sent + job.failed + job.skipped) / job.total)) : 100) + '%';
        }
        var text = '';
        if (job && job.status === 'queued') {
          text = word('queued');
        } else if (job && job.status === 'running') {
          text = word('importing') + ' ' + word('progress', counts);
        } else if (job && job.status === 'done') {
          text = job.last_error === 'contact_sync_off' ? word('sync-off') : word(job.failed > 0 ? 'done-failed' : 'done', counts);
        } else if (job && job.status === 'cancelled') {
          text = word('cancelled', counts);
        } else if (job && job.status === 'failed') {
          text = word('stopped', counts);
        }
        line.textContent = text;
        start.hidden = active;
        start.textContent = word(job && !active ? 'run-again' : 'start');
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
        show(importResult, action === 'stop' ? word('canceling') : '', true);
        post('import', {action: action}).then(function (answer) {
          job = answer.import;
          show(importResult, '', true);
          draw();
        }).catch(function (error) {
          show(importResult, error.message, false);
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

    /* A changed field loses its error; the selected choice card carries is-selected. */
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
      if (hook) {
        hook(input);
      }
      if (input.matches('.smaily-choice__input')) {
        root.querySelectorAll('input[name="' + input.name + '"]').forEach(function (other) {
          other.closest('.smaily-choice').classList.toggle('is-selected', other.checked);
        });
      }
    });

    root.addEventListener('click', function (event) {
      var target = event.target.closest('[data-smaily-action]');
      if (!target || !root.contains(target)) {
        return;
      }
      var action = target.getAttribute('data-smaily-action');
      if (action === 'test-smaily') {
        testSmaily(target);
      } else if (action === 'invitation-connect') {
        var subdomain = root.querySelector('[data-smaily-field="subdomain"]');
        if (subdomain) {
          subdomain.scrollIntoView({block: 'center'});
          subdomain.focus();
        }
      }
    });

    return {
      label: label,
      post: post,
      panel: panel,
      checked: checked,
      value: value,
      clearErrors: clearErrors,
      showErrors: showErrors,
      credentials: credentials,
      showLanguageMode: showLanguageMode,
      showStatus: showStatus,
      connectionData: connectionData,
      contactsData: contactsData,
      importCard: importCard,
      onChange: function (pageHook) {
        hook = pageHook;
      }
    };
  };
}());
