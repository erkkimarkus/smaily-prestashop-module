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
 * The card "Campaign Intelligence Automations" of Settings > Automations
 * (views/templates/admin/settings/engine-automations.html.twig), on the shell of common.js. Plain
 * JavaScript, no build. As Magento's config/engine-automations.phtml:
 *
 * - the card's content is asked of Campaign Intelligence when the Automations tab first shows
 *   (Admin\Api\Settings\EngineAutomationsCard), so it lists what the engine offers at that moment;
 * - a row shows its settings while Enabled is ticked, or while one of its fields has an error to
 *   fix; the values of a closed row stay in the form;
 * - the card saves on its own (Admin\Api\Settings\SaveEngineAutomations), every field of every
 *   row. After a save the card is drawn again from what Campaign Intelligence answers; a refused
 *   save marks each field at fault with its message and changes nothing else;
 * - an email's Test mode switch and "Send test email" (CON-459, as Magento's CON-458) are no form
 *   fields: each calls Campaign Intelligence at once, once per press, and is never repeated on its
 *   own (Admin\Api\Settings\EngineTestMode, EngineTestSend). Turning test mode off asks "Turn off
 *   test mode?" first; turning it on again asks nothing. The switch shows the change only once
 *   Campaign Intelligence made it: every answer carries the email's state as Campaign
 *   Intelligence reads it after the call, which the row then shows (the switch, the pill, the
 *   state line and the notice), with the answer's message and reasons.
 */
(function () {
  'use strict';

  var smailyConnect = window.smailyConnect = window.smailyConnect || {};

  smailyConnect.ready(function () {
    var card = document.querySelector('[data-smaily-engine-automations]');
    if (!card || !smailyConnect.api) {
      return;
    }
    var body = card.querySelector('[data-smaily-engine-body]');
    var asked = false;
    /* The Test mode block whose switch waits for the dialog's answer. */
    var pending = null;

    function field(name) {
      return body.querySelector('[data-smaily-engine-field="' + name + '"]');
    }

    function result(text, ok) {
      smailyConnect.show(body.querySelector('[data-smaily-engine-result]'), text, ok);
    }

    function load() {
      asked = true;
      smailyConnect.api(card.getAttribute('data-api-load')).then(function (answer) {
        body.innerHTML = answer.html;
      }).catch(function (error) {
        var line = document.createElement('p');
        line.className = 'smaily-result is-error';
        line.textContent = error.message;
        body.textContent = '';
        body.appendChild(line);
      });
    }

    /* A row is open while it is enabled, or while one of its fields has an error to fix. */
    function applyOpen(trigger) {
      var key = trigger.getAttribute('data-smaily-engine-trigger');
      var open = field(key + '.enabled').checked || trigger.querySelector('.smaily-field-error') !== null;
      trigger.querySelectorAll('[data-smaily-engine-settings]').forEach(function (node) {
        node.hidden = !open;
      });
    }

    /* Takes the messages and the marks off the fields inside `scope`. */
    function clearFieldErrors(scope) {
      scope.querySelectorAll('.smaily-field-error').forEach(function (node) {
        node.parentNode.removeChild(node);
      });
      scope.querySelectorAll('.is-invalid').forEach(function (node) {
        node.classList.remove('is-invalid');
        node.removeAttribute('aria-invalid');
      });
    }

    function clearErrors() {
      var banner = body.querySelector('[data-smaily-engine-errors]');
      if (banner) {
        banner.hidden = true;
      }
      clearFieldErrors(body);
      body.querySelectorAll('[data-smaily-engine-trigger]').forEach(applyOpen);
    }

    /* The summary above the rows, and each message under its field. */
    function showErrors(summary, fields) {
      var banner = body.querySelector('[data-smaily-engine-errors]');
      banner.querySelector('[data-smaily-engine-errors-text]').textContent = summary;
      banner.hidden = false;
      fields.forEach(function (error) {
        var input = field(error.field);
        if (!input) {
          return;
        }
        var message = document.createElement('p');
        message.className = 'smaily-field-error';
        message.textContent = error.message;
        var label = input.closest('label');
        if (input.type === 'checkbox') {
          // Under the row of switches, not inside it.
          label.parentNode.parentNode.insertBefore(message, label.parentNode.nextSibling);
        } else {
          label.appendChild(message);
        }
        input.classList.add('is-invalid');
        input.setAttribute('aria-invalid', 'true');
        applyOpen(input.closest('[data-smaily-engine-trigger]'));
      });
    }

    /* Every row with every field, and the mode and workflow map the row was drawn with. */
    function collect() {
      var triggers = {};
      body.querySelectorAll('[data-smaily-engine-trigger]').forEach(function (trigger) {
        var key = trigger.getAttribute('data-smaily-engine-trigger');
        triggers[key] = {
          enabled: field(key + '.enabled').checked,
          workflow_id: field(key + '.workflow_id').value,
          cooldown_days: field(key + '.cooldown_days').value,
          daily_cap: field(key + '.daily_cap').value,
          test_emails: field(key + '.test_emails').value,
          language_mode: trigger.getAttribute('data-language-mode'),
          original_map: JSON.parse(trigger.getAttribute('data-original-map'))
        };
      });
      return triggers;
    }

    function save(button) {
      clearErrors();
      button.disabled = true;
      result(card.getAttribute('data-label-saving'), true);
      smailyConnect.api(card.getAttribute('data-api-save'), {triggers: collect()}).then(function (answer) {
        // The state that Campaign Intelligence answers, not the one typed.
        if (answer.html !== null) {
          body.innerHTML = answer.html;
        }
        button.disabled = false;
        result(answer.message, true);
      }).catch(function (error) {
        button.disabled = false;
        if (error.code === 'card_changed') {
          // The account is refused, or the connection is gone: the card says which.
          load();
        } else if (error.code === 'invalid_fields') {
          showErrors(error.message, error.fields || []);
          result(card.getAttribute('data-label-saving-failed'), false);
        } else {
          result(error.message, false);
        }
      });
    }

    /* The email's state as Campaign Intelligence reads it now; null leaves the row as it is. */
    function showState(block, state) {
      if (!state) {
        return;
      }
      var trigger = block.closest('[data-smaily-engine-trigger]');
      var pill = trigger.querySelector('[data-smaily-engine-pill]');
      var status = block.querySelector('[data-smaily-test-mode-status]');
      var notice = block.querySelector('[data-smaily-test-mode-notice]');
      block.querySelector('[data-smaily-test-mode-switch]').checked = state.test_mode;
      block.querySelector('[data-smaily-test-mode-test]').hidden = !state.test_mode;
      status.textContent = state.status;
      status.hidden = state.status === '';
      notice.textContent = state.notice;
      notice.hidden = state.notice === '';
      trigger.classList.remove('is-off', 'is-test', 'is-stopped', 'is-active');
      trigger.classList.add('is-' + state.mode);
      pill.classList.toggle('smaily-pill--active', state.mode === 'active');
      pill.classList.toggle('smaily-pill--pending', state.mode === 'test');
      pill.classList.toggle('smaily-pill--failed', state.mode === 'stopped');
      pill.querySelector('[data-smaily-engine-pill-text]').textContent = state.pill;
    }

    /* The answer's message, with the engine's reasons in a list under it. */
    function showAnswer(block, answer) {
      var box = block.querySelector('[data-smaily-test-mode-result]');
      box.textContent = '';
      box.classList.toggle('is-error', !answer.done);
      if (answer.message !== '') {
        var line = document.createElement('p');
        line.textContent = answer.message;
        box.appendChild(line);
      }
      if (answer.reasons.length) {
        var list = document.createElement('ul');
        answer.reasons.forEach(function (reason) {
          var item = document.createElement('li');
          item.textContent = reason;
          list.appendChild(item);
        });
        box.appendChild(list);
      }
      box.hidden = box.childNodes.length === 0;
      showState(block, answer.state);
    }

    function showFailure(block, error) {
      if (error.code === 'card_changed') {
        // The account is refused, or the connection is gone: the card says which.
        load();
        return;
      }
      showAnswer(block, {done: false, message: error.message, reasons: [], state: null});
    }

    function call(block, url, data, control) {
      control.disabled = true;
      data.key = block.closest('[data-smaily-engine-trigger]').getAttribute('data-smaily-engine-trigger');
      smailyConnect.api(url, data).then(function (answer) {
        control.disabled = false;
        showAnswer(block, answer);
      }).catch(function (error) {
        control.disabled = false;
        showFailure(block, error);
      });
    }

    function switchTestMode(block, on) {
      var data = {test_mode: on};
      if (!on) {
        data.confirmed = true;
      }
      call(block, card.getAttribute('data-api-test-mode'), data, block.querySelector('[data-smaily-test-mode-switch]'));
    }

    function dialog() {
      return body.querySelector('[data-smaily-test-mode-dialog]');
    }

    function closeDialog() {
      var block = pending;
      pending = null;
      dialog().hidden = true;
      if (block) {
        block.querySelector('[data-smaily-test-mode-switch]').focus();
      }
      return block;
    }

    /* Test mode off asks first: the switch stays on until Campaign Intelligence turned it off. */
    function askTestModeOff(block) {
      var box = dialog();
      block.querySelector('[data-smaily-test-mode-switch]').checked = true;
      pending = block;
      box.querySelector('[data-smaily-test-mode-dialog-message]').textContent = block.getAttribute('data-confirm');
      box.hidden = false;
      box.querySelector('[data-smaily-test-mode-dialog-cancel]').focus();
    }

    card.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && pending) {
        event.preventDefault();
        closeDialog();
      }
    });

    card.addEventListener('change', function (event) {
      var input = event.target;
      if (input.hasAttribute('data-smaily-test-mode-switch')) {
        var block = input.closest('[data-smaily-test-mode]');
        if (input.checked) {
          switchTestMode(block, true);
        } else {
          askTestModeOff(block);
        }
        return;
      }
      var trigger = input.closest('[data-smaily-engine-trigger]');
      if (trigger && /\.enabled$/.test(input.getAttribute('data-smaily-engine-field') || '')) {
        if (!input.checked) {
          // A row that is switched off needs no workflow: its messages go, and it closes.
          clearFieldErrors(trigger);
        }
        applyOpen(trigger);
      }
    });

    card.addEventListener('click', function (event) {
      var button = event.target.closest('[data-smaily-action="save-engine-automations"]');
      if (button) {
        save(button);
        return;
      }
      button = event.target.closest('[data-smaily-action="send-test-email"]');
      if (button) {
        var block = button.closest('[data-smaily-test-mode]');
        var box = block.querySelector('[data-smaily-test-mode-result]');
        box.hidden = true;
        // One press, one test email: a refusal is shown, never sent again on its own.
        call(block, card.getAttribute('data-api-test-send'), {
          email: block.querySelector('[data-smaily-test-send-address]').value
        }, button);
        return;
      }
      if (event.target.closest('[data-smaily-test-mode-dialog-cancel]')) {
        closeDialog();
      } else if (event.target.closest('[data-smaily-test-mode-dialog-confirm]')) {
        switchTestMode(closeDialog(), false);
      }
    });

    var tab = document.querySelector('[role=tab][data-tab="automations"]');
    var panel = card.closest('[role=tabpanel]');
    if (tab) {
      tab.addEventListener('click', function () {
        if (!asked) {
          load();
        }
      });
    }
    if (!panel || !panel.hidden) {
      load();
    }
  });
}());
