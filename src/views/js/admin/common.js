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
 * helper, "Finish upgrade", dismissing the upgrade notices, the notice of a newer release and the
 * invitation, the Settings tabs and the Initial setup steps. Plain JavaScript, no build.
 *
 * window.smailyConnect.api(url, body) posts JSON to an endpoint of Admin\Api with the page's token
 * and the shop selection it was drawn for, and resolves to the answer's `data`; it rejects with an Error whose message is the answer's
 * error message and whose `code` is its error code.
 *
 * window.smailyConnect.setup.onContinue(step, saver) registers the saver of a setup step: Continue
 * runs it and moves on once the promise it returns resolves; a rejected promise keeps the step.
 * window.smailyConnect.setup.gate(step, hint) registers what Continue waits for: hint() answers
 * the words that say why, shown beside a disabled Continue, or '' once Continue may run; the page
 * calls window.smailyConnect.setup.refresh() when that may have changed. Step 1's gate also locks
 * the rail's later steps.
 *
 * window.smailyConnect.ready(callback) runs the callback once the page is parsed, and
 * window.smailyConnect.show(element, text, ok) writes a result line, marked as an error unless ok.
 */
(function () {
  'use strict';

  var smailyConnect = window.smailyConnect = window.smailyConnect || {};
  var savers = {};
  var gates = {};

  /*
   * The logo's entrance plays once per browser session, on the page the Configure button opens:
   * once it has played, that page shows the still logo as every other page does. This runs while
   * the page loads (the script comes after the content), before the entrance gets far.
   */
  (function () {
    var picture = document.querySelector('.smaily-ui .smaily-brand picture');
    if (!picture) {
      return;
    }
    var played;
    try {
      played = window.sessionStorage.getItem('smailyConnectEntrance') === '1';
      window.sessionStorage.setItem('smailyConnectEntrance', '1');
    } catch (error) {
      return;
    }
    if (played) {
      var still = picture.querySelector('source');
      picture.querySelector('img').src = still.getAttribute('srcset');
      picture.removeChild(still);
    }
  }());

  smailyConnect.setup = {
    onContinue: function (step, saver) {
      savers[step] = saver;
    },
    gate: function (step, hint) {
      gates[step] = hint;
    },
    refresh: function () {}
  };

  function ready(callback) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', callback);
    } else {
      callback();
    }
  }

  function show(result, text, ok) {
    if (result) {
      result.textContent = text;
      result.classList.toggle('is-error', !ok);
    }
  }

  smailyConnect.ready = ready;
  smailyConnect.show = show;

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
          'X-Smaily-Token': root.getAttribute('data-smaily-token'),
          // The shop selection the page was drawn for: the endpoint refuses another one.
          'X-Smaily-Scope': root.getAttribute('data-smaily-scope')
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
          // The fields at fault, where the endpoint names them ([{field, message}]).
          error.fields = answer && answer.error ? answer.error.fields : undefined;
          throw error;
        });
      });
    };

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
      } else if (action === 'dismiss-update') {
        var update = target.closest('[data-smaily-update]');
        smailyConnect.api(root.getAttribute('data-smaily-api-dismiss-update'), {version: update.getAttribute('data-smaily-update')}).then(function () {
          update.parentNode.removeChild(update);
        }).catch(function (error) {
          window.alert(error.message);
        });
      } else if (action === 'dismiss-invitation') {
        var invitation = target.closest('[data-smaily-invitation]');
        // The attribute names the card: `intelligence` is the invitation to Campaign Intelligence.
        smailyConnect.api(root.getAttribute('data-smaily-api-dismiss-invitation'), {card: invitation.getAttribute('data-smaily-invitation')}).then(function () {
          invitation.parentNode.removeChild(invitation);
        }).catch(function (error) {
          window.alert(error.message);
        });
      }
    });

    initTabs(root.querySelector('[data-smaily-tabs]'));
    initSetup(root.querySelector('[data-smaily-setup]'));
  });

  /*
   * Settings: one tab shown at a time; the address keeps ?tab=<id> so a reload opens it again. The
   * invitation to Campaign Intelligence on top of the tabs steps aside on the Intelligence tab,
   * which carries its own copy.
   */
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
      var invitation = container.querySelector('[data-smaily-invitation="intelligence"]');
      if (invitation) {
        invitation.classList.toggle('is-aside', id === 'intelligence');
      }
      var url = new URL(window.location.href);
      url.searchParams.set('tab', id);
      window.history.replaceState(null, '', url.toString());
    });
  }

  /*
   * Initial setup: the current step, and the furthest step reached, which the rail marks done and
   * lets the merchant go back to while step 1's gate is clear. Continue runs the step's saver
   * first; the last step (Overview) has no Back or Continue.
   */
  function initSetup(container) {
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
    var hinted = false;

    /*
     * Continue waits while the step's gate gives a reason; the reason shows beside it. While step
     * 1's gate gives one, the rail opens no later step (owner, 2026-11-25), as WooCommerce and
     * Shopify lock theirs until the Smaily connection is in order.
     */
    function gate() {
      var lock = gates[1] ? gates[1]() : '';
      container.querySelectorAll('.smaily-steps li').forEach(function (item) {
        var number = parseInt(item.getAttribute('data-step'), 10);
        var locked = lock !== '' && number > 1 && number !== step;
        item.classList.toggle('locked', locked);
        if (locked) {
          item.setAttribute('aria-disabled', 'true');
          item.title = lock;
        } else {
          item.removeAttribute('aria-disabled');
          item.removeAttribute('title');
        }
      });
      var hint = step === 1 ? lock : (gates[step] ? gates[step]() : '');
      next.disabled = hint !== '';
      if (hint !== '' || hinted) {
        show(result, hint, true);
      }
      hinted = hint !== '';
    }

    smailyConnect.setup.refresh = gate;

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
      hinted = false;
      gate();
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
      }).then(gate);
    });
    back.addEventListener('click', function () {
      step = Math.max(1, step - 1);
      render();
    });
    container.querySelector('.smaily-steps').addEventListener('click', function (event) {
      var item = event.target.closest('li');
      if (item && item.classList.contains('locked')) {
        show(result, item.title, true);
        hinted = true;
      } else if (item && item.classList.contains('done')) {
        step = parseInt(item.getAttribute('data-step'), 10);
        render();
      }
    });
  }
}());
