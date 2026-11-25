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
 * The Settings page's tabs Connection, Contacts, Automations and RSS
 * (views/templates/admin/settings/ and the panels of panel/), on the shell of common.js, which
 * switches the tabs, and the panels of panels.js. Plain JavaScript, no build. As Magento's
 * Settings:
 *
 * - every tab saves on its own (Admin\Api\Settings\SaveTab), and a refused save marks each field
 *   at fault with its message;
 * - a tab's switch "Use its own … on this shop", unticked on a shop that holds own values, mutes the
 *   tab until the save removes them; the page then reloads to show the shared values;
 * - the connection's state is asked of Smaily once the page has loaded;
 * - the workflow lists of the Automations tab are asked of Smaily when the tab first shows, and
 *   again on Refresh workflows; a saved language mode that changed reloads the page, because the
 *   Automations tab is drawn for the saved mode;
 * - Change of the store layout opens the choice above the tabs; to one store it asks first;
 * - the RSS builder builds the feed URL from the picked values, with Copy.
 *
 * Settings also asks before a change of who is synced to Smaily on a store that already syncs,
 * so that the audience never widens unnoticed.
 */
(function () {
  'use strict';

  var smailyConnect = window.smailyConnect = window.smailyConnect || {};

  smailyConnect.ready(function () {
    var root = document.querySelector('[data-smaily-settings-root]');
    if (!root || !smailyConnect.api) {
      return;
    }
    var panels = smailyConnect.panels(root, 'connected');
    var label = panels.label;
    var post = panels.post;
    var panel = panels.panel;
    var checked = panels.checked;
    var value = panels.value;
    var result = smailyConnect.show;

    function tabPanel(tab) {
      return root.querySelector('.smaily-tab-panel[data-tab="' + tab + '"]');
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

    /* Connection: the language mode, and the state of the stored accounts. */
    var connection = panel('connection');

    panels.onChange(function (input) {
      if (input.name === 'smaily-language-mode') {
        panels.pickLanguageMode(input);
      }
      if (input.name === 'smaily-shape-change') {
        showShapeNote();
      }
    });

    /*
     * The state of each stored account, asked of Smaily after the page has loaded (Magento's
     * Settings shows it under the account): the account of no language, or in mode `a` each
     * language's account.
     */
    function checkStoredAccounts() {
      if (!connection) {
        return;
      }
      panels.accountBlocks(connection, checked(connection, 'smaily-language-mode') || 'single').forEach(function (accountBlock) {
        var data = panels.credentials(accountBlock);
        if (data.subdomain === '') {
          return;
        }
        post('test', {saved: true, language: data.language}).then(function (answer) {
          if (answer.connected === null) {
            return;
          }
          if (data.language === '') {
            panels.showStatus(answer.connected === true);
          } else {
            result(accountBlock.querySelector('[data-smaily-test-result]'), label(answer.connected ? 'connected' : 'not-connected'), answer.connected === true);
          }
        }).catch(function () {
          // No right to ask, or Smaily out of reach: the state stays unsaid.
        });
      });
    }

    /* Automations: the workflow lists load when the tab first shows. */
    var workflowsAsked = false;

    function loadWorkflows() {
      if (panel('automations')) {
        workflowsAsked = true;
        panels.loadWorkflows(tabPanel('automations').querySelector('[data-smaily-tab-result]'));
      }
    }

    /* Contacts: what a save changes about who is synced. */
    var contacts = panel('contacts');

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

    /* RSS: the builder, whose data attributes carry the feed's own defaults and its largest limit. */
    var builder = root.querySelector('[data-smaily-rss-builder]');

    function rssData() {
      var fields = panel('rss');
      return {
        category: value(fields, 'category').trim(),
        limit: value(fields, 'limit').trim(),
        sort_by: value(fields, 'sort_by'),
        sort_order: value(fields, 'sort_order')
      };
    }

    /* Save Connection, Save Contacts, Save: each tab on its own (PRO-3641). */
    function saveTab(button) {
      var tab = button.getAttribute('data-tab');
      var scope = tabPanel(tab);
      var tabResult = scope.querySelector('[data-smaily-tab-result]');
      var own = ownSwitch(tab);
      var collect = {connection: panels.connectionData, contacts: panels.contactsData, automations: panels.automationsData, rss: rssData};
      var data = collect[tab]();
      if (tab === 'contacts' && !confirmAudience(data, own)) {
        return;
      }
      panels.clearErrors(scope);
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
          if (panels.connectionSaved(data.language_mode)) {
            reload();
            return;
          }
          if (workflowsAsked) {
            loadWorkflows();
          }
          panels.showStatus(!!answer.connected);
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
            panels.importCard.saved(data.enabled !== false, data.mode);
          } else if (tab === 'automations') {
            panels.automationsSaved();
          }
          result(tabResult, label('saved'), true);
        }
      }).catch(function (error) {
        result(tabResult, panels.showErrors(scope, error.fields) ? label('saving-failed') : error.message, false);
      }).then(function () {
        button.disabled = false;
      });
    }

    /* The feed URL from the picked values; a value equal to the feed's default is left out. */
    function buildFeedUrl() {
      var data = rssData();
      var params = [];
      if (/^[1-9][0-9]*$/.test(data.category)) {
        params.push('category_id=' + encodeURIComponent(data.category));
      }
      var limit = parseInt(data.limit, 10);
      var maxLimit = parseInt(builder.getAttribute('data-max-limit'), 10);
      if (limit && String(Math.min(maxLimit, Math.max(1, limit))) !== builder.getAttribute('data-default-limit')) {
        params.push('limit=' + Math.min(maxLimit, Math.max(1, limit)));
      }
      if (data.sort_by !== builder.getAttribute('data-default-sort-by')) {
        params.push('sort_by=' + encodeURIComponent(data.sort_by));
      }
      if (data.sort_order !== builder.getAttribute('data-default-sort-order')) {
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

    /*
     * How the shops are run: Change opens the choice. The page asks before a change to one store,
     * and before a change that carries or ends a Campaign Intelligence connection.
     */
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
      } else if (action === 'refresh-workflows') {
        loadWorkflows();
      } else if (action === 'rss-copy') {
        copyFeedUrl();
      } else if (action === 'shape-change') {
        showShapeChoice(true);
        showShapeNote();
      } else if (action === 'shape-cancel') {
        showShapeChoice(false);
      } else if (action === 'shape-save') {
        var chosen = checked(shapeChange, 'smaily-shape-change');
        // What the change removes or ends: the other shops' settings, Campaign Intelligence connections.
        var asked = [];
        shapeChange.querySelectorAll('[data-smaily-shape-ask][data-smaily-shape-note="' + chosen + '"]').forEach(function (note) {
          asked.push(note.textContent.trim());
        });
        if (chosen === shape.getAttribute('data-shape')) {
          showShapeChoice(false);
        } else if (!asked.length || window.confirm(asked.join('\n\n'))) {
          saveLayout(target, chosen, shapeChange.querySelector('[data-smaily-shape-result]'));
        }
      } else if (action === 'save-shape') {
        saveLayout(target, checked(shape, 'smaily-shape'), shape.querySelector('[data-smaily-shape-result]'));
      }
    });

    checkStoredAccounts();
    root.querySelector('[role=tab][data-tab="automations"]').addEventListener('click', function () {
      if (!workflowsAsked) {
        loadWorkflows();
      }
    });
    if (!tabPanel('automations').hidden) {
      loadWorkflows();
    }
  });
}());
