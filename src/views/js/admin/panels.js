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
 * panel's account blocks with Test connection and the state, the question before a change of the
 * language mode, what the Connection, Contacts and
 * Automations panels would save, the Automations panel's cards, workflow lists and discount
 * pickers, and the first
 * contact import. Plain JavaScript, no build; on the shell of common.js.
 *
 * window.smailyConnect.panels(root, connectedLabel) takes the page's root element, which carries
 * the endpoints (data-api-*) and the words (data-label-*), and the label that a passed test of the
 * account of no language shows. onChange(hook) runs the page's own hook on every change, between
 * the field's clean-up and the choice cards.
 *
 * An account block whose values passed Test connection carries data-smaily-tested until one of
 * its fields is edited; onTested(hook) runs the page's hook after each test and each such edit.
 * Both pages save the Connection only once every account the save would store passed the test
 * (connectionTested()), and ask before another Smaily account takes a stored one's place while an
 * automation has a workflow picked (confirmAccountChange()); lostWorkflows() shows on the page
 * the automations whose workflow such a save removed (Automation\LostWorkflows).
 * The edit also hides the connection state of the account of no language, and an answer about
 * values edited since shows no state (holds()), in the Initial setup and in Settings alike. A
 * connected state names the account's subdomain (Magento's 'CONNECTED as "<subdomain>"').
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

  /* Whether a block still holds the values `data` that a test or a check asked Smaily about. */
  function holds(accountBlock, data) {
    var now = credentials(accountBlock);
    return now.subdomain === data.subdomain && now.username === data.username && now.password === data.password;
  }

  /* The account blocks of a language mode: one, or in mode a each language's. */
  function accountBlocks(connection, mode) {
    return connection.querySelectorAll(mode === 'a' ? '[data-smaily-per-language] [data-smaily-account-block]' : '[data-smaily-account-block=""]');
  }

  /*
   * Whether the Connection may be saved: an account block passed Test connection and no filled
   * block of the mode waits for one (owner, 2026-11-25 and 2026-11-26).
   */
  function connectionTested(connection) {
    var passed = 0;
    var untested = 0;
    accountBlocks(connection, checked(connection, 'smaily-language-mode') || 'single').forEach(function (accountBlock) {
      var values = credentials(accountBlock);
      if (accountBlock.hasAttribute('data-smaily-tested')) {
        passed += 1;
      } else if (values.subdomain || values.username || values.password) {
        untested += 1;
      }
    });
    return passed > 0 && untested === 0;
  }

  /*
   * The subdomains that a save would put in a stored account's place: the typed subdomain of each
   * block against the stored one (the field's default value). In mode a the default fallback's
   * account also takes the place of the account of no language (ConnectionPanel).
   */
  function changedAccounts(connection) {
    var mode = checked(connection, 'smaily-language-mode') || 'single';
    var main = connection.querySelector('[data-smaily-account-block=""] [data-smaily-field="subdomain"]');
    var changed = [];

    function compare(stored, typed) {
      var before = subdomainOf(stored.defaultValue);
      var now = subdomainOf(typed.value);
      if (before !== '' && now !== '' && before !== now && changed.indexOf(now) === -1) {
        changed.push(now);
      }
    }

    if (mode !== 'a') {
      compare(main, main);
      return changed;
    }
    accountBlocks(connection, mode).forEach(function (accountBlock) {
      var field = accountBlock.querySelector('[data-smaily-field$=".subdomain"]');
      compare(field, field);
    });
    var fallback = checked(connection, 'smaily-fallback-language');
    var fallbackField = fallback ? connection.querySelector('[data-smaily-account-block="' + fallback + '"] [data-smaily-field$=".subdomain"]') : null;
    if (fallbackField) {
      compare(main, fallbackField);
    }
    return changed;
  }

  /*
   * One import card as Magento draws its Backfill card (PRO-3564), shared by the first contact
   * import and the Campaign Intelligence imports (intelligence.js): the state pill, the bar with
   * "X of Y" and the percentage under it, and the line, which for an outcome has its icon (done ✓,
   * failures and stalled !, stopped ×, cancelled —) and the finish time in brackets. `view` holds
   * `pill` (a pill class, or '' for none) and `pillText`, `bar` ('' for none, else running, done,
   * failed or stopped), `count` ("X of Y") and `percent`, `text`, `outcome` (a key of
   * IMPORT_ICONS, or '') and `finished`. The card's parts carry data-smaily-import-*.
   */
  var IMPORT_ICONS = {done: '✓', failures: '!', stalled: '!', stopped: '×', cancelled: '—'};

  smailyConnect.drawImport = function (card, view) {
    var pill = card.querySelector('[data-smaily-import-pill]');
    pill.hidden = !view.pill;
    if (view.pill) {
      pill.className = 'smaily-pill smaily-pill--' + view.pill;
      pill.querySelector('[data-smaily-import-pill-text]').textContent = view.pillText;
    }
    var progress = card.querySelector('[data-smaily-import-progress]');
    progress.hidden = !view.bar;
    if (view.bar) {
      progress.querySelector('.smaily-progress').className = 'smaily-progress is-' + view.bar;
      progress.querySelector('.smaily-progress__fill').style.width = view.percent + '%';
      progress.querySelector('[data-smaily-import-count]').textContent = view.count;
      progress.querySelector('[data-smaily-import-pct]').textContent = view.percent + '%';
    }
    var verdict = card.querySelector('[data-smaily-import-verdict]');
    var icon = verdict.querySelector('[data-smaily-import-icon]');
    verdict.setAttribute('data-state', view.outcome || '');
    icon.hidden = !view.outcome;
    icon.textContent = IMPORT_ICONS[view.outcome] || '';
    verdict.querySelector('[data-smaily-import-line]').textContent = view.text
      + (view.outcome && view.finished ? ' (' + view.finished + ')' : '');
  };

  /* How far an import got, in percent: what it sent, failed or skipped of its total. */
  smailyConnect.importPercent = function (job) {
    return job.total > 0 ? Math.min(100, Math.floor(100 * (job.sent + job.failed + (job.skipped || 0)) / job.total)) : 100;
  };

  smailyConnect.panels = function (root, connectedLabel) {
    var show = smailyConnect.show;
    var label = labels(root);
    var hook = null;
    var testedHook = null;

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

    /*
     * A picked language mode. As Magento, the page asks once before a saved mode with per-language
     * accounts or workflows (a, b) is left, in the Initial setup and in Settings alike; entering
     * one asks nothing. A declined change puts the saved mode back.
     */
    var savedConnection = panel('connection');
    var savedLanguageMode = savedConnection ? savedConnection.getAttribute('data-language-mode') : 'single';
    var languageModeConfirmed = false;

    function pickLanguageMode(input) {
      var leaving = (savedLanguageMode === 'a' || savedLanguageMode === 'b') && input.value !== savedLanguageMode;
      if (leaving && !languageModeConfirmed) {
        if (window.confirm(labels(savedConnection)('mode-switch'))) {
          languageModeConfirmed = true;
        } else {
          input = savedConnection.querySelector('input[name="smaily-language-mode"][value="' + savedLanguageMode + '"]');
          input.checked = true;
        }
      }
      showLanguageMode(input.value);
    }

    /*
     * After a Connection save: answers whether the saved mode changed. The page is then drawn
     * again, as the Automations panel shows the saved mode; else the next change asks again.
     */
    function connectionSaved(mode) {
      languageModeConfirmed = false;
      savedConnection.querySelectorAll('[data-smaily-field$="subdomain"]').forEach(function (input) {
        input.defaultValue = input.value;
      });
      return mode !== savedLanguageMode;
    }

    /*
     * Before a Connection save: another Smaily account (another subdomain) in a stored one's place
     * removes the workflows that it does not list (owner, 2026-11-26), so the page asks first while
     * an automation has a workflow picked. Another API user of the same subdomain asks nothing.
     * Answers false when the merchant declined.
     */
    function confirmAccountChange() {
      var changed = changedAccounts(savedConnection);
      var picked = automations && Array.prototype.some.call(automations.querySelectorAll('[data-smaily-workflows]'), function (select) {
        return /^[0-9]+$/.test(workflowValue(select));
      });
      if (!changed.length || !picked) {
        return true;
      }
      var word = labels(savedConnection);
      return window.confirm(word('account-change', {account: changed.join(', ')}) + '\n\n' + word('continue'));
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
      // Connected as "<subdomain>", as Magento names the account.
      var account = status.querySelector('[data-smaily-connection-account]');
      var subdomain = subdomainOf(value(status.closest('[data-smaily-account-block]'), 'subdomain'));
      account.textContent = connected && subdomain !== '' ? labels(account)('as', {account: subdomain}) : '';
    }

    function markTested(accountBlock, passed) {
      if (passed) {
        accountBlock.setAttribute('data-smaily-tested', '');
      } else {
        accountBlock.removeAttribute('data-smaily-tested');
      }
      if (testedHook) {
        testedHook();
      }
    }

    /*
     * Smaily's answer about the values `data` of a block: the result line, the state, and whether
     * the block counts as tested, which it does only while it still holds those values.
     */
    function tested(accountBlock, data, answer) {
      var testResult = accountBlock.querySelector('[data-smaily-test-result]');
      var held = holds(accountBlock, data);
      if (answer.connected) {
        show(testResult, label(data.language === '' ? connectedLabel : 'connected'), true);
        // The Initial setup's footer still says that an earlier Continue failed: once no field
        // of the panel shows an error, that message is stale.
        var stale = root.querySelector('.smaily-footer [data-smaily-result].is-error');
        if (stale && !panel('connection').querySelector('.smaily-field-error')) {
          show(stale, '', true);
        }
      } else {
        show(testResult, answer.error || label('failed'), false);
      }
      // A state that answers for values edited since stays hidden.
      if (data.language === '' && held) {
        showStatus(!!answer.connected);
      }
      markTested(accountBlock, !!answer.connected && held);
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
        tested(accountBlock, data, answer);
      }).catch(function (error) {
        showErrors(accountBlock, (error.fields || []).map(function (field) {
          return {field: prefix + field.field, message: field.message};
        }));
        show(testResult, error.message || label('failed'), false);
        markTested(accountBlock, false);
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

    /*
     * Automations: a card follows its Enabled switch, and the selects take their options from
     * Smaily, one list per account (`default`, or a language in mode a).
     */
    var automations = panel('automations');
    var workflowLists = {};

    /* A select whose list has not answered yet holds what is saved. */
    function workflowValue(select) {
      return select.options.length ? select.value : (select.getAttribute('data-saved') || '');
    }

    /*
     * A saved workflow that the list does not offer stays as an option of its own, so the save
     * keeps it (Magento PRO-1286); the opt-in trigger of 2.x shows while it is the saved one.
     */
    function fillWorkflows(select) {
      var word = labels(automations);
      var current = workflowValue(select);
      var listed = current === '';
      select.textContent = '';
      select.add(new Option(word('not-selected'), ''));
      if (select.getAttribute('data-saved') === 'optin') {
        select.add(new Option(word('optin'), 'optin'));
        listed = listed || current === 'optin';
      }
      (workflowLists[select.getAttribute('data-smaily-workflows')] || []).forEach(function (workflow) {
        select.add(new Option(workflow.name + ' (' + workflow.id + ')', workflow.id));
        listed = listed || String(workflow.id) === current;
      });
      if (!listed) {
        select.add(new Option(word('kept', {id: current}), current));
      }
      select.value = current;
    }

    /* The discount pickers: the cart rules of the shop, asked with the workflow lists. */
    function loadDiscountRules(own) {
      return post('discount-rules', {own: own}).then(function (answer) {
        return answer.rules;
      }, function () {
        return [];
      }).then(function (rules) {
        var word = labels(automations);
        automations.querySelectorAll('[data-smaily-discount-rules]').forEach(function (select) {
          var current = workflowValue(select);
          select.textContent = '';
          select.add(new Option(word('pick-rule'), ''));
          rules.forEach(function (rule) {
            select.add(new Option(rule.name + ' (' + rule.id + ')', rule.id));
          });
          // A saved rule that the list no longer offers reads as none: the save then asks for one.
          select.value = current;
          if (select.selectedIndex === -1) {
            select.value = '';
          }
        });
        automations.querySelectorAll('[data-smaily-discount-empty]').forEach(function (note) {
          note.hidden = rules.length > 0;
        });
      });
    }

    /* Asks Smaily for each account's list; a list that cannot be loaded says why in `line`. */
    function loadWorkflows(line) {
      if (!automations) {
        return Promise.resolve();
      }
      var selects = automations.querySelectorAll('[data-smaily-workflows]');
      var accounts = [];
      selects.forEach(function (select) {
        var account = select.getAttribute('data-smaily-workflows');
        if (accounts.indexOf(account) === -1) {
          accounts.push(account);
        }
      });
      var own = root.querySelector('[data-smaily-own="automations"]');
      loadDiscountRules(own ? own.checked : false);
      return Promise.all(accounts.map(function (account) {
        return post('workflows', {account: account, own: own ? own.checked : false}).then(function (answer) {
          workflowLists[account] = answer.workflows;
          return answer.error;
        }, function (error) {
          workflowLists[account] = [];
          return error.message;
        });
      })).then(function (errors) {
        selects.forEach(fillWorkflows);
        showUnmappedLanguages();
        var failure = errors.filter(Boolean)[0];
        show(line, failure || '', !failure);
      });
    }

    function showTrigger(card) {
      var trigger = card.getAttribute('data-smaily-trigger');
      var on = card.querySelector('[data-smaily-field="' + trigger + '.enabled"]').checked;
      card.classList.toggle('is-active', on);
      card.classList.toggle('is-off', !on);
      card.querySelector('[data-smaily-trigger-settings]').hidden = !on;
      // On without a picked workflow, it sends nothing: "No workflow" in place of "Active".
      var table = automations.querySelector('[data-smaily-language-trigger="' + trigger + '"]');
      var selects = Array.prototype.slice.call(card.querySelectorAll('[data-smaily-workflows]'))
        .concat(table ? Array.prototype.slice.call(table.querySelectorAll('[data-smaily-workflows]')) : []);
      var picked = selects.some(function (select) {
        return workflowValue(select) !== '';
      });
      var pill = card.querySelector('[data-smaily-pill]');
      pill.classList.toggle('smaily-pill--active', on && picked);
      pill.classList.toggle('smaily-pill--pending', on && !picked);
      pill.querySelector('[data-smaily-pill-text]').textContent = labels(automations)(on ? (picked ? 'active' : 'no-workflow') : 'off');
      var languages = automations.querySelector('[data-smaily-language-workflows]');
      if (languages) {
        languages.querySelector('[data-smaily-language-trigger="' + trigger + '"]').hidden = !on;
        languages.hidden = !automations.querySelector('[data-smaily-trigger].is-active');
      }
      showUnmappedLanguages();
    }

    /*
     * A workflow or an account per language (modes `b` and `a`): the banner "Some languages have no
     * workflow" names, for each automation that is on, the languages whose contacts get no
     * workflow, read as the Router reads the rows: the language's own row, else the automation's
     * default fallback, else in mode `a` the row of the fallback account's language (Magento's
     * showUnmappedLanguages(), CON-188). A list not loaded yet counts its saved workflow.
     */
    function showUnmappedLanguages() {
      var banner = automations ? automations.querySelector('[data-smaily-unmapped]') : null;
      if (!banner) {
        return;
      }
      var accountFallback = automations.getAttribute('data-account-fallback') || '';
      var list = banner.querySelector('[data-smaily-unmapped-list]');
      list.textContent = '';
      automations.querySelectorAll('[data-smaily-language-trigger]').forEach(function (table) {
        var trigger = table.getAttribute('data-smaily-language-trigger');
        var workflow = function (language) {
          var select = table.querySelector('select[data-language="' + language + '"]');
          return select ? workflowValue(select) : '';
        };
        var fallback = checked(table, 'smaily-fallback-' + trigger);
        if (!automations.querySelector('[data-smaily-field="' + trigger + '.enabled"]').checked
          || (fallback !== '' && workflow(fallback) !== '')
          || (accountFallback !== '' && workflow(accountFallback) !== '')) {
          return;
        }
        var missing = [];
        table.querySelectorAll('select[data-language]').forEach(function (select) {
          if (workflowValue(select) === '') {
            missing.push(select.getAttribute('data-language').toUpperCase());
          }
        });
        if (missing.length) {
          var item = document.createElement('li');
          item.textContent = automations.querySelector('[data-smaily-trigger="' + trigger + '"] .smaily-trigger__name').textContent.trim() + ': ' + missing.join(', ');
          list.appendChild(item);
        }
      });
      banner.hidden = list.children.length === 0;
    }

    /*
     * After a Connection save that removed workflows, while the page stays (Settings): their
     * selects hold none, their cards say so, and the banner names them. A workflow that the page
     * draws no select for (in mode `b` the one of no language) is not named, as the panel's
     * `lost` leaves it out (AutomationsPanel::values()).
     */
    function lostWorkflows(entries) {
      if (!automations) {
        return;
      }
      var shown = entries.filter(function (entry) {
        var field = entry.trigger + (entry.language === '' ? '.workflow' : '.languages.' + entry.language);
        return automations.querySelector('[data-smaily-field="' + field + '"]') !== null;
      });
      if (shown.length === 0) {
        return;
      }
      var names = shown.map(function (entry) {
        var field = entry.trigger + (entry.language === '' ? '.workflow' : '.languages.' + entry.language);
        var select = automations.querySelector('[data-smaily-field="' + field + '"]');
        select.setAttribute('data-saved', '');
        if (select.options.length) {
          select.value = '';
        }
        showTrigger(automations.querySelector('[data-smaily-trigger="' + entry.trigger + '"]'));
        var name = automations.querySelector('[data-smaily-trigger="' + entry.trigger + '"] .smaily-trigger__name').textContent.trim();
        return entry.language === '' ? name : name + ' (' + entry.language.toUpperCase() + ')';
      }).join(', ');
      var banner = automations.querySelector('[data-smaily-lost-workflows]');
      banner.querySelector('[data-smaily-lost-message]').textContent = labels(banner)('message', {automations: names});
      banner.hidden = false;
    }

    /* What the Automations panel would save. */
    function automationsData() {
      var data = {triggers: {}, cart_delay: value(automations, 'cart_delay').trim()};
      automations.querySelectorAll('[data-smaily-trigger]').forEach(function (card) {
        var trigger = card.getAttribute('data-smaily-trigger');
        var item = {enabled: card.querySelector('[data-smaily-field="' + trigger + '.enabled"]').checked};
        var workflow = card.querySelector('[data-smaily-field="' + trigger + '.workflow"]');
        if (workflow) {
          item.workflow = workflowValue(workflow);
        }
        var table = automations.querySelector('[data-smaily-language-trigger="' + trigger + '"]');
        if (table) {
          item.languages = {};
          table.querySelectorAll('select').forEach(function (select) {
            item.languages[select.getAttribute('data-language')] = workflowValue(select);
          });
          item.fallback = checked(table, 'smaily-fallback-' + trigger);
        }
        item.discount = {
          enabled: card.querySelector('[data-smaily-field="' + trigger + '.discount.enabled"]').checked,
          rule: workflowValue(card.querySelector('[data-smaily-field="' + trigger + '.discount.rule"]')),
          days: value(card, trigger + '.discount.days').trim()
        };
        data.triggers[trigger] = item;
      });
      return data;
    }

    /* After a save, what the selects hold is what is saved: the 2.x opt-in option goes with it. */
    function automationsSaved() {
      automations.querySelectorAll('[data-smaily-workflows]').forEach(function (select) {
        select.setAttribute('data-saved', workflowValue(select));
        if (select.options.length) {
          fillWorkflows(select);
        }
      });
      automations.querySelectorAll('[data-smaily-discount-rules]').forEach(function (select) {
        select.setAttribute('data-saved', workflowValue(select));
      });
    }

    /*
     * The first contact import: its state, Start, Cancel, and the progress while it runs. A queued
     * or running import that nothing has moved for an hour is stalled (`stalled`): Run again
     * starts a fresh one, and Cancel import stays. Start stays, disabled, while the Contacts
     * settings are not saved or the mode imports nobody.
     */
    var importCard = (function (card) {
      if (!card) {
        return {saved: function () {}, mode: function () {}};
      }
      var word = labels(card);
      var job = JSON.parse(card.getAttribute('data-smaily-import') || 'null');
      var estimates = JSON.parse(card.getAttribute('data-smaily-import-estimates') || '{}');
      var timer = null;
      var logLine = card.querySelector('[data-smaily-import-log]');
      var start = card.querySelector('[data-smaily-action="import-start"]');
      var stop = card.querySelector('[data-smaily-action="import-stop"]');
      var importResult = card.querySelector('[data-smaily-import-result]');
      var startable = !start.disabled;
      // A job's look: the pill, its word, the bar and the outcome (smailyConnect.drawImport()).
      var looks = {
        queued: ['pending', 'state-pending', '', ''],
        running: ['pending', 'state-running', 'running', ''],
        done: ['active', 'state-done', 'done', 'done'],
        failures: ['active', 'state-done', 'failed', 'failures'],
        failed: ['failed', 'state-stopped', 'failed', 'stopped'],
        stalled: ['failed', 'state-stalled', 'failed', 'stalled'],
        cancelled: ['off', 'state-cancelled', 'stopped', 'cancelled']
      };

      function draw() {
        var active = !!job && (job.status === 'queued' || job.status === 'running');
        var counts = job ? {sent: job.sent, total: job.total, failed: job.failed} : {};
        var state = !job ? '' : (job.stalled ? 'stalled' : (job.status === 'done' && job.failed > 0 ? 'failures' : job.status));
        var shown = looks[state] || ['', '', '', ''];
        var text = '';
        if (state === 'stalled') {
          text = word('stalled', counts);
        } else if (state === 'queued') {
          text = word('queued');
        } else if (state === 'running') {
          text = word('importing');
        } else if (state === 'done' || state === 'failures') {
          // Contacts that Smaily refused one by one: the Log's Details list them.
          var refused = job.failed > 0 && job.refused === job.failed;
          text = job.last_error === 'contact_sync_off' ? word('sync-off') : word(job.failed > 0 ? (refused ? 'done-refused' : 'done-failed') : 'done', counts);
        } else if (state === 'cancelled') {
          text = word('cancelled', counts);
        } else if (state === 'failed') {
          // Where it stopped, in the admin's language (Admin\Setup\ImportDetails); never a text of Smaily (Magento CON-233).
          text = word('stopped', counts) + (job.details ? ' ' + word('details', {details: job.details}) : '');
        }
        smailyConnect.drawImport(card, {
          pill: shown[0],
          pillText: shown[1] ? word(shown[1]) : '',
          bar: shown[2],
          count: job ? word('progress', counts) : '',
          percent: !job ? 0 : (state === 'done' ? 100 : smailyConnect.importPercent(job)),
          text: text,
          outcome: shown[3],
          finished: job ? job.finished : ''
        });
        // The estimate stands before and during a run; an outcome takes its place (Magento's card).
        card.querySelector('[data-smaily-import-estimate]').hidden = !startable || !!shown[3];
        logLine.hidden = state !== 'failures';
        start.hidden = active && state !== 'stalled';
        start.textContent = word(job && (!active || state === 'stalled') ? 'run-again' : 'start');
        start.disabled = !startable;
        stop.hidden = !active;
        window.clearTimeout(timer);
        if (active && state !== 'stalled') {
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
        }).catch(function (error) {
          show(importResult, error.message, false);
        }).then(function () {
          stop.disabled = false;
          draw();
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
          startable = enabled && mode !== 'checkout_optin';
          card.querySelector('[data-smaily-import-unsaved]').hidden = enabled;
          card.querySelector('[data-smaily-import-nobody]').hidden = !enabled || mode !== 'checkout_optin';
          draw();
        },
        // The estimate of the mode picked on the panel, saved or not (Magento PRO-3582).
        mode: function (mode) {
          card.querySelector('[data-smaily-import-estimate]').textContent = estimates[mode] || '';
        }
      };
    }(root.querySelector('[data-smaily-import]')));

    showUnmappedLanguages();

    /*
     * An edited account field undoes the block's passed test, and the state of the account of no
     * language clears until a test or a save answers again (owner, 2026-11-25), as WooCommerce.
     */
    root.addEventListener('input', function (event) {
      var accountBlock = event.target.closest('[data-smaily-account-block]');
      if (!accountBlock) {
        return;
      }
      if (accountBlock.hasAttribute('data-smaily-tested')) {
        show(accountBlock.querySelector('[data-smaily-test-result]'), '', true);
      }
      var status = accountBlock.querySelector('[data-smaily-connection-status]');
      if (status) {
        status.hidden = true;
      }
      markTested(accountBlock, false);
    });

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
      if (/\.discount\.enabled$/.test(input.getAttribute('data-smaily-field') || '')) {
        input.closest('.smaily-trigger__discount').querySelector('[data-smaily-discount-body]').hidden = !input.checked;
      } else if (/\.enabled$/.test(input.getAttribute('data-smaily-field') || '') && input.closest('[data-smaily-trigger]')) {
        showTrigger(input.closest('[data-smaily-trigger]'));
      } else if (input.hasAttribute('data-smaily-workflows')) {
        var owner = input.closest('[data-smaily-trigger]') || input.closest('[data-smaily-language-trigger]');
        showTrigger(automations.querySelector('[data-smaily-trigger="' + (owner.getAttribute('data-smaily-trigger') || owner.getAttribute('data-smaily-language-trigger')) + '"]'));
      }
      if (input.matches('.smaily-choice__input')) {
        root.querySelectorAll('input[name="' + input.name + '"]').forEach(function (other) {
          other.closest('.smaily-choice').classList.toggle('is-selected', other.checked);
        });
      }
      if (input.name === 'smaily-contacts-mode') {
        importCard.mode(input.value);
        // As WooCommerce (CON-439): the guest switch is drawn in All customers only; it keeps its value.
        root.querySelector('[data-smaily-guests]').hidden = input.value !== 'all_customers';
      }
      if (automations && automations.contains(input)) {
        showUnmappedLanguages();
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
      holds: holds,
      accountBlocks: accountBlocks,
      pickLanguageMode: pickLanguageMode,
      connectionSaved: connectionSaved,
      confirmAccountChange: confirmAccountChange,
      connectionTested: connectionTested,
      lostWorkflows: lostWorkflows,
      showStatus: showStatus,
      connectionData: connectionData,
      tested: tested,
      contactsData: contactsData,
      automationsData: automationsData,
      automationsSaved: automationsSaved,
      loadWorkflows: loadWorkflows,
      importCard: importCard,
      onChange: function (pageHook) {
        hook = pageHook;
      },
      onTested: function (pageHook) {
        testedHook = pageHook;
      }
    };
  };
}());
