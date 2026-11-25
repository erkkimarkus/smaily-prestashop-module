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
 * typed again. An edit of a block asks for the test again. Back keeps what
 * was typed: the steps stay on the page. The Automations step is drawn for the saved language
 * mode, so a Connect save that changes the mode opens the page again on step 2. The Intelligence
 * step belongs to later work; Continue passes through it, and Finish on step 4 runs
 * window.smailyConnect.setup.finish(), which a later saver of step 4 calls after its own save.
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

    /* Connect continues once a block passed Test connection and no filled block waits for one. */
    var storedChecked = false;

    function connectHint() {
      var connection = panels.panel('connection');
      if (!connection) {
        return '';
      }
      // The stored accounts are asked about when the page first asks: the rail asks on any step.
      if (!storedChecked) {
        storedChecked = true;
        checkStored(connection);
      }
      var passed = 0;
      var untested = 0;
      panels.accountBlocks(connection, panels.checked(connection, 'smaily-language-mode') || 'single').forEach(function (accountBlock) {
        var values = panels.credentials(accountBlock);
        if (accountBlock.hasAttribute('data-smaily-tested')) {
          passed += 1;
        } else if (values.subdomain || values.username || values.password) {
          untested += 1;
        }
      });
      return passed > 0 && untested === 0 ? '' : label('test-to-continue');
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


    function saveConnect() {
      var step = stepPanel(1);
      panels.clearErrors(step);
      var data = panels.panel('connection') ? panels.connectionData() : {};
      return post('save', {step: 'connect', data: data}).then(function (answer) {
        if (panels.connectionSaved(data.language_mode)) {
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
      var data = panels.panel('contacts') ? panels.contactsData() : {};
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
      return post('save', {step: 'automations', data: panels.automationsData()}).then(panels.automationsSaved).catch(function (error) {
        failed(step, error);
      });
    }

    /* Finish: the setup is done; the page opens again on the Overview. */
    smailyConnect.setup.finish = function () {
      return post('save', {step: 'finish'}).then(function () {
        return reopen(5);
      });
    };

    smailyConnect.setup.onContinue(1, saveConnect);
    smailyConnect.setup.onContinue(2, saveContacts);
    smailyConnect.setup.onContinue(3, saveAutomations);
    smailyConnect.setup.onContinue(4, smailyConnect.setup.finish);

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
    smailyConnect.setup.refresh();
    panels.loadWorkflows(null);
  });
}());
