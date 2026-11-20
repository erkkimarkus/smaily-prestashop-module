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
 * The Log page (views/templates/admin/log/): Details in the side panel, "Send again" of one row and
 * "Retry" of the ticked rows or of every row the filter selects, each after a question in the
 * page's own dialog. Plain JavaScript, no build; the endpoints are those of Admin\Log, called
 * through window.smailyConnect.api (common.js).
 *
 * An answer's message shows in the flash line above the list. After a change the page reloads, and
 * the message waits in the browser's session storage until the reloaded page shows it.
 */
(function () {
  'use strict';

  var FLASH_KEY = 'smailyConnectLogFlash';

  function ready(callback) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', callback);
    } else {
      callback();
    }
  }

  ready(function () {
    var log = document.querySelector('[data-smaily-log]');
    if (!log) {
      return;
    }
    var api = window.smailyConnect.api;
    var flash = document.querySelector('[data-smaily-log-flash]');
    var drawer = document.querySelector('[data-smaily-log-drawer]');
    var drawerBody = drawer.querySelector('[data-smaily-log-drawer-body]');
    var dialog = document.querySelector('[data-smaily-log-dialog]');
    var retry = log.querySelector('[data-smaily-log-retry]');
    var all = log.querySelector('[data-smaily-log-all]');
    var pageBox = log.querySelector('[data-smaily-log-page]');
    var onConfirm = null;

    function label(name) {
      return log.getAttribute('data-label-' + name);
    }

    function showFlash(text, ok) {
      flash.querySelector('p').textContent = text;
      flash.classList.toggle('alert-success', ok);
      flash.classList.toggle('alert-danger', !ok);
      flash.hidden = false;
      // The answer shows above the list, also when the row that was clicked lies far below.
      flash.scrollIntoView({block: 'center'});
    }

    function reloadWith(text) {
      try {
        window.sessionStorage.setItem(FLASH_KEY, text);
      } catch (error) {
        // Without session storage the reloaded page shows the change without the message.
      }
      window.location.reload();
    }

    try {
      var waiting = window.sessionStorage.getItem(FLASH_KEY);
      if (waiting) {
        window.sessionStorage.removeItem(FLASH_KEY);
        showFlash(waiting, true);
      }
    } catch (error) {
      // No session storage: nothing waits.
    }

    function ask(title, message, confirmLabel, callback) {
      dialog.querySelector('.smaily-dialog__title').textContent = title;
      dialog.querySelector('.smaily-dialog__message').textContent = message;
      dialog.querySelector('[data-smaily-log-dialog-confirm]').textContent = confirmLabel;
      onConfirm = callback;
      dialog.hidden = false;
      dialog.querySelector('[data-smaily-log-dialog-confirm]').focus();
    }

    function closeDialog() {
      dialog.hidden = true;
      onConfirm = null;
    }

    dialog.querySelector('[data-smaily-log-dialog-cancel]').addEventListener('click', closeDialog);
    dialog.querySelector('[data-smaily-log-dialog-confirm]').addEventListener('click', function () {
      var callback = onConfirm;
      closeDialog();
      if (callback) {
        callback();
      }
    });

    function closeDrawer() {
      drawer.hidden = true;
      drawerBody.textContent = '';
    }

    function openDetails(id) {
      drawerBody.textContent = '';
      drawer.hidden = false;
      api(log.getAttribute('data-api-details'), {id: id}).then(function (data) {
        // The panel is the module's own template, rendered and escaped by the server.
        drawerBody.innerHTML = data.html;
      }).catch(function (error) {
        var message = document.createElement('p');
        message.className = 'smaily-result is-error';
        message.textContent = error.message;
        drawerBody.appendChild(message);
      });
    }

    function sendAgain(id) {
      ask(label('resend-title'), label('resend-message'), label('resend-title'), function () {
        api(log.getAttribute('data-api-resend'), {id: id}).then(function (data) {
          reloadWith(data.message);
        }).catch(function (error) {
          closeDrawer();
          showFlash(error.message, false);
        });
      });
    }

    function selected() {
      return Array.prototype.slice.call(log.querySelectorAll('[data-smaily-log-select]:checked')).map(function (box) {
        return box.value;
      });
    }

    function refreshSelection() {
      if (!retry) {
        return;
      }
      var whole = all.checked;
      log.querySelectorAll('[data-smaily-log-select]').forEach(function (box) {
        box.disabled = whole;
        if (whole) {
          box.checked = true;
        }
      });
      pageBox.disabled = whole;
      if (whole) {
        pageBox.checked = true;
      }
      retry.disabled = !whole && selected().length === 0;
    }

    if (retry) {
      all.addEventListener('change', function () {
        if (!all.checked) {
          pageBox.checked = false;
          log.querySelectorAll('[data-smaily-log-select]').forEach(function (box) {
            box.checked = false;
          });
        }
        refreshSelection();
      });
      pageBox.addEventListener('change', function () {
        log.querySelectorAll('[data-smaily-log-select]').forEach(function (box) {
          box.checked = pageBox.checked;
        });
        refreshSelection();
      });
      retry.addEventListener('click', function () {
        var filter = JSON.parse(log.getAttribute('data-filter'));
        var body = all.checked
          ? {all: true, filter: Array.isArray(filter) ? {} : filter}
          : {ids: selected()};
        ask(label('retry-title'), label('retry-message'), label('retry'), function () {
          retry.disabled = true;
          api(log.getAttribute('data-api-retry'), body).then(function (data) {
            reloadWith(data.message);
          }).catch(function (error) {
            showFlash(error.message, false);
            refreshSelection();
          });
        });
      });
    }

    document.addEventListener('click', function (event) {
      var target = event.target;
      if (target.closest('[data-smaily-log-close]')) {
        closeDrawer();
      } else if (target.closest('[data-smaily-log-details]')) {
        openDetails(target.closest('[data-smaily-log-details]').getAttribute('data-smaily-log-details'));
      } else if (target.closest('[data-smaily-log-resend]')) {
        sendAgain(target.closest('[data-smaily-log-resend]').getAttribute('data-smaily-log-resend'));
      } else if (target.closest('[data-smaily-log-copy]')) {
        var status = drawerBody.querySelector('[data-smaily-log-copy-status]');
        var payload = drawerBody.querySelector('[data-smaily-payload]');
        var copied = navigator.clipboard ? navigator.clipboard.writeText(payload.textContent) : Promise.reject(new Error('no clipboard'));
        copied.then(function () {
          status.textContent = label('copied');
          status.classList.remove('is-error');
        }).catch(function () {
          status.textContent = label('copy-failed');
          status.classList.add('is-error');
        });
      }
    });
    log.addEventListener('change', function (event) {
      if (event.target.matches('[data-smaily-log-select]')) {
        refreshSelection();
      }
    });
    document.addEventListener('keydown', function (event) {
      if (event.key !== 'Escape') {
        return;
      }
      if (!dialog.hidden) {
        closeDialog();
      } else if (!drawer.hidden) {
        closeDrawer();
      }
    });
  });
}());
