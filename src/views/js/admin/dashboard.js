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
 * The cron line of the Dashboard's Background tasks card and of the Initial setup's Overview: the
 * box asks the server for the cron line (Admin\Dashboard\CronLine) and shows it in place of the
 * button "Show the cron line" and its reason. While the background tasks do not run from a cron URL
 * (`data-smaily-cron-open`), the box asks at once, so the line shows without a click; a failed
 * request brings the button back. Plain JavaScript; window.smailyConnect.api() and ready() come
 * from common.js.
 */
(function () {
  'use strict';

  window.smailyConnect.ready(function () {
    var box = document.querySelector('.smaily-ui [data-smaily-cron-line]');
    if (!box) {
      return;
    }
    var button = box.querySelector('[data-smaily-action="cron-line"]');
    var ask = box.querySelector('[data-smaily-cron-ask]');
    var line = box.querySelector('[data-smaily-cron]');
    var result = box.querySelector('[data-smaily-result]');

    button.addEventListener('click', function () {
      button.disabled = true;
      result.textContent = '';
      result.classList.remove('is-error');
      window.smailyConnect.api(box.getAttribute('data-smaily-cron-line')).then(function (data) {
        line.textContent = data.line;
        line.hidden = false;
        ask.hidden = true;
      }).catch(function (error) {
        result.textContent = error.message;
        result.classList.add('is-error');
        button.disabled = false;
        ask.hidden = false;
      });
    });
    if (box.hasAttribute('data-smaily-cron-open')) {
      button.click();
    }
  });
}());
