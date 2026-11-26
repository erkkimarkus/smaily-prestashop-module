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
 * of common.js and the panels of panels.js: the store-layout question, the Connection, Contacts
 * and Automations steps, Finish, and the Overview's connection state. Plain JavaScript, no build.
 *
 * Continue saves a step through Admin\Api\Setup\SaveStep (window.smailyConnect.setup.onContinue);
 * a refused save marks each field at fault with its message and keeps the step. On Connect,
 * Continue waits until every account the step would save passed Test connection (owner,
 * 2026-11-25), as WooCommerce and Shopify, and the step list opens no later step until then; a
 * stored account counts once Smaily accepts it after the page opens, so a saved password is not
 * typed again. An edit of a block asks for the test again. Continue asks first before another
 * Smaily account takes a stored one's place while an automation has a workflow picked. Back keeps
 * what was typed: the steps stay on the page. The Automations step is drawn for the saved language
 * mode and workflows, so a Connect save that changes the mode, or that removed workflows
 * (Automation\LostWorkflows), opens the page again on step 2. Continue on step 4 runs
 * window.smailyConnect.setup.overview(), which intelligence.js calls after its own save: the page
 * opens again on the Overview, which then shows what the steps saved. The Overview's Finish ends
 * the setup and opens the Dashboard, as WooCommerce's Finish (owner, 2026-11-26; Linear CON-460).
 *
 * As in Magento's wizard: a finished setup opens step 1 on the connection's summary, where
 * Continue saves nothing until "Edit credentials" shows the form; the store-layout bar's Change
 * runs Settings' change of the layout; and the Connect, Contacts and Automations saves name in
 * `use_shared` the shops listed under the step whose own values give way to the step's values.
 */
(function () {
  'use strict';

  var smailyConnect = window.smailyConnect = window.smailyConnect || {};

  smailyConnect.ready(function () {
    var root = document.querySelector('[data-smaily-setup-root]');
    var container = root && root.querySelector('[data-smaily-setup]');
    if (!container || !smailyConnect.setup) {
      return;
    }
    var panels = smailyConnect.panels(root, 'connected-next');
    var label = panels.label;
    var post = panels.post;
    var result = smailyConnect.show;

    function stepPanel(step) {
      return container.querySelector('.smaily-step-panel[data-step="' + step + '"]');
    }

    panels.onChange(function (input) {
      if (input.name === 'smaily-language-mode') {
        panels.pickLanguageMode(input);
        smailyConnect.setup.refresh();
      }
      if (input.name === 'smaily-shape') {
        showShapeNotes();
      }
      if (input.name === 'smaily-shape-change') {
        showShapeChangeNotes();
      }
    });

    /*
     * Smaily's answer about a stored account, asked once per language: step 1 and the Overview
     * share it, so an account is not checked twice.
     */
    var savedTests = {};

    function testSaved(language) {
      if (!Object.prototype.hasOwnProperty.call(savedTests, language)) {
        savedTests[language] = post('test', {saved: true, language: language});
      }
      return savedTests[language];
    }

    /*
     * A finished setup opens step 1 on the connection's summary (Magento PRO-3563): Continue moves
     * on without a save until "Edit credentials" shows the form.
     */
    var summary = container.querySelector('[data-smaily-setup-summary]');

    /* Connect continues once a block passed Test connection and no filled block waits for one. */
    var storedChecked = false;

    function connectHint() {
      var connection = panels.panel('connection');
      if (!connection || summary) {
        return '';
      }
      // The stored accounts are asked about when the page first asks: the rail asks on any step.
      if (!storedChecked) {
        storedChecked = true;
        checkStored(connection);
      }
      return panels.connectionTested(connection) ? '' : label('test-to-continue');
    }

    /* Each stored account of the saved mode is asked about once; an accepted one counts as tested. */
    function checkStored(connection) {
      panels.accountBlocks(connection, connection.getAttribute('data-language-mode')).forEach(function (accountBlock) {
        if (accountBlock.getAttribute('data-has-password') !== '1') {
          return;
        }
        var values = panels.credentials(accountBlock);
        testSaved(values.language).then(function (answer) {
          if (answer.connected === true) {
            panels.tested(accountBlock, values, answer);
          }
        }).catch(function () {
          // Not counted: the merchant tests the account.
        });
      });
    }

    panels.onTested(function () {
      smailyConnect.setup.refresh();
    });
    smailyConnect.setup.gate(1, connectHint);

    /* The store-layout question comes first while no layout is stored (Magento CON-279). */
    var shape = panels.panel('store-shape');
    if (shape && shape.getAttribute('data-first') === '1') {
      container.querySelector('.smaily-steps').hidden = true;
      container.style.gridTemplateColumns = 'minmax(0, 1fr)';
      container.querySelector('.smaily-setup__pane > .smaily-footer').hidden = true;
      container.querySelector('[data-smaily-shape-page]').hidden = true;
      var invitation = container.querySelector('[data-smaily-invitation]');
      if (invitation) {
        invitation.hidden = true;
      }
    }

    /* What the answer does with the Campaign Intelligence connections shows while it is picked. */
    function showShapeNotes() {
      var chosen = panels.checked(shape, 'smaily-shape');
      shape.querySelectorAll('[data-smaily-shape-note]').forEach(function (note) {
        note.hidden = note.getAttribute('data-smaily-shape-note') !== chosen;
      });
    }

    function saveShape(button) {
      var shapeResult = shape.querySelector('[data-smaily-shape-result]');
      // An answer that carries or ends a connection is asked first, as Settings' Change is.
      var asked = [];
      shape.querySelectorAll('[data-smaily-shape-ask][data-smaily-shape-note="' + panels.checked(shape, 'smaily-shape') + '"]').forEach(function (note) {
        asked.push(note.textContent.trim());
      });
      if (asked.length && !window.confirm(asked.join('\n\n'))) {
        return;
      }
      button.disabled = true;
      result(shapeResult, label('saving'), true);
      post('save', {step: 'shape', data: {shape: panels.checked(shape, 'smaily-shape')}}).then(function () {
        window.location.reload();
      }).catch(function (error) {
        result(shapeResult, error.message, false);
        button.disabled = false;
      });
    }

    /*
     * The bar's Change (Magento's wizard): the choice, with what each direction does, and the same
     * change and questions as on Settings (Admin\Api\Settings\ChangeLayout). The steps wait while
     * the choice shows, as they wait for a first answer.
     */
    var shapeChange = container.querySelector('[data-smaily-shape-change]');

    function showShapeChangeNotes() {
      var current = shape.getAttribute('data-shape');
      var chosen = panels.checked(shapeChange, 'smaily-shape-change');
      shapeChange.querySelectorAll('[data-smaily-shape-note]').forEach(function (note) {
        note.hidden = chosen === current || note.getAttribute('data-smaily-shape-note') !== chosen;
      });
    }

    function showShapeChange(open) {
      container.querySelector('.smaily-steps').hidden = open;
      container.style.gridTemplateColumns = open ? 'minmax(0, 1fr)' : '';
      container.querySelector('.smaily-setup__pane > .smaily-footer').hidden = open;
      container.querySelector('[data-smaily-shape-page]').hidden = open;
      var invitation = container.querySelector('[data-smaily-invitation]');
      if (invitation) {
        invitation.hidden = open;
      }
      shapeChange.hidden = !open;
      result(shapeChange.querySelector('[data-smaily-shape-result]'), '', true);
      if (!open) {
        var current = shapeChange.querySelector('input[value="' + shape.getAttribute('data-shape') + '"]');
        current.checked = true;
        current.dispatchEvent(new Event('change', {bubbles: true}));
      }
      showShapeChangeNotes();
    }

    function changeShape(button) {
      var chosen = panels.checked(shapeChange, 'smaily-shape-change');
      if (chosen === shape.getAttribute('data-shape')) {
        showShapeChange(false);
        return;
      }
      // What the change removes or ends: the other shops' settings, Campaign Intelligence connections.
      var asked = [];
      shapeChange.querySelectorAll('[data-smaily-shape-ask][data-smaily-shape-note="' + chosen + '"]').forEach(function (note) {
        asked.push(note.textContent.trim());
      });
      if (asked.length && !window.confirm(asked.join('\n\n'))) {
        return;
      }
      var shapeResult = shapeChange.querySelector('[data-smaily-shape-result]');
      button.disabled = true;
      result(shapeResult, label('saving'), true);
      post('layout', {shape: chosen}).then(function () {
        window.location.reload();
      }).catch(function (error) {
        result(shapeResult, error.message, false);
        button.disabled = false;
      });
    }

    /* Edit credentials: the summary of a finished setup gives way to the form, which saves again. */
    function editCredentials(button) {
      summary.hidden = true;
      summary = null;
      button.hidden = true;
      var form = container.querySelector('[data-smaily-connect-form]');
      form.hidden = false;
      smailyConnect.setup.refresh();
      var field = form.querySelector('[data-smaily-field="subdomain"]');
      if (field) {
        field.focus();
      }
    }

    /* The summary's state: Smaily's answer about the stored account. */
    function checkSummary() {
      var pill = summary && summary.querySelector('[data-smaily-summary-state]');
      if (!pill) {
        return;
      }
      testSaved('').then(function (answer) {
        return answer.connected === true;
      }).catch(function () {
        return false;
      }).then(function (connected) {
        if (connected) {
          pill.classList.add('smaily-pill--active');
        }
        pill.querySelector('[data-smaily-pill-text]').textContent = label(connected ? 'connected' : 'not-connected');
      });
    }

    function failed(scope, error) {
      throw new Error(panels.showErrors(scope, error.fields) ? label('saving-failed') : error.message);
    }

    /* Opens the page again on a step, which the server draws from what is saved now. */
    function reopen(step) {
      var url = new URL(root.getAttribute('data-setup-url'), window.location.href);
      new URL(window.location.href).searchParams.forEach(function (paramValue, key) {
        url.searchParams.set(key, paramValue);
      });
      url.searchParams.set('step', String(step));
      window.location.href = url.toString();
      return new Promise(function () {});
    }


    /* The shops listed under a step whose own values give way to the step's values (Magento CON-179). */
    function withShared(section, data) {
      data.use_shared = [];
      container.querySelectorAll('[data-smaily-shop-overrides="' + section + '"] input[value="shared"]:checked').forEach(function (input) {
        data.use_shared.push(parseInt(input.getAttribute('data-shop'), 10));
      });
      return data;
    }

    function saveConnect() {
      var step = stepPanel(1);
      panels.clearErrors(step);
      if (summary) {
        return Promise.resolve();
      }
      if (panels.panel('connection') && !panels.confirmAccountChange()) {
        return Promise.reject({declined: true});
      }
      var data = withShared('connection', panels.panel('connection') ? panels.connectionData() : {});
      return post('save', {step: 'connect', data: data}).then(function (answer) {
        if (panels.connectionSaved(data.language_mode) || (answer.workflows_removed || []).length) {
          return reopen(2);
        }
        panels.showStatus(!!answer.connected);
        container.querySelectorAll('[data-smaily-field$="password"]').forEach(function (input) {
          input.value = '';
        });
        // The workflow lists are those of the account saved now.
        panels.loadWorkflows(null);
      }).catch(function (error) {
        failed(step, error);
      });
    }

    function saveContacts() {
      var step = stepPanel(2);
      panels.clearErrors(step);
      var data = withShared('contacts', panels.panel('contacts') ? panels.contactsData() : {});
      return post('save', {step: 'contacts', data: data}).then(function () {
        panels.importCard.saved(data.enabled !== false, data.mode);
      }).catch(function (error) {
        failed(step, error);
      });
    }

    function saveAutomations() {
      var step = stepPanel(3);
      panels.clearErrors(step);
      if (!panels.panel('automations')) {
        return Promise.resolve();
      }
      return post('save', {step: 'automations', data: withShared('automations', panels.automationsData())}).then(panels.automationsSaved).catch(function (error) {
        failed(step, error);
      });
    }

    /* Step 4's Continue: the page opens again on the Overview, drawn from what is saved. */
    smailyConnect.setup.overview = function () {
      return reopen(5);
    };

    /* The Overview's Finish: the setup is done, and the Dashboard opens. */
    function finish(button) {
      var line = container.querySelector('[data-smaily-finish-result]');
      button.disabled = true;
      result(line, label('saving'), true);
      post('save', {step: 'finish'}).then(function (answer) {
        window.location.href = answer.dashboard;
      }).catch(function (error) {
        result(line, error && error.message ? error.message : label('saving-failed'), false);
        button.disabled = false;
      });
    }

    smailyConnect.setup.onContinue(1, saveConnect);
    smailyConnect.setup.onContinue(2, saveContacts);
    smailyConnect.setup.onContinue(3, saveAutomations);
    smailyConnect.setup.onContinue(4, smailyConnect.setup.overview);

    /* The Overview asks Smaily about each stored account when it shows. */
    function checkAccounts() {
      var states = container.querySelectorAll('[data-smaily-account-state]');
      var pending = states.length;
      var allConnected = pending > 0;
      states.forEach(function (pill) {
        testSaved(pill.getAttribute('data-smaily-account-state')).then(function (answer) {
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
      } else if (action === 'shape-change') {
        showShapeChange(true);
      } else if (action === 'shape-cancel') {
        showShapeChange(false);
      } else if (action === 'shape-save') {
        changeShape(target);
      } else if (action === 'edit-credentials') {
        editCredentials(target);
      } else if (action === 'setup-finish') {
        finish(target);
      } else if (action === 'refresh-workflows') {
        // A list that cannot be loaded says why beside the step's buttons, as in Magento.
        panels.loadWorkflows(container.querySelector('.smaily-footer [data-smaily-result]'));
      } else if (action === 'setup-goto') {
        event.preventDefault();
        var item = container.querySelector('.smaily-steps li[data-step="' + target.getAttribute('data-step') + '"]');
        if (item) {
          item.click();
        }
      }
    });

    if (!stepPanel(5).hidden) {
      checkAccounts();
    }
    checkSummary();
    smailyConnect.setup.refresh();
    panels.loadWorkflows(null);
  });
}());
