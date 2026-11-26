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
 * The Intelligence panel (views/templates/admin/panel/intelligence.html.twig) on Settings >
 * Intelligence and on step 4 of the Initial setup, on the shell of common.js. Plain JavaScript,
 * no build.
 *
 * The panel's state (Admin\Setup\IntelligencePanel::values()) comes with the page and again with
 * every answer of Admin\Api (EngineExchange, EnginePing, EngineDisconnect, BackfillAction,
 * BackfillState); draw() shows the parts of the page that belong to it, so Connect, Check again
 * and Disconnect change the page without a reload. While an import is queued or running the
 * state is asked again every few seconds. Save sends the cookie-banner confirmation; on the
 * Initial setup Continue sends it and then opens the Overview.
 */
(function () {
  'use strict';

  var smailyConnect = window.smailyConnect = window.smailyConnect || {};
  var POLL_MS = 5000;
  var PILLS = {
    queued: ['pending', 'state-pending'],
    running: ['pending', 'state-running'],
    done: ['active', 'state-done'],
    failed: ['failed', 'state-stopped'],
    stalled: ['failed', 'state-stalled'],
    cancelled: ['off', 'state-cancelled']
  };

  smailyConnect.ready(function () {
    var panel = document.querySelector('[data-smaily-panel="intelligence"]');
    if (!panel) {
      return;
    }
    var root = panel.closest('[data-smaily-settings-root], [data-smaily-setup-root]');
    var inSetup = panel.getAttribute('data-context') === 'setup';
    var state = JSON.parse(panel.getAttribute('data-smaily-intelligence'));
    var consent = panel.querySelector('[data-ci-consent]');
    var show = smailyConnect.show;
    var timer = null;
    // What this page view did: a connection it made shows the notices of the imports it started.
    var started = false;
    var held = false;

    function word(name, params) {
      var text = panel.getAttribute('data-label-' + name) || '';
      Object.keys(params || {}).forEach(function (key) {
        text = text.split('%' + key + '%').join(String(params[key]));
      });
      return text;
    }

    function post(api, body) {
      return smailyConnect.api(root.getAttribute('data-api-' + api), body);
    }

    function results(name, text, ok) {
      panel.querySelectorAll('[data-ci-' + name + '-result]').forEach(function (line) {
        show(line, text, ok);
      });
    }

    function drawImport(card, job) {
      var start = card.querySelector('[data-smaily-action="ci-import-start"]');
      var counts = job ? {sent: job.sent, total: job.total, failed: job.failed, months: job.start_months, horizon: job.horizon_months} : {};
      var text = '';
      // The bar and the outcome of each state, as the first contact import draws them (panels.js).
      var shown = !job ? ['', ''] : ({
        queued: ['', ''],
        running: ['running', ''],
        done: job.failed > 0 ? ['failed', 'failures'] : ['done', 'done'],
        stalled: ['failed', 'stalled'],
        cancelled: ['stopped', 'cancelled']
      }[job.status] || ['failed', 'stopped']);
      // An import that waits behind a stalled one: Run again here would queue it behind that again.
      var blocked = !!job && job.status === 'stalled' && !!job.blocked_by;
      if (job) {
        if (job.status === 'queued') {
          text = word('queued');
        } else if (job.status === 'running') {
          text = job.phase ? word(job.phase === 'older' ? 'orders-older' : 'orders-start', counts) : word('importing');
        } else if (job.status === 'done') {
          text = word(job.failed > 0 ? 'done-failed' : 'done', counts);
        } else if (blocked) {
          text = word('stalled-behind', {import: word('card-' + job.blocked_by)});
        } else if (job.status === 'stalled' || job.status === 'cancelled') {
          text = word(job.status, counts);
        } else {
          // Where it stopped, in the admin's language (Admin\Setup\ImportDetails); never a text of the engine (Magento CON-233).
          text = word('stopped', counts) + (job.details ? ' ' + word('details', {details: job.details}) : '');
        }
      }
      var look = job ? (PILLS[job.status] || PILLS.done) : null;
      smailyConnect.drawImport(card, {
        pill: look ? look[0] : '',
        pillText: look ? word(look[1]) : '',
        bar: shown[0],
        count: job ? word('progress', counts) : '',
        percent: !job ? 0 : (shown[1] === 'done' ? 100 : smailyConnect.importPercent(job)),
        text: text,
        outcome: shown[1],
        finished: job ? job.finished : ''
      });
      card.querySelector('[data-ci-log]').hidden = !(job && job.status === 'done' && job.failed > 0);
      // A stalled import that is still to run offers both: Run again and Cancel import.
      start.hidden = (!!job && job.active && job.status !== 'stalled') || blocked;
      start.textContent = word(job ? 'run-again' : 'start');
      card.querySelector('[data-smaily-action="ci-import-cancel"]').hidden = !(job && job.active);
    }

    function draw() {
      var current = state.connected ? state.state : 'none';
      panel.querySelectorAll('[data-ci-show]').forEach(function (part) {
        part.hidden = part.getAttribute('data-ci-show').split(' ').indexOf(current) === -1;
      });
      // The invitation on top of the Settings tabs is for a store without a connection.
      var invitation = root.querySelector('[data-smaily-invitation="intelligence"]');
      if (invitation) {
        invitation.classList.toggle('is-connected', current !== 'none');
      }
      panel.querySelector('[data-ci-connected-text]').textContent = state.engine_version
        ? word('connected', {name: state.name, version: state.engine_version})
        : word('connected-plain', {name: state.name});
      var account = panel.querySelector('[data-ci-account-url]');
      account.hidden = !state.account_url;
      account.href = state.account_url || '#';
      panel.querySelector('[data-ci-started]').hidden = !(started && !held && current === 'connected');
      panel.querySelector('[data-ci-held]').hidden = !held;

      var active = false;
      panel.querySelectorAll('[data-ci-import]').forEach(function (card) {
        var job = state.imports ? state.imports[card.getAttribute('data-ci-import')] : null;
        drawImport(card, job);
        active = active || !!(job && job.active);
      });
      window.clearTimeout(timer);
      if (active && !inSetup) {
        timer = window.setTimeout(poll, POLL_MS);
      }
    }

    function poll() {
      post('backfill-state').then(function (answer) {
        state = answer.intelligence;
        draw();
      }).catch(function () {
        timer = window.setTimeout(poll, POLL_MS);
      });
    }

    /* Runs an action of the panel and draws the state it answers. */
    function act(button, name, api, body, working, after) {
      button.disabled = true;
      results(name, working || '', true);
      return post(api, body).then(function (answer) {
        state = answer.intelligence;
        results(name, answer.message || '', !answer.message);
        if (after) {
          after();
        }
        draw();
      }).catch(function (error) {
        results(name, error.message, false);
      }).then(function () {
        button.disabled = false;
      });
    }

    function importAction(button, action) {
      var card = button.closest('[data-ci-import]');
      var result = card.querySelector('[data-ci-import-result]');
      button.disabled = true;
      show(result, action === 'cancel' ? word('canceling') : '', true);
      post('backfill', {entity: card.getAttribute('data-ci-import'), action: action}).then(function (answer) {
        state = answer.intelligence;
        show(result, '', true);
        draw();
      }).catch(function (error) {
        show(result, error.message, false);
      }).then(function () {
        button.disabled = false;
      });
    }

    function saveConsent() {
      var body = inSetup ? {step: 'intelligence', data: {consent: consent.checked}} : {tab: 'intelligence', data: {consent: consent.checked}};
      return post('save', body).then(function () {
        state.consent = consent.checked;
      });
    }

    panel.addEventListener('click', function (event) {
      var target = event.target.closest('[data-smaily-action]');
      if (!target || !panel.contains(target)) {
        return;
      }
      var action = target.getAttribute('data-smaily-action');
      if (action === 'ci-connect') {
        var link = panel.querySelector('[data-ci-link]');
        act(target, 'connect', 'engine-exchange', {link: link.value}, word('connecting'), function () {
          link.value = '';
          started = true;
          held = false;
          consent.checked = state.consent;
        });
      } else if (action === 'ci-check') {
        act(target, 'check', 'engine-ping', {}, word('checking'));
      } else if (action === 'ci-disconnect') {
        if (window.confirm(word('confirm-disconnect'))) {
          act(target, 'check', 'engine-disconnect', {}, word('disconnecting'), function () {
            started = false;
            held = false;
          });
        }
      } else if (action === 'ci-hold') {
        act(target, 'hold', 'backfill', {entity: 'catalog', action: 'cancel'}, word('canceling'), function () {
          held = true;
        });
      } else if (action === 'ci-import-start') {
        importAction(target, 'start');
      } else if (action === 'ci-import-cancel') {
        importAction(target, 'cancel');
      } else if (action === 'ci-save') {
        target.disabled = true;
        results('save', root.getAttribute('data-label-saving'), true);
        saveConsent().then(function () {
          results('save', root.getAttribute('data-label-saved'), true);
        }).catch(function (error) {
          results('save', error.message, false);
        }).then(function () {
          target.disabled = false;
        });
      }
    });

    if (inSetup && smailyConnect.setup && smailyConnect.setup.overview) {
      // Continue: the confirmation of a connected store is saved first; the step is optional.
      smailyConnect.setup.onContinue(4, function () {
        return (state.connected ? saveConsent() : Promise.resolve()).then(smailyConnect.setup.overview);
      });
    }

    consent.checked = state.consent;
    draw();
  });
}());
