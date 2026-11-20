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
 * The shell of the Smaily Connect admin pages (views/templates/admin/layout/): the JSON endpoint
 * helper, "Finish upgrade", dismissing the upgrade notices and the invitation, the Settings tabs and
 * the Initial setup steps. Plain JavaScript, no build.
 *
 * window.smailyConnect.api(url, body) posts JSON to an endpoint of Admin\Api with the page's token
 * and resolves to the answer's `data`; it rejects with an Error whose message is the answer's
 * error message and whose `code` is its error code.
 *
 * window.smailyConnect.setup.onContinue(step, saver) registers the saver of a setup step: Continue
 * runs it and moves on once the promise it returns resolves; a rejected promise keeps the step.
 */
(function () {
  'use strict';

  var smailyConnect = window.smailyConnect = window.smailyConnect || {};
  var savers = {};

  smailyConnect.setup = {
    onContinue: function (step, saver) {
      savers[step] = saver;
    }
  };

  function ready(callback) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', callback);
    } else {
      callback();
    }
  }

  ready(function () {
    var root = document.querySelector('.smaily-ui');
    if (!root) {
      return;
    }

    smailyConnect.api = function (url, body) {
      return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-Smaily-Token': root.getAttribute('data-smaily-token')
        },
        body: JSON.stringify(body || {})
      }).then(function (response) {
        return response.json().catch(function () {
          return null;
        }).then(function (answer) {
          if (answer && answer.ok) {
            return answer.data;
          }
          var error = new Error(answer && answer.error ? answer.error.message : root.getAttribute('data-label-error'));
          error.code = answer && answer.error ? answer.error.code : 'invalid_response';
          error.status = response.status;
          throw error;
        });
      });
    };

    function show(result, text, ok) {
      if (result) {
        result.textContent = text;
        result.classList.toggle('is-error', !ok);
      }
    }

    root.addEventListener('click', function (event) {
      var target = event.target.closest('[data-smaily-action]');
      if (!target || !root.contains(target)) {
        return;
      }
      var action = target.getAttribute('data-smaily-action');

      if (action === 'finish-upgrade') {
        var banner = target.closest('[data-smaily-upgrade-banner]');
        var result = banner.querySelector('[data-smaily-result]');
        target.disabled = true;
        show(result, root.getAttribute('data-label-working'), true);
        smailyConnect.api(root.getAttribute('data-smaily-api-finish-upgrade')).then(function () {
          show(result, root.getAttribute('data-label-upgrade-done'), true);
          window.location.reload();
        }).catch(function (error) {
          show(result, error.message, false);
          target.disabled = false;
        });
      } else if (action === 'dismiss-notice') {
        var notice = target.closest('[data-smaily-notice]');
        smailyConnect.api(root.getAttribute('data-smaily-api-dismiss-notice'), {id: notice.getAttribute('data-smaily-notice')}).then(function () {
          notice.parentNode.removeChild(notice);
        }).catch(function (error) {
          window.alert(error.message);
        });
      } else if (action === 'dismiss-invitation') {
        var invitation = target.closest('[data-smaily-invitation]');
        smailyConnect.api(root.getAttribute('data-smaily-api-dismiss-invitation')).then(function () {
          invitation.parentNode.removeChild(invitation);
        }).catch(function (error) {
          window.alert(error.message);
        });
      }
    });

    initTabs(root.querySelector('[data-smaily-tabs]'));
    initSetup(root.querySelector('[data-smaily-setup]'), show);
  });

  /* Settings: one tab shown at a time; the address keeps ?tab=<id> so a reload opens it again. */
  function initTabs(container) {
    if (!container) {
      return;
    }
    container.addEventListener('click', function (event) {
      var tab = event.target.closest('[role=tab]');
      if (!tab) {
        return;
      }
      var id = tab.getAttribute('data-tab');
      container.querySelectorAll('[role=tab]').forEach(function (other) {
        var active = other === tab;
        other.classList.toggle('active', active);
        other.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      container.querySelectorAll('[role=tabpanel]').forEach(function (panel) {
        panel.hidden = panel.getAttribute('data-tab') !== id;
      });
      var url = new URL(window.location.href);
      url.searchParams.set('tab', id);
      window.history.replaceState(null, '', url.toString());
    });
  }

  /*
   * Initial setup: the current step, and the furthest step reached, which the rail marks done and
   * lets the merchant go back to. Continue runs the step's saver first; the last step (Overview)
   * has no Back or Continue.
   */
  function initSetup(container, show) {
    if (!container) {
      return;
    }
    var step = parseInt(container.getAttribute('data-step'), 10) || 1;
    var reached = parseInt(container.getAttribute('data-reached'), 10) || 1;
    var last = container.querySelectorAll('.smaily-step-panel').length;
    var footer = container.querySelector('.smaily-footer');
    var back = container.querySelector('[data-smaily-action=setup-back]');
    var next = container.querySelector('[data-smaily-action=setup-next]');
    var result = footer.querySelector('[data-smaily-result]');

    function render() {
      container.setAttribute('data-step', step);
      container.querySelectorAll('.smaily-steps li').forEach(function (item) {
        var number = parseInt(item.getAttribute('data-step'), 10);
        item.classList.toggle('active', number === step);
        item.classList.toggle('done', number !== step && number <= reached);
        if (number === step) {
          item.setAttribute('aria-current', 'step');
        } else {
          item.removeAttribute('aria-current');
        }
      });
      container.querySelectorAll('.smaily-step-panel').forEach(function (panel) {
        panel.hidden = parseInt(panel.getAttribute('data-step'), 10) !== step;
      });
      footer.hidden = step === last;
      back.hidden = step === 1;
      next.textContent = container.getAttribute(step === last - 1 ? 'data-label-finish' : 'data-label-continue');
      show(result, '', true);
      window.scrollTo(0, 0);
    }

    next.addEventListener('click', function () {
      var saver = savers[step];
      next.disabled = true;
      show(result, container.getAttribute('data-label-saving'), true);
      Promise.resolve(saver ? saver() : null).then(function () {
        step = Math.min(last, step + 1);
        reached = Math.max(reached, step);
        render();
      }).catch(function (error) {
        show(result, error && error.message ? error.message : container.getAttribute('data-label-failed'), false);
      }).then(function () {
        next.disabled = false;
      });
    });
    back.addEventListener('click', function () {
      step = Math.max(1, step - 1);
      render();
    });
    container.querySelector('.smaily-steps').addEventListener('click', function (event) {
      var item = event.target.closest('li.done');
      if (item) {
        step = parseInt(item.getAttribute('data-step'), 10);
        render();
      }
    });
  }
}());
