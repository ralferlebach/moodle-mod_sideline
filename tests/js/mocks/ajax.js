/**
 * Mock of core/ajax for the AMD panel tests: tests set global.mockCall to a jest.fn returning a promise.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/* eslint-env jest, node */
module.exports = {
    call: (requests) => requests.map((r) => global.mockCall(r)),
};
