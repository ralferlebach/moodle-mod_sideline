// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Jest configuration for the AMD panel (Moodle 4.5 to 5.2). Run from this directory with a
 * toolchain providing jest, jest-environment-jsdom, babel-jest and @babel/preset-env (see
 * docs/environment-setup.md). The React panel of Moodle 5.3 is tested by Moodle's own Jest setup.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/* eslint-env node */
module.exports = {
    rootDir: '../..',
    testEnvironment: 'jsdom',
    testMatch: ['<rootDir>/tests/js/**/*.test.js'],
    transform: {'^.+\\.js$': ['babel-jest', {presets: [['@babel/preset-env', {targets: {node: 'current'}}]]}]},
    moduleNameMapper: {'^core/ajax$': '<rootDir>/tests/js/mocks/ajax.js'},
};
